#!/usr/bin/env node
'use strict';
// Dynamic content (dcs.t202DataObj) on a page carrying the real snippet,
// served by a live instance, in jsdom (installed separately). Both the
// current landing.php snippet and 1.9.56's landing.js snippet are loaded.
// PAGE: a simple landing page with a campaign whose account tracks this
// visitor in full (privacy 'disabled'). Records clicks: disposable instances only.
const {JSDOM, ResourceLoader, VirtualConsole} = require('jsdom');
const assert = require('assert');
const args = process.argv.slice(2);
if (args.length !== 3 || args[2] !== '--ack-disposable-database' || !/^[1-9][0-9]*$/.test(args[1])) {
    throw new Error('Usage: node dcs-landing-jsdom.js BASE PAGE --ack-disposable-database');
}
const [base, page] = args;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function waitFor(condition, why) {
    for (let i = 0; i < 200; i++) { if (condition()) { return; } await sleep(40); }
    throw new Error('Timed out waiting for ' + why);
}

async function run(snippet, refused) {
    const urls = [];
    const errors = [];
    const warnings = [];
    class Recorder extends ResourceLoader {
        fetch(url, options) { urls.push(url); return super.fetch(url, options); }
    }
    const vc = new VirtualConsole();
    vc.on('jsdomError', (e) => errors.push(e.message));
    vc.on('warn', (m) => warnings.push(m));
    const body = '<form action="https://crm.example/lead" method="post">'
        + '<input name="t202Country" t202Default="your country">'
        + '<input type="hidden" name="lead_t202Subid">'
        + '<input type="hidden" name="t202C1"><input type="hidden" name="t202GCLID"><input type="hidden" name="t202Pci">'
        + '<textarea name="t202Kw"></textarea>'
        + '<select name="t202Utm_source"><option value="">-</option><option value="news">News</option></select>'
        + '</form>'
        + '<span id="attr" t202attribute="t202C1">before</span>'
        + '<span id="ip" name="t202IP" t202Default="hidden">before</span>'
        + '<span id="year" name="t202Year">before</span>'
        + '<span id="code" name="t202CountryCode" t202Default="XX">before</span>'
        + '<span id="html" name="t202C2">before</span>'
        + snippet;
    const query = '?c1=Spring+sale&c2=%3Cb%3Ebold%3C%2Fb%3E&t202kw=blue+shoes&utm_source=news&gclid=G-1&pci=88'
        + (refused ? '&p202_consent=0' : '');
    const dom = new JSDOM('<!doctype html><html><head></head><body>' + body + '</body></html>', {
        url: base + '/test-page' + query, runScripts: 'dangerously', resources: new Recorder(),
        pretendToBeVisual: true, virtualConsole: vc,
    });
    const w = dom.window;
    const d = w.document;
    const field = (name) => d.querySelector('[name="' + name + '"]');
    try {
        await waitFor(() => urls.some((u) => u.includes('/record.php?')), 'record request');
        await waitFor(() => w.dcs && (refused || w.dcs.t202DataObj.t202Subid !== ''), 'the click\'s subid');
        await sleep(100);
        const data = w.dcs.t202DataObj;
        assert.strictEqual(field('t202Country').value, data.t202Country || 'your country');
        assert.strictEqual(field('t202C1').value, 'Spring sale');
        assert.strictEqual(field('t202GCLID').value, 'G-1');
        assert.strictEqual(field('t202Pci').value, '88');
        assert.strictEqual(field('t202Kw').value, 'blue shoes');
        assert.strictEqual(field('t202Utm_source').value, 'news');
        assert.strictEqual(d.getElementById('attr').textContent, 'Spring sale');
        assert.strictEqual(d.getElementById('html').textContent, '<b>bold</b>', 'text, never HTML');
        assert.strictEqual(d.getElementById('html').children.length, 0);
        assert.strictEqual(d.getElementById('year').textContent, String(data.t202Year));
        assert.ok(/^[0-9]{4}$/.test(data.t202Year));
        assert.strictEqual(d.getElementById('code').textContent, data.t202CountryCode || 'XX');
        if (refused) {
            assert.strictEqual(d.getElementById('ip').textContent, 'hidden', 'no address without consent');
            assert.strictEqual(field('lead_t202Subid').value, '', 'no subid without consent');
        } else {
            assert.notStrictEqual(d.getElementById('ip').textContent, 'hidden', 'the address, tracked in full');
            assert.ok(/^[1-9][0-9]*$/.test(field('lead_t202Subid').value), 'the click\'s subid');
        }

        const before = urls.length;
        w.dcs.addDataObj({offerName: 'Pro plan'});
        w.dcs.updateDataObj({t202C1: 'Summer sale'});
        assert.strictEqual(field('t202C1').value, 'Summer sale');
        await sleep(300);
        assert.ok(!urls.slice(before).some((u) => u.includes('/u.php')), 'nothing is sent to redirect/u.php');
        assert.ok(warnings.some((m) => /recorded click is not updated/.test(m)), 'the page is told');
        assert.deepStrictEqual(errors, []);
    } finally { w.close(); }
}

(async () => {
    const current = '<script async src="' + base + '/tracking202/static/landing.php?lpip=' + page + '"></script>';
    const legacy = '<script src="' + base + '/tracking202/static/landing.js?lpip=' + page + '" id="t202js" async></script>';
    await run(current, false);
    await run(legacy, false);
    await run(current, true);
    console.log('Dynamic content checks passed in jsdom (current and 1.9.56 snippets)');
})().catch((e) => { console.error(e); process.exitCode = 1; });
