'use strict';

// Runs landing.php's PurLink fallback and original-subid block
// (t202Settings.t202UseSubidOrig). Usage: node landing-subid-orig-harness.js <landing.php>
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');
const slice = (from, to) => {
    const start = source.indexOf(from);
    const end = source.indexOf(to, start);
    assert.ok(start > 0 && end > start, 'landing.php has the block from ' + from);
    return source.slice(start, end);
};
const cookies = slice('function createCookie(name, value, days) {', '// Expose cookie/param functions globally');
const block = slice('// The redirect that stands in for a PurLink carries', 'function p202ConfigurePurLinks(pageview) {');
// landing.php's own warning and settings reader, not a copy.
const warn = slice('var p202SettingWarned = {};', 'var p202SettingsApplied = false;');

function page(settings, advanced) {
    const jar = new Map();
    const document = {};
    Object.defineProperty(document, 'cookie', {
        get() { return [...jar].map(([k, v]) => k + '=' + v.value).join('; '); },
        set(text) {
            const [pair, ...rest] = text.split('; ');
            const at = pair.indexOf('=');
            const name = pair.slice(0, at);
            const expires = rest.find((p) => p.startsWith('expires='));
            const when = expires ? Date.parse(expires.slice(8)) : null;
            if (when !== null && when < Date.now()) { jar.delete(name); return; }
            jar.set(name, {value: pair.slice(at + 1), expires: when});
        },
    });
    const warned = [];
    const context = {window: {t202Settings: settings, console: {warn: (m) => warned.push(m)}}, document, Date, Math, Number, String, isFinite,
        consent: true, encodeURIComponent};
    vm.createContext(context);
    vm.runInContext(cookies + `
        function t202Enc(e) { return encodeURIComponent(e); }
        function p202ConsentGiven() { return consent; }
        var p202PurLinkBase = 'https://tracker.test/';
        var p202PurLinkAdvanced = ${advanced ? 'true' : 'false'};
        var p202PurLinkPage = '77';
        var p202PurLinkRevoked = false;
    ` + warn + block, context);
    return {context, jar, warned, run: (code) => vm.runInContext(code, context)};
}

const first = '11-491-' + 'a'.repeat(12);
const later = '25-491-' + 'b'.repeat(12);
const full = '{keepSubid: true}';
const DAY = 86400000;

// The fallback keeps the link's own URL, and only an absolute http(s) one.
{
    const p = page(undefined, true);
    const link = 'https://shop.example/go?sid=[[subid]]&acip=491&u=https%3A%2F%2Fshop.example%2Fp%2F9';
    assert.equal(p.run(`p202PurLinkFallback('491', ${JSON.stringify(link)})`),
        'https://tracker.test/tracking202/redirect/go.php?acip=491&p202_to=' + encodeURIComponent(link));
    assert.equal(p.run(`p202PurLinkFallback('491', '/relative?sid=[[subid]]')`),
        'https://tracker.test/tracking202/redirect/go.php?acip=491');
    assert.equal(p.run(`p202PurLinkFallback('491')`), 'https://tracker.test/tracking202/redirect/go.php?acip=491');
    assert.equal(p.run(`p202PurLinkFallback('', ${JSON.stringify(link)})`), null, 'an advanced page link must name its offer');
    const simple = page(undefined, false);
    assert.equal(simple.run(`p202PurLinkFallback('', ${JSON.stringify(link)})`),
        'https://tracker.test/tracking202/redirect/go.php?lpip=77&p202_to=' + encodeURIComponent(link));
}

// Off unless the page turns it on, as in 1.9.56.
for (const settings of [undefined, {}, {t202UseSubidOrig: false}, {t202ExpireSubidOrig: 30}, 'yes']) {
    const p = page(settings, true);
    p.run(`p202SubidOrigKeep('491', ${full}, '${first}')`);
    assert.equal(p.jar.size, 0, 'nothing is kept for ' + JSON.stringify(settings));
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), null);
}

// On: the first subid is kept for the days asked, and a later one does not replace it.
{
    const p = page({t202UseSubidOrig: true, t202ExpireSubidOrig: 30}, true);
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), null);
    p.run(`p202SubidOrigKeep('491', ${full}, '${first}')`);
    const kept = p.jar.get('_t202SubidOrig_491');
    assert.equal(kept.value, first);
    assert.ok(Math.abs(kept.expires - Date.now() - 30 * DAY) < 5000, 'kept for 30 days');
    p.run(`p202SubidOrigKeep('491', ${full}, '${later}')`);
    assert.equal(p.jar.get('_t202SubidOrig_491').value, first, 'the first click stays');
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), first);
    assert.equal(p.run(`p202SubidOrigRead('492', ${full})`), null, 'another offer has its own');

    // Not for a visitor the privacy setting holds back, nor without consent.
    assert.equal(p.run(`p202SubidOrigRead('491', {})`), null);
    assert.equal(p.run(`p202SubidOrigRead('491', null)`), null);
    p.context.consent = false;
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), null);
    p.context.consent = true;
    p.run('p202PurLinkRevoked = true');
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), null);
    p.run('p202PurLinkRevoked = false');

    // A value that is not a signed subid, or names another offer, is not used.
    p.jar.set('_t202SubidOrig_491', {value: '12345', expires: null});
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), null, "1.9.56's unsigned value is not used");
    p.jar.set('_t202SubidOrig_491', {value: '11-999-' + 'a'.repeat(12), expires: null});
    assert.equal(p.run(`p202SubidOrigRead('491', ${full})`), null);
    p.run(`p202SubidOrigKeep('491', ${full}, '${later}')`);
    assert.equal(p.jar.get('_t202SubidOrig_491').value, later, 'and is replaced by this click\'s');

    // Only a signed subid of this offer is kept.
    p.jar.clear();
    for (const bad of ["undefined", "''", "'12345'", "'11-999-" + 'a'.repeat(12) + "'"]) {
        p.run(`p202SubidOrigKeep('491', ${full}, ${bad})`);
        assert.equal(p.jar.size, 0, bad + ' is not kept');
    }
    p.run(`p202SubidOrigKeep('491', ${full}, '${first}')`);

    // Refusing consent removes every kept subid.
    p.jar.set('other', {value: 'x', expires: null});
    p.run('p202SubidOrigForget()');
    assert.deepEqual([...p.jar.keys()], ['other']);
}

// Defaults and bounds: 7 days when unset or unreadable, a year at most.
for (const [settings, days] of [[{t202UseSubidOrig: true}, 7], [{t202UseSubidOrig: 'true', t202ExpireSubidOrig: 'abc'}, 7],
    [{t202UseSubidOrig: 1, t202ExpireSubidOrig: -3}, 7], [{t202UseSubidOrig: '1', t202ExpireSubidOrig: 9999}, 365]]) {
    const p = page(settings, false);
    p.run(`p202SubidOrigKeep('', ${full}, '${first}')`);
    const kept = p.jar.get('_t202SubidOrig_p77');
    assert.ok(kept && Math.abs(kept.expires - Date.now() - days * DAY) < 5000, JSON.stringify(settings) + ' keeps ' + days + ' days');
    assert.equal(p.run(`p202SubidOrigRead('', ${full})`), first, 'a simple page keeps its own');
}

// A t202ExpireSubidOrig that is not a number of days is said, once.
for (const [given, days, said] of [[undefined, 7, null], [30, 30, null], ['30', 30, null], [400, 365, 'more than 365'],
    ['abc', 7, 'not a number'], [0, 7, 'not a number'], [-3, 7, 'not a number'], [Infinity, 7, 'not a number'],
    [true, 7, 'not a number'], [null, 7, 'not a number'], ['', 7, 'not a number'], [Symbol('x'), 7, 'not a number'],
    [{}, 7, 'not a number'], [[30], 7, 'not a number']]) {
    const settings = {t202UseSubidOrig: true};
    if (given !== undefined) { settings.t202ExpireSubidOrig = given; }
    const p = page(settings, false);
    assert.equal(p.run('p202SubidOrigDays()'), days, String(given));
    p.run('p202SubidOrigDays()');
    assert.equal(p.warned.length, said ? 1 : 0, String(given) + ': ' + JSON.stringify(p.warned));
    if (said) { assert.ok(p.warned[0].includes(said), p.warned[0]); }
}

// t202UseSubidOrig: only true, 1, "1" and "true" turn it on; any other value but off is said, once.
for (const [use, on, said] of [[true, true, false], [1, true, false], ['1', true, false], ['true', true, false],
    [false, false, false], [0, false, false], ['0', false, false], ['false', false, false], [undefined, false, false],
    ['yes', false, true], ['on', false, true], ['True', false, true], [2, false, true], [null, false, true]]) {
    const settings = use === undefined ? {} : {t202UseSubidOrig: use};
    const p = page(settings, false);
    assert.equal(p.run('p202SubidOrigDays()') > 0, on, String(use));
    p.run('p202SubidOrigDays()');
    assert.equal(p.warned.length, said ? 1 : 0, String(use) + ': ' + JSON.stringify(p.warned));
    if (said) { assert.ok(p.warned[0].includes('t202UseSubidOrig is not true or false'), p.warned[0]); }
}

// A getter on t202Settings that throws is said, and never thrown out of the script.
{
    const settings = {t202UseSubidOrig: true};
    Object.defineProperty(settings, 't202ExpireSubidOrig', {enumerable: true, get() { throw new Error('boom'); }});
    const p = page(settings, false);
    assert.equal(p.run('p202SubidOrigDays()'), 7);
    assert.deepStrictEqual(p.warned, ['Prosper202: t202Settings.t202ExpireSubidOrig could not be read, and is not used.']);
}

console.log('Landing original-subid harness passed');
