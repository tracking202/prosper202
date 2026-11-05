'use strict';
// Runs landing.php's Bot202 Facebook Pixel Assistant hook and
// 202-js/p202-fb-pixel-assistant.js against a small DOM and records every fbq call.
// argv: <hook source> <module path>

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const hook = fs.readFileSync(process.argv[2], 'utf8');
const moduleSource = fs.readFileSync(process.argv[3], 'utf8');

function page(options) {
    const listeners = {};
    const appended = [];
    const element = (tag, attrs = {}, parent = null) => ({
        tagName: tag.toUpperCase(), attrs, parentElement: parent,
        get href() { return attrs.href || ''; },
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
        getAttribute(name) { return this.hasAttribute(name) ? attrs[name] : null; },
    });
    const head = {appendChild(el) { appended.push(el); }};
    const firstScript = {parentNode: {insertBefore(el) { appended.push(el); }}};
    const document = {
        baseURI: 'https://lander.example/page',
        head,
        createElement(tag) { return {tagName: tag.toUpperCase()}; },
        getElementsByTagName() { return [firstScript]; },
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    };
    const calls = [];
    const window = {document, URL};
    // Stands in for fbevents.js: what the base code queued, then every call.
    const sandbox = {window, document, URL, console};
    window.window = window;
    vm.createContext(sandbox);
    let consent = options.consent !== false;
    const hookProgram = 'var p202TrackerHost = "tracker.example";\n'
        + 'function p202ConsentGiven() { return consentNow(); }\n'
        + hook.replace(/<\?php echo .*?\?>/, '"https://tracker.example/202-js/p202-fb-pixel-assistant.js?v=1"');
    sandbox.consentNow = () => consent;
    vm.runInContext(hookProgram.replace(/window\./g, 'window.'), sandbox);
    return {
        sandbox, window, appended, calls,
        setConsent(v) { consent = v; },
        loadModule() { vm.runInContext(moduleSource, sandbox); },
        fbqCalls() {
            const q = window.fbq ? window.fbq.queue : [];
            return q.map((args) => Array.prototype.slice.call(args));
        },
        click(attrs) {
            const a = element('a', attrs);
            const target = element('span', {}, a);
            (listeners.click || []).forEach((fn) => fn({target}));
        },
    };
}

const settings = {
    pixelId: '123456789012345', eventId: 'p202-9001', contentName: 'Spring sale',
    contentType: 'product', contentId: 't202id42', clickEvent: 'AddToCart', value: 3.75, currency: 'GBP',
};
const content = {content_type: 'product', content_name: 'Spring sale', content_ids: ['t202id42'], contents: [{id: 't202id42', quantity: 1}]};

// 1. The hook loads the module once; the module fires PageView and ViewContent.
{
    const p = page({});
    p.window.p202FbPixelAssistant(settings);
    assert.strictEqual(p.appended.length, 1, 'the hook loads the module');
    assert.strictEqual(p.appended[0].src, 'https://tracker.example/202-js/p202-fb-pixel-assistant.js?v=1');
    p.loadModule();
    p.appended[0].onload();
    assert.strictEqual(p.appended.length, 2, 'the module loads fbevents.js');
    assert.strictEqual(p.appended[1].src, 'https://connect.facebook.net/en_US/fbevents.js');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(p.fbqCalls())), [
        ['init', '123456789012345'],
        ['trackSingle', '123456789012345', 'PageView', {}, {eventID: 'p202-9001-pageview'}],
        ['trackSingle', '123456789012345', 'ViewContent', {...content, t202_event: 'Landing Page View'}, {eventID: 'p202-9001-view'}],
    ]);

    // A link that is not out through the tracker fires nothing.
    p.click({href: 'https://elsewhere.example/about'});
    p.click({href: 'https://tracker.example/tracking202/static/landing.php'});
    assert.strictEqual(p.fbqCalls().length, 3);

    // The outbound click carries the EPV, once per page view.
    p.click({href: 'https://tracker.example/tracking202/redirect/go.php?lpip=55'});
    p.click({href: 'https://tracker.example/tracking202/redirect/lp.php?lpip=55'});
    const calls = JSON.parse(JSON.stringify(p.fbqCalls()));
    assert.strictEqual(calls.length, 4);
    assert.deepStrictEqual(calls[3], ['trackSingle', '123456789012345', 'AddToCart',
        {...content, t202_event: 'Click To View', value: 3.75, currency: 'GBP'}, {eventID: 'p202-9001-click'}]);

    // A second answer on the same page starts nothing more.
    p.window.p202FbPixelAssistant(settings);
    assert.strictEqual(p.fbqCalls().length, 4);
}

// 2. Refused consent: nothing loads.
{
    const p = page({consent: false});
    p.window.p202FbPixelAssistant(settings);
    assert.strictEqual(p.appended.length, 0);
    assert.strictEqual(p.window.fbq, undefined);
}

// 3. Consent withdrawn after the page view: the outbound click is not sent.
{
    const p = page({});
    p.loadModule();
    p.window.p202FbPixelAssistant({...settings, value: undefined, currency: undefined, clickEvent: 'ViewContent'});
    assert.strictEqual(p.fbqCalls().length, 3);
    p.setConsent(false);
    p.click({href: 'https://tracker.example/tracking202/redirect/go.php?lpip=55'});
    assert.strictEqual(p.fbqCalls().length, 3);
    p.setConsent(true);
    p.click({href: 'https://tracker.example/tracking202/redirect/go.php?lpip=55', 'data-x': '1'});
    const last = JSON.parse(JSON.stringify(p.fbqCalls()[3]));
    assert.deepStrictEqual(last, ['trackSingle', '123456789012345', 'ViewContent', {...content, t202_event: 'Click To View'}, {eventID: 'p202-9001-click'}]);
}

// 4. A Pixel ID that is not one, or no outbound event: no click listener, nothing fired for a bad id.
{
    const p = page({});
    p.loadModule();
    p.window.p202FbPixelAssistant({...settings, pixelId: '12;alert(1)'});
    assert.strictEqual(p.window.fbq, undefined);
    const q = page({});
    q.loadModule();
    q.window.p202FbPixelAssistant({pixelId: '123456789012345'});
    q.click({href: 'https://tracker.example/tracking202/redirect/go.php?lpip=55'});
    assert.deepStrictEqual(JSON.parse(JSON.stringify(q.fbqCalls())).map((c) => c[2] || c[0]), ['init', 'PageView', 'ViewContent']);
}

// 5. The page's own fbq is used, not replaced.
{
    const p = page({});
    const own = [];
    p.window.fbq = function () { own.push(Array.prototype.slice.call(arguments)); };
    p.loadModule();
    p.window.p202FbPixelAssistant(settings);
    assert.strictEqual(p.appended.length, 0, 'fbevents.js is not loaded twice');
    assert.deepStrictEqual(own.map((c) => c[2] || c[0]), ['init', 'PageView', 'ViewContent']);
}

process.stdout.write('Facebook Pixel Assistant harness passed');
