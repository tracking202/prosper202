'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

class Element {
    constructor(tag, attrs = {}) {
        this.tagName = tag.toUpperCase();
        this.nodeType = 1;
        this.attrs = {...attrs};
        this.children = [];
        this.disabled = false;
    }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
    setAttribute(name, value) { this.attrs[name] = String(value); }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name); }
    removeAttribute(name) { delete this.attrs[name]; }
    closest() { return this; }
    appendChild(child) { child.parent = this; this.children.push(child); }
    remove() { this.parent.children = this.parent.children.filter((child) => child !== this); }
    querySelectorAll() { return this.children.filter((child) => child.name); }
}

const listeners = {};
const links = [
    new Element('a', {href: 'https://offer.example/?sid=[[subid]]&kw=[[t202kw]]&acip=42#part'}),
    new Element('a', {href: 'https://unrelated.example/?x=1'}),
    new Element('a', {href: 'javascript:alert([[subid]])'}),
    new Element('a', {href: 'https://simple.example/offer'}),
    new Element('a', {href: 'https://partner.example/?src=[[c1]]&utm=[[utm_source]]&r=[[random]]'}),
    new Element('a', {href: 'https://tracker.example/tracking202/redirect/go.php?acip=42&c1=[[c1]]'}),
];
const form = new Element('form', {action: 'https://offer.example/search?sid=[[subid]]&acip=42', method: 'get'});
const existing = new Element('input');
existing.name = 'q';
existing.value = 'shoes';
existing.attrs.name = 'q';
form.appendChild(existing);
const hidden = new Element('input');
hidden.name = 'ref';
hidden.value = '[[t202kw]]';
hidden.attrs.name = 'ref';
form.appendChild(hidden);
const postForm = new Element('form', {action: 'https://offer.example/send?sid=[[subid]]&acip=42', method: 'post'});
const existingSidForm = new Element('form', {action: 'https://offer.example/?sid=[[subid]]&acip=42', method: 'get'});
const existingSid = new Element('input');
existingSid.name = 'sid';
existingSid.attrs.name = 'sid';
existingSid.value = '[[subid]]';
existingSidForm.appendChild(existingSid);
const forms = [form, postForm, existingSidForm];
let queryCount = 0;
const document = {
    baseURI: 'https://landing.example/',
    documentElement: {},
    querySelectorAll(selector) { queryCount++; return selector === 'a[href]' ? links : forms; },
    addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
    createElement(tag) { return new Element(tag); },
};
let mutationCallback;
class MutationObserver {
    constructor(callback) { mutationCallback = callback; }
    observe() {}
}
const context = {window: {MutationObserver}, document, URL, WeakMap, Object, encodeURIComponent};
vm.createContext(context);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), context);
const reports = [];
const config = {
    directEnabled: true,
    tokens: {t202kw: "shoes & bags@example.com", c1: 'fb', utm_source: '', random: '7'},
    trackerBase: 'https://tracker.example/',
    simpleTemplate: 'https://simple.example/offer',
    offerSubids: {},
    fallback: (offer) => 'https://tracker.example/go.php?' + (offer ? 'acip=' + offer : 'lpip=11'),
    report: (offer) => reports.push(offer),
};
context.window.p202PurLink.init(config);
assert.strictEqual(links[0].getAttribute('href'), 'https://tracker.example/go.php?acip=42');
assert.strictEqual(links[1].getAttribute('href'), 'https://unrelated.example/?x=1');
assert.strictEqual(links[2].getAttribute('href'), 'javascript:alert([[subid]])');
assert.strictEqual(links[3].getAttribute('href'), 'https://tracker.example/go.php?lpip=11');
// A link with tokens but no [[subid]] goes direct at once, as in 1.9.56.
assert.strictEqual(links[4].getAttribute('href'), 'https://partner.example/?src=fb&utm=&r=7');
assert.strictEqual(links[5].getAttribute('href'), 'https://tracker.example/tracking202/redirect/go.php?acip=42&c1=[[c1]]',
    'links into the tracker are left to its redirect');
assert.deepStrictEqual(form.children.map((e) => [e.name, e.value]), [['q', 'shoes'], ['ref', '[[t202kw]]'], ['acip', '42']]);
config.offerSubids['42'] = '41-42-0123456789ab';
config.simpleSubid = '41-7-ba9876543210';
context.window.updatePurLink();
assert.strictEqual(links[0].getAttribute('href'),
    'https://offer.example/?sid=41-42-0123456789ab&kw=shoes%20%26%20bags@example.com&acip=42#part');
assert.strictEqual(form.getAttribute('action'), 'https://offer.example/search');
assert.strictEqual(links[3].getAttribute('href'), 'https://simple.example/offer');
assert.deepStrictEqual(form.children.map((e) => [e.name, e.value]), [
    ['q', 'shoes'], ['ref', 'shoes & bags@example.com'], ['sid', '41-42-0123456789ab'], ['acip', '42'],
]);
assert.strictEqual(postForm.getAttribute('action'), 'https://offer.example/send?sid=41-42-0123456789ab&acip=42');
assert.deepStrictEqual(existingSidForm.children.map((e) => [e.name, e.value]), [
    ['sid', '41-42-0123456789ab'], ['acip', '42'],
]);
context.window.updatePurLink();
assert.strictEqual(form.children.length, 4, 'refresh does not duplicate hidden form inputs');
context.window.dcs = {t202DataObj: {t202Kw: 'updated & value'}};
context.window.updatePurLink();
assert.ok(links[0].getAttribute('href').includes('kw=updated%20%26%20value'));
assert.strictEqual(hidden.value, 'updated & value');
context.window.p202PurLink.init(config);
assert.strictEqual(listeners.click.length, 1, 'reinitialization does not duplicate event handlers');
listeners.click[0]({type: 'click', target: links[0], defaultPrevented: false});
listeners.auxclick[0]({type: 'auxclick', target: links[0], button: 1, defaultPrevented: false});
listeners.auxclick[0]({type: 'auxclick', target: links[0], button: 2, defaultPrevented: false});
listeners.click[0]({type: 'click', target: links[1], defaultPrevented: false});
listeners.click[0]({type: 'click', target: links[5], defaultPrevented: false});
listeners.submit[0]({type: 'submit', target: form, defaultPrevented: false});
assert.deepStrictEqual(reports, ['42', '42', '42']);
config.directEnabled = false;
context.window.updatePurLink();
assert.strictEqual(existingSid.value, '[[subid]]', 'fallback must not submit the previous pageview SubID');
assert.strictEqual(hidden.value, '[[t202kw]]', 'fallback restores tokenized form fields');
assert.strictEqual(existingSidForm.children.length, 2, 'fallback does not duplicate hidden fields');
config.directEnabled = true;
context.window.updatePurLink();
assert.strictEqual(existingSid.value, '41-42-0123456789ab');
links[0].setAttribute('href', 'https://new.example/?s=[[subid]]&acip=42');
mutationCallback([{type: 'attributes', target: links[0], attributeName: 'href'}]);
assert.strictEqual(links[0].getAttribute('href'), 'https://new.example/?s=41-42-0123456789ab&acip=42');
links[0].setAttribute('href', 'https://ordinary.example/');
mutationCallback([{type: 'attributes', target: links[0], attributeName: 'href'}]);
assert.strictEqual(links[0].getAttribute('href'), 'https://ordinary.example/');
assert.strictEqual(links[0].hasAttribute('data-t202rawlink'), false);
const dynamic = new Element('a', {href: 'https://offer.example/?sid=[[subid]]&acip=42'});
links.push(dynamic);
mutationCallback([{addedNodes: [dynamic]}]);
assert.strictEqual(dynamic.getAttribute('href'), 'https://offer.example/?sid=41-42-0123456789ab&acip=42');
dynamic.setAttribute('data-t202rawlink', 'https://another.example/?id=[[subid]]&acip=42');
mutationCallback([{type: 'attributes', target: dynamic, attributeName: 'data-t202rawlink'}]);
assert.strictEqual(dynamic.getAttribute('href'), 'https://another.example/?id=41-42-0123456789ab&acip=42');
const countBeforeOwnMutation = queryCount;
mutationCallback([{type: 'attributes', target: dynamic, attributeName: 'href'}]);
assert.strictEqual(queryCount, countBeforeOwnMutation, 'our own mutation does not trigger another refresh');
assert.strictEqual(dynamic.getAttribute('href'), 'https://another.example/?id=41-42-0123456789ab&acip=42');
form.setAttribute('action', 'https://new.example/search?sid=[[subid]]&acip=42');
mutationCallback([{type: 'attributes', target: form, attributeName: 'action'}]);
assert.strictEqual(form.getAttribute('action'), 'https://new.example/search');
assert.strictEqual(form.children.length, 4, 'changed form action does not duplicate controls');
process.stdout.write('PurLink browser harness passed\n');
