'use strict';

// Runs landing.php's dynamic-content block (dcs.t202DataObj) against a small
// DOM double. Usage: node dcs-harness.js <landing.php>
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');
const start = source.indexOf("// --- Dynamic content: 1.9.56's dcs.t202DataObj ---");
const end = source.indexOf('// --- Manual ABM event instrumentation ---');
assert.ok(start > 0 && end > start, 'the dynamic-content block is in landing.php');
const block = source.slice(start, end);

class Element {
    constructor(tag, attrs = {}, extra = {}) {
        this.tagName = tag.toUpperCase();
        this.attrs = {...attrs};
        this.value = extra.value === undefined ? '' : extra.value;
        this._text = extra.text === undefined ? 'before' : extra.text;
        this.options = extra.options || [];
        this.selectedIndex = extra.selectedIndex === undefined ? -1 : extra.selectedIndex;
        this.refuseValue = !!extra.refuseValue;
        if (this.refuseValue) {
            Object.defineProperty(this, 'value', {get() { return ''; }, set() { throw new Error('InvalidStateError'); }});
        }
    }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name); }
    get textContent() { return this._text; }
    set textContent(v) { this._text = String(v); }
    set innerHTML(v) { throw new Error('innerHTML must not be written'); }
}

function page(serverData, query, elements, options = {}) {
    const listeners = {};
    const warnings = [];
    const network = [];
    const posts = [];
    const queue = [];
    const document = {
        readyState: options.readyState || 'complete',
        referrer: options.referrer === undefined ? 'https://search.example/?q=shoes' : options.referrer,
        querySelectorAll(selector) {
            assert.strictEqual(selector, '[name],[t202attribute]');
            return elements.filter((el) => el.hasAttribute('name') || el.hasAttribute('t202attribute'));
        },
        addEventListener(name, fn) { (listeners[name] = listeners[name] || []).push(fn); },
    };
    const params = new URLSearchParams(query);
    const get = (name) => params.getAll(name).join(', ');
    let consent = options.consent !== false;
    const window = {
        p202: {consent(given) { if (given === false) { consent = false; } else if (given === true) { consent = true; } return consent; }},
    };
    if (options.fetch !== false) {
        window.fetch = (url, init) => {
            network.push('fetch');
            let resolve;
            const answered = new Promise((r) => { resolve = r; });
            posts.push({url, init, resolve: () => resolve({status: 204})});
            return answered;
        };
    }
    if (options.xhr) {
        // A browser without fetch: XMLHttpRequest, answered when the test says.
        window.XMLHttpRequest = function () {
            const xhr = this;
            xhr.readyState = 0;
            xhr.headers = {};
            xhr.open = (method, url, async) => { Object.assign(xhr, {method, url, async}); xhr.readyState = 1; };
            xhr.setRequestHeader = (name, value) => { xhr.headers[name] = value; };
            xhr.send = (body) => {
                if (options.xhr === 'refuses') { throw new Error('refused'); }
                network.push('xhr');
                posts.push({url: xhr.url, xhr, body, resolve: () => {
                    xhr.readyState = 4;
                    if (xhr.onreadystatechange) { xhr.onreadystatechange(); }
                }});
            };
        };
    }
    const context = {
        window, document, URLSearchParams,
        console: {warn(m) { warnings.push(m); }},
        navigator: {sendBeacon(url, blob) { network.push('beacon'); posts.push({url, blob}); return true; }},
        Blob,
        p202BeaconFinished: !!options.beaconFinished,
        p202EventQueue: queue,
        Image: function () { network.push('image'); },
        t202GetVar: get,
        p202ConsentGiven: () => consent,
        p202PurLinkRevoked: !!options.revoked,
        t202kw: get('t202kw'), c1: get('c1'), c2: get('c2'), c3: get('c3'), c4: get('c4'),
        utm_source: get('utm_source'), utm_medium: get('utm_medium'), utm_term: get('utm_term'),
        utm_content: get('utm_content'), utm_campaign: get('utm_campaign'),
    };
    window.console = context.console;
    const map = {t202kw: 't202kw', c1: 't202c1', c2: 't202c2', c3: 't202c3', c4: 't202c4',
        utm_source: 't202utm_source', utm_medium: 't202utm_medium', utm_term: 't202utm_term',
        utm_content: 't202utm_content', utm_campaign: 't202utm_campaign'};
    const code = block
        .replace('<?php echo $p202ServerDataJs; ?>', JSON.stringify(serverData))
        .replace('<?php echo $p202ClientParamMapJs; ?>', JSON.stringify(map))
        .replace('<?php echo $p202DcsUpdateUrlJs; ?>', JSON.stringify(UPDATE_URL));
    assert.ok(!code.includes('<?php'), 'every PHP value is substituted');
    vm.runInNewContext(code, context);
    return {window, listeners, warnings, network, posts, queue, context};
}
const UPDATE_URL = 'https://track.example/tracking202/static/dcs-update.php';
const PROOF = 'update.41.12.3.1700086400.' + 'A'.repeat(43);
const settle = () => new Promise((r) => setImmediate(r));

const server = {
    t202Country: 'Example', t202CountryCode: 'EX', t202Region: '', t202City: 'Sample City', t202Postal: '',
    t202Browser: 'Firefox', t202OS: 'Linux', t202Device: 'Other', t202ISP: '', t202IP: '192.0.2.7',
    t202DayOfWeek: 'Tuesday', t202DayOfWeekShort: 'Tue', t202MonthOfYear: 'November', t202MonthOfYearShort: 'Nov',
    t202DayOfMonth: '3', t202Year: '2026', t202YearShort: '26',
};
const query = 'c1=<b>one</b>&c2=two&t202kw=blue+shoes&utm_source=news&pci=88&t202id=12&gclid=G1&fbclid=F1&msclkid=M1&gbraid=B1';

// 1. The audit's four elements, and the segments 1.9.56 had.
{
    const input = new Element('input', {name: 't202Country'});
    const attr = new Element('span', {t202attribute: 't202Country'});
    const prefixed = new Element('span', {name: 'lead_t202Country'});
    const exact = new Element('span', {name: 't202Country'});
    const code = new Element('span', {name: 't202CountryCode'});
    const region = new Element('span', {name: 't202Region', t202Default: 'your area'});
    const postal = new Element('span', {name: 't202Postal'});
    const textarea = new Element('textarea', {name: 't202City'});
    const select = new Element('select', {name: 't202CountryCode'}, {options: [{value: 'XX', text: 'Elsewhere'}, {value: 'EX', text: 'Example'}], selectedIndex: 0});
    const selectText = new Element('select', {name: 'country_t202Country'}, {options: [{value: '1', text: 'Other'}, {value: '2', text: 'Example'}], selectedIndex: 0});
    const selectNone = new Element('select', {name: 't202Browser'}, {options: [{value: 'a', text: 'Chrome'}], selectedIndex: 0});
    const oldCase = ['t202C1', 't202C2', 't202Kw', 't202Utm_source', 't202Pci', 't202Id', 't202GCLID', 't202FBCLID',
        't202MSCLKID', 't202GBRAID', 't202IP', 't202DayOfWeek', 't202DayOfWeekShort', 't202MonthOfYearShort', 't202Year', 't202YearShort',
        't202Referrer', 't202c2', 't202kw'].map((n) => new Element('input', {name: n}));
    const form = new Element('form', {name: 't202Country'}, {text: 'form children'});
    const p13n = new Element('span', {name: 't202p13n_t202Country'});
    const purlinkField = new Element('input', {name: 't202kw', 'data-p202-purlink-input': ''}, {value: '[[t202kw]]'});
    const file = new Element('input', {name: 't202City', type: 'file'}, {refuseValue: true});
    const both = new Element('span', {name: 't202City', t202attribute: 't202Country'});
    const kept = ['radio', 'checkbox', 'submit', 'button', 'reset', 'image'].map((type) =>
        new Element('input', {name: 'pick_t202C2', type}, {value: 'own'}));
    kept.forEach((el) => { el.type = el.attrs.type; });
    const button = new Element('button', {name: 'go_t202Country'}, {text: 'Order now'});
    const labelled = new Element('button', {t202attribute: 't202City'}, {text: 'Ship'});
    const els = [input, attr, prefixed, exact, code, region, postal, textarea, select, selectText, selectNone,
        ...oldCase, form, p13n, purlinkField, file, both, ...kept, button, labelled];
    const {window, network} = page(server, query, els);

    assert.deepStrictEqual([input.value, attr.textContent, prefixed.textContent, exact.textContent],
        ['Example', 'Example', 'Example', 'Example'], 'the audit\'s four elements');
    assert.strictEqual(code.textContent, 'EX', 'the longest key wins');
    assert.strictEqual(region.textContent, 'your area', 't202Default when empty');
    assert.strictEqual(postal.textContent, '', 'empty without a default');
    assert.strictEqual(textarea.value, 'Sample City');
    assert.strictEqual(select.selectedIndex, 1, 'a select picks the option by value');
    assert.strictEqual(selectText.selectedIndex, 1, 'or by its text');
    assert.strictEqual(selectNone.selectedIndex, 0, 'a select with no such option keeps its choice');
    assert.deepStrictEqual(oldCase.map((el) => el.value), ['<b>one</b>', 'two', 'blue shoes', 'news', '88', '12', 'G1',
        'F1', 'M1', 'B1', '192.0.2.7', 'Tuesday', 'Tue', 'Nov', '2026', '26', 'https://search.example/?q=shoes', 'two', 'blue shoes']);
    assert.strictEqual(form.textContent, 'form children', 'a form is never emptied');
    assert.strictEqual(p13n.textContent, 'before', 'personalization fields are left alone');
    assert.strictEqual(purlinkField.value, '[[t202kw]]', 'PurLink form fields are left alone');
    assert.strictEqual(both.textContent, 'Example', 't202attribute is written after the name');
    assert.deepStrictEqual(kept.map((el) => el.value), kept.map(() => 'own'), 'choice and button inputs keep what they post');
    assert.strictEqual(button.textContent, 'Order now', 'a button matched by name keeps its label');
    assert.strictEqual(labelled.textContent, 'Sample City', 't202attribute labels a button on purpose');

    const dcs = window.dcs;
    assert.ok(dcs && typeof dcs.addDataObj === 'function' && typeof dcs.updateDataObj === 'function'
        && typeof dcs.renderChanges === 'function', 'the public dcs API');
    assert.ok(Number(dcs.t202DataObj.t202Random) >= 1000000 && Number(dcs.t202DataObj.t202Random) <= 9999999);
    assert.strictEqual(dcs.t202DataObj.t202Subid, '');
    assert.deepStrictEqual(network, [], 'rendering sends nothing');
}

// 2. addDataObj, renderChanges and updateDataObj keep 1.9.56's semantics.
{
    const custom = new Element('span', {name: 'promoCode'});
    const country = new Element('input', {name: 't202Country'});
    const countryCode = new Element('span', {name: 't202CountryCode'});
    const c1 = new Element('input', {name: 't202C1'});
    const {window, warnings, network} = page(server, query, [custom, country, countryCode, c1]);
    const dcs = window.dcs;
    const before = dcs.t202DataObj;

    assert.strictEqual(dcs.addDataObj({promoCode: 'SAVE10', __proto__: {polluted: true}}), undefined);
    assert.notStrictEqual(dcs.t202DataObj, before, 'addDataObj replaces the object');
    assert.strictEqual(dcs.t202DataObj.promoCode, 'SAVE10');
    assert.strictEqual(dcs.t202DataObj.t202Country, 'Example', 'and keeps what it had');
    assert.strictEqual(custom.textContent, 'before', 'addDataObj renders nothing');
    dcs.renderChanges(['promoCode']);
    assert.strictEqual(custom.textContent, 'SAVE10', 'renderChanges renders the keys named');
    dcs.renderChanges('promoCode');

    dcs.t202DataObj.t202Country = 'Elsewhere';
    dcs.renderChanges(['t202Country']);
    assert.strictEqual(country.value, 'Elsewhere');
    assert.strictEqual(countryCode.textContent, 'EX', 'another key\'s element is not touched');

    dcs.updateDataObj({t202Country: 'Third', unknownKey: 'x'});
    assert.strictEqual(country.value, 'Third', 'updateDataObj renders the keys it changed');
    assert.ok(!('unknownKey' in dcs.t202DataObj), 'updateDataObj changes only keys the object has');
    assert.deepStrictEqual(warnings, [], 'a page-only key is not warned about');

    dcs.updateDataObj({t202C1: 'changed'});
    dcs.updateDataObj({t202C2: 'again'});
    assert.strictEqual(c1.value, 'changed');
    assert.deepStrictEqual(warnings, [], 'c1-c4 wait for the click');
    assert.deepStrictEqual(network, [], 'nothing is sent before record.php hands over the proof');
}

// 3. The click's subid arrives with record.php's answer; consent governs IP and subid.
{
    const subid = new Element('input', {name: 't202Subid'});
    const orig = new Element('input', {name: 't202SubidOrig'});
    const ip = new Element('span', {name: 't202IP', t202Default: 'hidden'});
    const {window} = page(server, query, [subid, orig, ip]);
    window.p202DcsClick({t202Subid: '41'});
    assert.deepStrictEqual([subid.value, orig.value, ip.textContent], ['41', '41', '192.0.2.7']);
    window.p202.consent(false);
    assert.deepStrictEqual([subid.value, ip.textContent], ['', 'hidden'], 'a withdrawal clears them');
    window.p202DcsClick({t202Subid: '42'});
    assert.strictEqual(subid.value, '', 'no subid after a withdrawal');
    window.p202.consent(true);
    assert.strictEqual(ip.textContent, '192.0.2.7', 'consent given again restores the address');
}
for (const options of [{consent: false}, {revoked: true}]) {
    const subid = new Element('input', {name: 't202Subid'});
    const ip = new Element('span', {name: 't202IP'});
    const {window} = page(server, query, [subid, ip], options);
    window.p202DcsClick({t202Subid: '41'});
    assert.deepStrictEqual([subid.value, ip.textContent], ['', ''], 'refused: ' + JSON.stringify(options));
}

// 4. A script in the head renders again once the page is parsed.
{
    const late = new Element('span', {name: 't202City'});
    const els = [];
    const {listeners} = page(server, '', els, {readyState: 'loading'});
    els.push(late);
    assert.strictEqual(listeners.DOMContentLoaded.length, 1);
    listeners.DOMContentLoaded[0]();
    assert.strictEqual(late.textContent, 'Sample City');
}

// 5. updateDataObj() changes the click this pageview recorded: c1-c4 and the
// keyword, with record.php's update proof, one request at a time.
(async () => {
    {
        const {window, warnings, network, posts} = page(server, query, []);
        const dcs = window.dcs;
        dcs.updateDataObj({t202C1: 'changed', t202Country: 'page only'});
        dcs.updateDataObj({t202C2: 'again', t202Kw: 'red shoes & socks'});
        dcs.updateDataObj({t202c2: 'lower case wins last'});
        assert.deepStrictEqual(network, [], 'held until the proof arrives');
        window.p202DcsClick({t202Subid: '41', updateProof: PROOF});
        assert.strictEqual(dcs.t202DataObj.t202Subid, '41');
        assert.strictEqual(posts.length, 1, 'one request for every change so far');
        assert.strictEqual(posts[0].url, UPDATE_URL);
        assert.deepStrictEqual(
            {method: posts[0].init.method, keepalive: posts[0].init.keepalive, credentials: posts[0].init.credentials,
                type: posts[0].init.headers['Content-Type']},
            {method: 'POST', keepalive: true, credentials: 'omit', type: 'application/x-www-form-urlencoded'});
        assert.deepStrictEqual(Object.fromEntries(new URLSearchParams(posts[0].init.body)),
            {proof: PROOF, t202c1: 'changed', t202c2: 'lower case wins last', t202kw: 'red shoes & socks'});
        assert.ok(!posts[0].init.body.includes('t202subid') && !posts[0].init.body.includes('t202country'),
            'the click is the proof\'s, never a subid the page names');

        dcs.updateDataObj({t202C3: 'first'});
        dcs.updateDataObj({t202C3: 'second', t202C4: 'z'});
        assert.strictEqual(posts.length, 1, 'a request in flight holds the next');
        posts[0].resolve();
        await settle();
        assert.strictEqual(posts.length, 2);
        assert.deepStrictEqual(Object.fromEntries(new URLSearchParams(posts[1].init.body)),
            {proof: PROOF, t202c3: 'second', t202c4: 'z'}, 'the latest values, after the earlier ones landed');
        posts[1].resolve();
        await settle();

        dcs.updateDataObj({t202C1: '', t202C2: null, t202C3: {toString() { return 'x'; }}});
        assert.strictEqual(posts.length, 2, 'an empty value leaves the click\'s, as 1.9.56 did');
        dcs.updateDataObj({t202C1: 'a'.repeat(400), t202Kw: 'k'.repeat(149) + '\u{1F600}x', t202C2: 7});
        const cut = Object.fromEntries(new URLSearchParams(posts[2].init.body));
        assert.deepStrictEqual([cut.t202c1.length, cut.t202kw, cut.t202c2],
            [350, 'k'.repeat(149) + '\u{1F600}', '7'], 'cut to what the click stores, never half a character');
        posts[2].resolve();
        await settle();

        dcs.updateDataObj({t202Id: '9', t202Utm_source: 'mail', t202Referrer: 'https://ref.example/'});
        dcs.updateDataObj({t202Id: '10'});
        assert.strictEqual(posts.length, 3, 'the traffic source, UTMs and referrer are page only');
        assert.strictEqual(warnings.length, 3, 'each said once');
        assert.ok(warnings.every((w) => /on the page only; the recorded click keeps its value/.test(w)), warnings.join('\n'));
        assert.ok(/t202Id/.test(warnings[0]) && /t202Utm_source/.test(warnings[1]) && /t202Referrer/.test(warnings[2]));

        window.p202.consent(false);
        dcs.updateDataObj({t202C1: 'after withdrawal'});
        window.p202.consent(true);
        dcs.updateDataObj({t202C1: 'consent again'});
        assert.strictEqual(posts.length, 3, 'a withdrawal ends the proof');
        assert.strictEqual(warnings.length, 4);
        assert.match(warnings[3], /no recorded click to update/);
    }
    {
        // record.php answered with a subid and no proof (no key): said once it has answered.
        const {window, warnings, posts, queue} = page(server, query, []);
        window.dcs.updateDataObj({t202C1: 'v'});
        window.p202DcsClick({t202Subid: '41'});
        assert.deepStrictEqual([posts.length, warnings.length, queue.length], [0, 0, 1]);
        window.dcs.updateDataObj({t202C2: 'w'});
        assert.strictEqual(queue.length, 1, 'one wait');
        queue.forEach((fn) => fn());
        assert.strictEqual(posts.length, 0);
        assert.strictEqual(warnings.length, 1);
        assert.match(warnings[0], /no recorded click to update/);
    }
    for (const [why, options] of [['p202_beacon=0', {beaconFinished: true}], ['refused', {consent: false}], ['revoked', {revoked: true}]]) {
        const {window, warnings, posts} = page(server, query, [], options);
        // An events-only page records no click; a refusal ignores record.php's answer.
        if (!options.beaconFinished) { window.p202DcsClick({t202Subid: '41', updateProof: PROOF}); }
        window.dcs.updateDataObj({t202C1: 'v'});
        assert.strictEqual(posts.length, 0, why);
        assert.strictEqual(warnings.length, 1, why);
        assert.match(warnings[0], /no recorded click to update/, why);
    }
    {
        // Without fetch, XMLHttpRequest, still one request at a time.
        const {window, posts, network} = page(server, query, [], {fetch: false, xhr: true});
        window.p202DcsClick({t202Subid: '41', updateProof: PROOF});
        window.dcs.updateDataObj({t202C4: 'older'});
        window.dcs.updateDataObj({t202C4: 'newer'});
        assert.deepStrictEqual(network, ['xhr'], 'the newer value waits for the older one to land');
        const {xhr} = posts[0];
        assert.deepStrictEqual([xhr.method, xhr.url, xhr.async, xhr.headers['Content-Type'], xhr.withCredentials],
            ['POST', UPDATE_URL, true, 'application/x-www-form-urlencoded', undefined]);
        assert.strictEqual(posts[0].body, 'proof=' + encodeURIComponent(PROOF) + '&t202c4=older');
        posts[0].resolve();
        assert.deepStrictEqual(network, ['xhr', 'xhr']);
        assert.strictEqual(posts[1].body, 'proof=' + encodeURIComponent(PROOF) + '&t202c4=newer');
        posts[1].resolve();
        posts[0].resolve();
        assert.strictEqual(posts.length, 2, 'an answer counts once');
        window.p202.consent(false);
        window.dcs.updateDataObj({t202C4: 'withdrawn'});
        assert.strictEqual(posts.length, 2, 'a withdrawal still ends the proof');
    }
    {
        // A request the browser refuses keeps its values for the next change.
        const {window, posts, warnings} = page(server, query, [], {fetch: false, xhr: 'refuses'});
        window.p202DcsClick({t202Subid: '41', updateProof: PROOF});
        window.dcs.updateDataObj({t202C1: 'kept', t202C2: 'old'});
        assert.strictEqual(posts.length, 0);
        assert.strictEqual(warnings.length, 1);
        assert.match(warnings[0], /could not be sent/);
        const sent = page(server, query, [], {fetch: false, xhr: true});
        // The same page once the browser takes requests again.
        window.XMLHttpRequest = sent.window.XMLHttpRequest;
        window.dcs.updateDataObj({t202C2: 'new'});
        assert.strictEqual(sent.posts.length, 1);
        assert.deepStrictEqual(Object.fromEntries(new URLSearchParams(sent.posts[0].body)),
            {proof: PROOF, t202c1: 'kept', t202c2: 'new'}, 'the refused values go with the next, newer ones winning');
    }
    {
        // Neither fetch nor XMLHttpRequest: nothing sent, said once.
        const {window, posts, warnings} = page(server, query, [], {fetch: false});
        window.p202DcsClick({t202Subid: '41', updateProof: PROOF});
        window.dcs.updateDataObj({t202C1: 'v'});
        window.dcs.updateDataObj({t202C1: 'w'});
        assert.deepStrictEqual([posts.length, warnings.length], [0, 1]);
    }
    {
        // A replaced dcs sends nothing.
        const {window, posts} = page(server, query, []);
        const old = window.dcs;
        window.p202DcsClick({t202Subid: '41', updateProof: PROOF});
        window.dcs = {};
        old.updateDataObj({t202C1: 'stale'});
        assert.strictEqual(posts.length, 0);
    }
    console.log('Dynamic content harness passed');
})().catch((e) => { console.error(e); process.exitCode = 1; });
