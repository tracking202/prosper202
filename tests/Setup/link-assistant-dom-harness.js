// Runs the Link Assistant's page wiring (202-js/p202-link-assistant.js)
// against a stand-in document: one URL field, its suggestion box and the
// table. Types links, presses the buttons, and prints what it saw as JSON
// for LinkAssistantTest.
//
//   node link-assistant-dom-harness.js <p202-link-assistant.js> <networks.json>
'use strict';

const fs = require('fs');
const vm = require('vm');

const [script, networksFile] = process.argv.slice(2);

function element(tag) {
    const listeners = {};
    return {
        tagName: tag,
        className: '',
        textContent: '',
        hidden: false,
        value: '',
        type: '',
        children: [],
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
        appendChild(child) { this.children.push(child); return child; },
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        dispatchEvent(event) { (listeners[event.type] || []).forEach((fn) => fn(event)); return true; },
        focus() {},
        fire(type) { this.dispatchEvent({ type }); },
    };
}

// textContent = '' empties a node, as in a browser.
function box() {
    const el = element('div');
    let text = '';
    Object.defineProperty(el, 'textContent', {
        get() { return text; },
        set(v) { text = v; if (v === '') { el.children = []; } },
    });
    return el;
}

const field = element('textarea');
const assistant = box();
assistant.setAttribute('data-p202-link-assistant', '#aff_campaign_url');
assistant.hidden = true;
const table = element('script');
table.textContent = fs.readFileSync(networksFile, 'utf8');
const docListeners = {};
const document = {
    getElementById(id) { return id === 'p202-link-networks' ? table : null; },
    querySelector(sel) { return sel === '#aff_campaign_url' ? field : null; },
    querySelectorAll(sel) { return sel === '[data-p202-link-assistant]' ? [assistant] : []; },
    createElement: element,
    addEventListener(type, fn) { (docListeners[type] = docListeners[type] || []).push(fn); },
};
class Event { constructor(type) { this.type = type; } }

const sandbox = { document, Event };
sandbox.window = sandbox;
vm.runInNewContext(fs.readFileSync(script, 'utf8'), sandbox, { filename: script });
(docListeners.DOMContentLoaded || []).forEach((fn) => fn());

function walk(node, out) {
    out.push(node);
    (node.children || []).forEach((c) => walk(c, out));
    return out;
}
function buttons() {
    return walk(assistant, []).filter((n) => n.tagName === 'button');
}
function shown() {
    return { hidden: assistant.hidden, buttons: buttons().map((b) => b.textContent) };
}

const seen = {};
seen.atLoad = shown();
field.value = 'https://paykstrt.com/1/2';
field.fire('input');
seen.typed = shown();
seen.valueBeforeClick = field.value;
// A blur on pressing the button fires change; the button must survive it.
const add = buttons()[0];
field.fire('change');
seen.sameButtonAfterChange = buttons()[0] === add;
add.fire('click');
seen.valueAfterClick = field.value;
seen.afterApply = shown();
field.value = 'https://network.go2cloud.org/aff_c?offer_id=1&aff_id=2&aff_sub=mine';
field.fire('input');
seen.replace = shown();
buttons()[1].fire('click');
seen.afterDismiss = shown();
seen.valueAfterDismiss = field.value;
field.value = 'https://shop.example/';
field.fire('input');
seen.unknown = shown();
process.stdout.write(JSON.stringify(seen));
