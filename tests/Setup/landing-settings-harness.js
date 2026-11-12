'use strict';

// Runs landing.php's t202Settings block (p202ApplySettings: values and
// t202UrlVars). Usage: node landing-settings-harness.js <landing.php>
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');
const start = source.indexOf("// 1.9.56's t202Settings: a key of dcs.t202DataObj given a value");
const end = source.indexOf('// --- First-party identity', start);
assert.ok(start > 0 && end > start, 'landing.php has the t202Settings block');
const block = source.slice(start, end);

let warned = [];
function page(settings, query, options = {}) {
    warned = [];
    const params = new URLSearchParams(query);
    const get = (name) => params.getAll(name).join(', ');
    const context = {
        window: {t202Settings: settings, p202PurLink: options.purlink ? {} : undefined,
            console: {warn: (m) => warned.push(m)}},
        isFinite, String, Object,
        t202GetVar: get,
        readCookie: (name) => (name === 't202forcedkw' && options.forced ? options.forced : null),
        t202kw: get('t202kw'), c1: get('c1'), c2: get('c2'), c3: get('c3'), c4: get('c4'),
        utm_source: get('utm_source'), utm_medium: get('utm_medium'), utm_term: get('utm_term'),
        utm_content: get('utm_content'), utm_campaign: get('utm_campaign'),
        p202PurLinkEnabled: !!options.purlink, p202PurLinkPending: 'pending',
        configured: [], dcsSets: [],
    };
    vm.createContext(context);
    vm.runInContext(block + `
        function p202ConfigurePurLinks(pageview) { configured.push(pageview); }
        p202DcsSet = function (values) { dcsSets.push(JSON.parse(JSON.stringify(values))); };
        p202ApplySettings();
        p202ApplySettings();
    `, context);
    return context;
}
const plain = (v) => JSON.parse(JSON.stringify(v));
const sent = (c) => ({t202kw: c.t202kw, c1: c.c1, c2: c.c2, utm_source: c.utm_source,
    gclid: vm.runInContext("p202Var('gclid')", c), t202pubid: vm.runInContext("p202Var('t202pubid')", c)});
const QUERY = 't202kw=blue&c1=one&utm_source=news&gclid=G1&q=red+shoes&sub=aff7&src=mail&gid=G2&empty=';

// No settings, or none that say anything: the URL's values stand.
for (const settings of [undefined, null, 'x', 7, {}, {t202UseSubidOrig: true, t202ExpireSubidOrig: 30}, {t202UrlVars: 'q'},
    {t202UrlVars: {t202Kw: 'absent', t202C1: 'empty', t202C2: 5, t202C3: ''}}, {t202C1: ''}, {t202C1: null}, {t202C1: {}}]) {
    const c = page(settings, QUERY, {purlink: true});
    assert.deepStrictEqual(sent(c), {t202kw: 'blue', c1: 'one', c2: '', utm_source: 'news', gclid: 'G1', t202pubid: ''}, JSON.stringify(settings));
    assert.deepStrictEqual(plain([c.dcsSets, c.configured]), [[], []], 'nothing is touched for ' + JSON.stringify(settings));
}

// t202UrlVars: a key is read from the parameter the page names, in any case of the key.
{
    const c = page({t202UrlVars: {t202Kw: 'q', t202c1: 'sub', t202Utm_source: 'src', t202GCLID: 'gid', t202C2: 'absent'}}, QUERY, {purlink: true});
    assert.deepStrictEqual(sent(c), {t202kw: 'red shoes', c1: 'aff7', c2: '', utm_source: 'mail', gclid: 'G2', t202pubid: ''});
    assert.deepStrictEqual(plain(c.dcsSets), [{t202Kw: 'red shoes', t202c1: 'aff7', t202Utm_source: 'mail', t202GCLID: 'G2'}], 'applied once');
    assert.deepStrictEqual(plain(c.configured), ['pending'], 'PurLinks are rendered again with them');
}

// A value the page sets; the URL's parameter wins where t202UrlVars names one that is there.
{
    const c = page({t202C1: 'home', t202C2: 12, t202Pubid: 'p9', t202Country: 'Elsewhere',
        t202UrlVars: {t202C1: 'absent', t202C2: 'sub'}}, QUERY);
    assert.deepStrictEqual(sent(c), {t202kw: 'blue', c1: 'home', c2: 'aff7', utm_source: 'news', gclid: 'G1', t202pubid: 'p9'});
    assert.deepStrictEqual(plain(c.dcsSets), [{t202C1: 'home', t202C2: 'aff7', t202Pubid: 'p9', t202Country: 'Elsewhere'}],
        'a key the pageview does not send is shown only');
    assert.deepStrictEqual(plain(c.configured), [], 'no PurLinks on this page');
}

// The visitor's ids and address are never the page's to set; a forced keyword stays first.
{
    const c = page({t202Subid: '5', t202SubidOrig: '5', t202SourceId: '5', t202IP: '192.0.2.1', t202Kw: 'set',
        t202UrlVars: {t202Subid: 'q', t202SourceId: 'q', t202IP: 'q'}}, QUERY, {forced: 'forced kw'});
    assert.deepStrictEqual(plain(c.dcsSets), [{t202Kw: 'set'}]);
    assert.strictEqual(c.t202kw, 'forced kw');
}

// What the page sets and is not used is named once in the console.
{
    page({t202C1: ['x'], t202C2: {}, t202C3: true, t202Subid: '5', t202UseSubidOrig: true,
        t202UrlVars: {t202Kw: 5, t202C4: '', t202IP: 'q'}}, QUERY);
    assert.deepStrictEqual(warned.slice().sort(), [
        'Prosper202: t202Settings.t202C1 is not text or a number, and is not used.',
        'Prosper202: t202Settings.t202C2 is not text or a number, and is not used.',
        'Prosper202: t202Settings.t202C3 is not text or a number, and is not used.',
        'Prosper202: t202Settings.t202Subid cannot be set by the page, and is not used.',
        'Prosper202: t202Settings.t202UrlVars.t202C4 does not name a URL parameter, and is not used.',
        'Prosper202: t202Settings.t202UrlVars.t202IP cannot be set by the page, and is not used.',
        'Prosper202: t202Settings.t202UrlVars.t202Kw does not name a URL parameter, and is not used.',
    ]);
    page({t202C1: 'home', t202UrlVars: {t202C2: 'sub'}}, QUERY);
    assert.deepStrictEqual(warned, [], 'nothing to say about settings that are used');
}

// The real p202SettingWarn says a message once a page, however often it is asked.
{
    const c = page({t202C1: ['x']}, QUERY);
    vm.runInContext("p202SettingWarn('t202Settings.t202C1 is not text or a number, and is not used.');"
        + "p202SettingWarn('another');p202SettingWarn('another');", c);
    assert.deepStrictEqual(warned, ['Prosper202: t202Settings.t202C1 is not text or a number, and is not used.',
        'Prosper202: another']);
}

// A Symbol, a throwing getter or a throwing t202UrlVars entry skips that key only.
{
    const settings = {t202C2: Symbol('s'), t202C3: 'kept', t202UrlVars: {}};
    Object.defineProperty(settings, 't202C1', {enumerable: true, get() { throw new Error('boom'); }});
    Object.defineProperty(settings.t202UrlVars, 't202Kw', {enumerable: true, get() { throw new Error('boom'); }});
    const c = page(settings, QUERY);
    assert.strictEqual(c.c3, 'kept', 'the readable key is applied');
    assert.strictEqual(c.c1, 'one', 'the unreadable one leaves the URL\'s value');
    assert.deepStrictEqual(warned.slice().sort(), [
        'Prosper202: t202Settings.t202C1 could not be read, and is not used.',
        'Prosper202: t202Settings.t202C2 is not text or a number, and is not used.',
        'Prosper202: t202Settings.t202UrlVars.t202Kw does not name a URL parameter, and is not used.',
    ]);
}
// t202Settings itself throwing on enumeration (a Proxy) is said, and the URL's values stand.
{
    const settings = new Proxy({}, {ownKeys() { throw new Error('boom'); }});
    const c = page(settings, QUERY);
    assert.deepStrictEqual(sent(c).c1, 'one');
    assert.deepStrictEqual(warned, ['Prosper202: t202Settings could not be read, and is not used.']);
}

console.log('Landing settings harness passed');
