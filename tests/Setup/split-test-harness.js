'use strict';

// Runs landing.php's t202Settings, first-party identity and split test
// blocks: the variant a visitor is given, kept and shown, and the C4 the
// pageview is sent with. Usage: node split-test-harness.js <landing.php>
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');
const start = source.indexOf("// 1.9.56's t202Settings: a key of dcs.t202DataObj given a value");
const end = source.indexOf('// --- End of the split test ---', start);
assert.ok(start > 0 && end > start, 'landing.php has the settings, identity and split test blocks in that order');
assert.ok(source.indexOf('// --- Split test: one variant per visitor ---', start) > start, 'landing.php has the split test block');
const block = source.slice(start, end);

const LPIP = '4215';
const KEY = 'p202variant_' + LPIP;

function storage(initial = {}) {
    const items = new Map(Object.entries(initial));
    return {
        items,
        getItem: (k) => (items.has(k) ? items.get(k) : null),
        setItem: (k, v) => { items.set(k, String(v)); },
        removeItem: (k) => { items.delete(k); },
    };
}

// split: what PHP prints for $p202Split; random: the draws crypto answers, as fractions of 2^32.
function page(split, options = {}) {
    const params = new URLSearchParams(options.query || '');
    const get = (name) => params.getAll(name).join(', ');
    const store = options.store || storage();
    const draws = (options.random || [0]).slice();
    const events = [];
    const attrs = {};
    const dcsSets = [];
    const window = {
        localStorage: store,
        t202Settings: options.settings,
        console: {warn: () => {}},
        crypto: options.noCrypto ? undefined : {getRandomValues(a) {
            assert.ok(draws.length > 0, 'a draw was expected');
            a[0] = Math.floor(draws.shift() * 4294967296);
            return a;
        }},
    };
    if (options.p202) { window.p202 = options.p202; }
    const context = {
        window, URLSearchParams, Uint8Array, Uint32Array, isFinite, String, Object,
        Math: options.math || Math,
        CustomEvent: function (type, init) { this.type = type; this.detail = init.detail; },
        document: {
            cookie: '',
            documentElement: {setAttribute(name, value) { attrs[name] = value; }},
            dispatchEvent(ev) { events.push({type: ev.type, detail: ev.detail}); return true; },
        },
        _params: params,
        t202GetVar: get,
        readCookie: () => null,
        t202kw: get('t202kw'), c1: get('c1'), c2: get('c2'), c3: get('c3'), c4: get('c4'),
        utm_source: '', utm_medium: '', utm_term: '', utm_content: '', utm_campaign: '',
        p202PurLinkEnabled: false,
        p202SubidOrigForget: () => {},
        p202ConfigurePurLinks: () => {},
        dcsSets,
    };
    const values = {
        '$p202TrackerHostJs': JSON.stringify('track.example'),
        '$p202CampaignCaptureJs': 'true',
        '$p202SplitJs': JSON.stringify(split),
        '$lpip_js': JSON.stringify(LPIP),
    };
    const code = block.replace(/<\?php echo (\$[A-Za-z0-9_]+); \?>/g, (all, name) => {
        assert.ok(name in values, 'a value for ' + name);
        return values[name];
    });
    assert.ok(!code.includes('<?php'), 'every PHP value is substituted');
    vm.createContext(context);
    vm.runInContext(code + `
        p202DcsSet = function (values) { dcsSets.push(JSON.parse(JSON.stringify(values))); };
    `, context);
    const run = (js) => vm.runInContext(js, context);
    const plain = (v) => JSON.parse(JSON.stringify(v));
    return {window, store, events, attrs, get dcsSets() { return plain(dcsSets); }, run, c4: () => run('c4')};
}
const AB = {variants: [{name: 'headline-a', weight: 1}, {name: 'headline-b', weight: 3}], overwriteC4: false};
const over = (test) => ({variants: test.variants, overwriteC4: true});

// No test: nothing is shown or sent, and a variant kept from an earlier test is forgotten.
{
    const p = page(null, {query: 'c4=src', store: storage({[KEY]: 'headline-a'})});
    assert.strictEqual(p.window.t202Variant, '');
    assert.strictEqual(p.window.p202.variant(), '');
    assert.strictEqual(p.c4(), 'src');
    assert.deepStrictEqual(JSON.parse(JSON.stringify([p.attrs, p.events])), [{}, []]);
    assert.strictEqual(p.store.getItem(KEY), null, 'the kept variant is forgotten');
}

// A test that could not be read: no variant, and the visitor's own is kept for the next view.
{
    const p = page(false, {store: storage({[KEY]: 'headline-a'})});
    assert.strictEqual(p.window.t202Variant, '');
    assert.strictEqual(p.c4(), '', 'nothing is sent as C4');
    assert.strictEqual(p.store.getItem(KEY), 'headline-a');
}

// Picked by weight: a draw below 1/4 is headline-a, the rest headline-b; kept for the visitor.
{
    const a = page(AB, {random: [0.2]});
    assert.strictEqual(a.window.t202Variant, 'headline-a');
    assert.strictEqual(a.store.getItem(KEY), 'headline-a');
    const b = page(AB, {random: [0.3]});
    assert.strictEqual(b.window.t202Variant, 'headline-b');
    assert.strictEqual(page(AB, {random: [0.999999]}).window.t202Variant, 'headline-b');
    assert.strictEqual(page(AB, {random: [0]}).window.t202Variant, 'headline-a');
}

// Sticky: the next view keeps the visitor's variant, with no draw.
{
    const store = storage();
    page(AB, {store, random: [0.9]});
    const again = page(AB, {store, random: []});
    assert.strictEqual(again.window.t202Variant, 'headline-b');
    assert.strictEqual(store.getItem(KEY), 'headline-b');
}

// A kept variant the test no longer shows (renamed, removed, or weight 0, which PHP leaves out) is replaced.
{
    const store = storage({[KEY]: 'headline-c'});
    const p = page(AB, {store, random: [0.1]});
    assert.strictEqual(p.window.t202Variant, 'headline-a');
    assert.strictEqual(store.getItem(KEY), 'headline-a');
    // The name is compared exactly: what the page was told is what C4 holds.
    const other = page(AB, {store: storage({[KEY]: 'HEADLINE-A'}), random: [0.5]});
    assert.strictEqual(other.window.t202Variant, 'headline-b');
}

// The click's C4: the variant when the visit has none; the visit's own unless the test replaces it.
{
    assert.strictEqual(page(AB, {random: [0.5]}).c4(), 'headline-b');
    assert.strictEqual(page(AB, {random: [0.5], query: 'c4=from-source'}).c4(), 'from-source');
    assert.strictEqual(page(over(AB), {random: [0.5], query: 'c4=from-source'}).c4(), 'headline-b');
}

// The page's own t202Settings: a C4 it sets counts as the visit's; the shown C4 is the one sent.
{
    const kept = page(AB, {random: [0.5], settings: {t202C4: 'home'}});
    kept.run('p202ApplySettings()');
    assert.strictEqual(kept.c4(), 'home');
    assert.deepStrictEqual(kept.dcsSets, [{t202C4: 'home'}]);

    const replaced = page(over(AB), {random: [0.5], settings: {t202C4: 'home'}});
    replaced.run('p202ApplySettings()');
    assert.strictEqual(replaced.c4(), 'headline-b');
    assert.deepStrictEqual(replaced.dcsSets, [{t202C4: 'headline-b'}]);

    // t202UrlVars naming the source's parameter is the source's C4.
    const named = page(AB, {random: [0.5], query: 'sub4=aff', settings: {t202UrlVars: {t202C4: 'sub4'}}});
    named.run('p202ApplySettings()');
    assert.strictEqual(named.c4(), 'aff');

    // Settings that leave C4 alone leave the variant in it.
    const other = page(AB, {random: [0.5], settings: {t202C1: 'x'}});
    other.run('p202ApplySettings()');
    assert.deepStrictEqual([other.c4(), other.run('c1')], ['headline-b', 'x']);
}

// Shown to the page: a global, p202.variant(), the root's attribute and one event.
{
    const p = page(AB, {random: [0.5], p202: {q: []}});
    assert.strictEqual(p.window.t202Variant, 'headline-b');
    assert.strictEqual(p.window.p202.variant(), 'headline-b');
    assert.strictEqual(p.window.p202.q.length, 0, 'the page\'s own p202 is kept');
    assert.deepStrictEqual(p.attrs, {'data-p202-variant': 'headline-b'});
    assert.deepStrictEqual(JSON.parse(JSON.stringify(p.events)), [{type: 'p202:variant', detail: {variant: 'headline-b'}}]);
}

// Consent: without it the visitor still sees a variant, which is never kept;
// a refusal later forgets the one kept.
{
    const refused = storage({p202_consent: '0', [KEY]: 'headline-a'});
    const p = page(AB, {store: refused, random: [0.5]});
    assert.strictEqual(p.window.t202Variant, 'headline-b');
    assert.strictEqual(refused.getItem(KEY), null);

    const byUrl = page(AB, {random: [0.5], query: 'p202_consent=0'});
    assert.strictEqual(byUrl.store.getItem(KEY), null, 'a refusal on the URL keeps nothing');

    const given = page(AB, {random: [0.5]});
    assert.strictEqual(given.store.getItem(KEY), 'headline-b');
    given.run('window.p202.consent(false)');
    assert.strictEqual(given.store.getItem(KEY), null);
}

// Without crypto, Math.random draws.
{
    let asked = 0;
    const math = Object.create(Math);
    math.random = () => { asked++; return 0.1; };
    const p = page(AB, {noCrypto: true, math});
    assert.deepStrictEqual([p.window.t202Variant, asked], ['headline-a', 1]);
}

// Over many visitors the weights hold.
{
    const counts = {'headline-a': 0, 'headline-b': 0, 'headline-c': 0};
    const three = {variants: [{name: 'headline-a', weight: 1}, {name: 'headline-b', weight: 3}, {name: 'headline-c', weight: 6}],
        overwriteC4: false};
    const draws = [];
    for (let i = 0; i < 2000; i++) { draws.push((i + 0.5) / 2000); }
    for (const d of draws) { counts[page(three, {random: [d]}).window.t202Variant]++; }
    assert.deepStrictEqual(counts, {'headline-a': 200, 'headline-b': 600, 'headline-c': 1200});
}

console.log('Split test harness passed');
