'use strict';
// Runs landing.php's ChatGPT Ads pixel hook against a small DOM: what it
// queues for OpenAI's pixel, and what it posts to openai-ref.php.
// argv: <hook source>

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const hook = fs.readFileSync(process.argv[2], 'utf8');

function page(options) {
    const appended = [];
    const posts = [];
    const timers = [];
    const firstScript = {parentNode: {insertBefore(el) { appended.push(el); }}};
    const document = {
        cookie: options.cookie || '',
        head: {appendChild(el) { appended.push(el); }},
        documentElement: {appendChild(el) { appended.push(el); }},
        createElement(tag) { return {tagName: tag.toUpperCase()}; },
        getElementsByTagName() { return [firstScript]; },
    };
    const window = {
        document,
        fetch(url, init) { posts.push({url, body: init.body, init}); return {then() {}}; },
        setInterval(fn) { timers.push(fn); return timers.length; },
        clearInterval(id) { timers[id - 1] = null; },
    };
    window.window = window;
    let consent = options.consent !== false;
    const sandbox = {window, document, console};
    vm.createContext(sandbox);
    sandbox.consentNow = () => consent;
    sandbox.setConsentNow = (v) => { consent = v; };
    const program = 'function p202ConsentGiven() { return consentNow(); }\n'
        + 'window.p202 = {consent: function(given) { if (given === false) { setConsentNow(false); } else if (given === true) { setConsentNow(true); } return consentNow(); }};\n'
        + hook.replace(/<\?php echo .*?\?>/, '"https://tracker.example/tracking202/static/openai-ref.php"');
    assert.ok(!/<\?php/.test(program), 'one PHP echo in the hook');
    vm.runInContext(program, sandbox);
    return {
        window, document, appended, posts,
        tick() { timers.slice().forEach((fn) => { if (fn) { fn(); } }); },
        running() { return timers.filter(Boolean).length; },
        // Copied out of the page's realm, so deepStrictEqual compares values.
        queue() { return window.oaiq ? JSON.parse(JSON.stringify(window.oaiq.q.map((args) => Array.prototype.slice.call(args)))) : []; },
    };
}

const settings = {pixelId: 'px_123', refProof: 'browserref.9.4.1.99.mac'};

// 1. The base code with consent first, then the reference once the cookie is set.
{
    const p = page({});
    p.window.p202OpenAiPixel(settings);
    assert.deepStrictEqual(p.queue(), [['consent', false], ['init', {pixelId: 'px_123'}], ['consent', true]]);
    assert.strictEqual(p.appended.length, 1);
    assert.strictEqual(p.appended[0].src, 'https://bzrcdn.openai.com/sdk/oaiq.min.js');
    assert.strictEqual(p.appended[0].async, true);
    p.tick();
    assert.strictEqual(p.posts.length, 0, 'nothing posted before the cookie is there');
    p.document.cookie = 'a=1; __obref=ob.ABC-123_x; b=2';
    p.tick();
    assert.strictEqual(p.posts.length, 1);
    assert.strictEqual(p.posts[0].url, 'https://tracker.example/tracking202/static/openai-ref.php');
    assert.strictEqual(p.posts[0].body, 'proof=browserref.9.4.1.99.mac&obref=ob.ABC-123_x');
    assert.strictEqual(p.posts[0].init.credentials, 'omit');
    assert.strictEqual(p.running(), 0, 'the watch stops once sent');
    // A second settings call changes nothing.
    p.window.p202OpenAiPixel({pixelId: 'other'});
    assert.strictEqual(p.queue().length, 3);
}

// 2. Withdrawing consent withdraws the pixel's and asks the tracker to drop the reference.
{
    const p = page({cookie: '__obref=ob1'});
    p.window.p202OpenAiPixel(settings);
    p.tick();
    assert.strictEqual(p.posts.length, 1);
    assert.strictEqual(p.window.p202.consent(false), false);
    assert.deepStrictEqual(p.queue().slice(-1), [['consent', false]]);
    assert.strictEqual(p.posts.length, 2);
    assert.strictEqual(p.posts[1].body, 'proof=browserref.9.4.1.99.mac&p202_consent=0');
    // Given again: the pixel's consent follows, and the reference is sent again.
    assert.strictEqual(p.window.p202.consent(true), true);
    assert.deepStrictEqual(p.queue().slice(-1), [['consent', true]]);
    p.tick();
    assert.strictEqual(p.posts.length, 3);
    assert.strictEqual(p.posts[2].body, 'proof=browserref.9.4.1.99.mac&obref=ob1');
}

// 3. Refused before the call: nothing loads until consent is given.
{
    const p = page({consent: false, cookie: '__obref=ob2'});
    p.window.p202OpenAiPixel(settings);
    assert.strictEqual(p.window.oaiq, undefined);
    assert.strictEqual(p.appended.length, 0);
    p.tick();
    assert.strictEqual(p.posts.length, 0);
    p.window.p202.consent(true);
    assert.deepStrictEqual(p.queue(), [['consent', false], ['init', {pixelId: 'px_123'}], ['consent', true]]);
    p.tick();
    assert.strictEqual(p.posts[0].body, 'proof=browserref.9.4.1.99.mac&obref=ob2');
}

// 4. A cookie that is not a reference the tracker keeps is not sent, and the watch gives up.
{
    const p = page({cookie: '__obref=' + 'x'.repeat(256)});
    p.window.p202OpenAiPixel(settings);
    for (let i = 0; i < 25; i++) { p.tick(); }
    assert.strictEqual(p.posts.length, 0);
    assert.strictEqual(p.running(), 0);
}

// 5. Without a proof the pixel loads and nothing is posted, even on withdrawal.
{
    const p = page({cookie: '__obref=ob3'});
    p.window.p202OpenAiPixel({pixelId: 'px_9'});
    p.tick();
    p.window.p202.consent(false);
    assert.strictEqual(p.posts.length, 0);
    assert.deepStrictEqual(p.queue()[1], ['init', {pixelId: 'px_9'}]);
}

// 6. Settings without a pixel id load nothing.
{
    const p = page({});
    p.window.p202OpenAiPixel({refProof: 'x'});
    p.window.p202OpenAiPixel(null);
    assert.strictEqual(p.window.oaiq, undefined);
}

console.log('ChatGPT Ads pixel harness passed');
