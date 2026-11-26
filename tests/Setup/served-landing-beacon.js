'use strict';

// Runs a landing.php script as served, as a page whose query is <search>
// loads it, with crypto's one draw <draw> (a fraction of 2^32), and prints
// the variant it gave the visitor and the record.php URL its pageview asks
// for. Usage: node served-landing-beacon.js <script file> <search> <draw>
const fs = require('fs');
const vm = require('vm');

const [file, search, draw] = process.argv.slice(2);
const script = fs.readFileSync(file, 'utf8');
const requested = [];
const later = [];
const items = new Map();
const element = (tag) => ({tagName: tag.toUpperCase(), setAttribute() {}, getAttribute() { return null; },
    hasAttribute() { return false; }, appendChild() {}});
const head = {appendChild(el) { if (el.src) { requested.push(el.src); } }};
const document = {
    readyState: 'complete', cookie: '', referrer: '', head,
    documentElement: {setAttribute() {}},
    createElement: element,
    getElementById: () => null,
    getElementsByTagName: () => [{parentNode: head}],
    querySelectorAll: () => [],
    addEventListener() {},
    removeEventListener() {},
    dispatchEvent() { return true; },
};
const window = {
    location: {search, href: 'https://lp.example/' + search, hostname: 'lp.example'},
    localStorage: {getItem: (k) => (items.has(k) ? items.get(k) : null), setItem: (k, v) => items.set(k, String(v)),
        removeItem: (k) => items.delete(k)},
    crypto: {getRandomValues(a) {
        if (a instanceof Uint32Array) { a[0] = Math.floor(Number(draw) * 4294967296); } else { a.fill(7); }
        return a;
    }},
    console,
    addEventListener() {},
    removeEventListener() {},
};
window.window = window;
window.document = document;
const context = vm.createContext(Object.assign(window, {
    URLSearchParams, URL, Uint8Array, Uint32Array, Promise, JSON, Math, Date, Object, Array, String, Number, isFinite,
    navigator: {language: 'en-US', userAgent: 'node'},
    screen: {width: 1280, height: 800},
    CustomEvent: function (type, init) { this.type = type; this.detail = init && init.detail; },
    setTimeout: (fn, ms) => { if (!ms) { later.push(fn); } return 0; },
    clearTimeout() {},
}));
vm.runInContext(script, context, {filename: 'landing.php'});
while (later.length) { later.shift()(); }
const record = requested.filter((u) => u.indexOf('tracking202/static/record.php?') !== -1);
if (record.length !== 1) {
    console.error('expected one record.php request, got: ' + JSON.stringify(requested));
    process.exit(1);
}
console.log(JSON.stringify({variant: window.t202Variant, url: record[0]}));
