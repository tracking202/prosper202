'use strict';

// Execute the complete page script, letting its report request or conversions
// click receive the supplied HTTP result. No private function is extracted.
const fs = require('fs');
const vm = require('vm');
const [script, argsJson] = process.argv.slice(2);
const args = JSON.parse(argsJson);
const listeners = {};
const requests = [];

function element(attributes = {}) {
    return {
        attributes,
        innerHTML: '',
        style: {},
        setAttribute(name, value) { attributes[name] = value; },
        getAttribute(name) { return attributes[name] ?? null; },
        hasAttribute(name) { return Object.hasOwn(attributes, name); },
        querySelector() { return null; },
        querySelectorAll() { return []; },
    };
}

const panel = element({ 'data-p202-report': '/report', 'aria-busy': 'true' });
if (args.subject === 'spy') {
    panel.setAttribute('data-p202-spy', '');
}
const body = element();
const modal = element();
modal.querySelector = selector => selector === '[data-p202-breakdown-body]' ? body : null;
const button = element({ 'data-p202-breakdown': '/conversions' });
button.closest = selector => selector === '[data-p202-breakdown]' ? button : null;
const document = {
    readyState: 'complete',
    addEventListener(type, callback) { (listeners[type] ??= []).push(callback); },
    querySelectorAll(selector) {
        return selector === '[data-p202-report]' && args.subject !== 'conversions' ? [panel] : [];
    },
    querySelector() { return null; },
    getElementById(id) { return id === 'p202-click-conversions' ? modal : null; },
};

function request(url) {
    requests.push(url);
    const xhr = { status: args.status, responseText: args.responseText || '' };
    return {
        done(callback) {
            if (args.status === 200) { callback('<p data-report-result>Report loaded</p>'); }
            return this;
        },
        fail(callback) {
            if (args.status !== 200) { callback(xhr, 'error'); }
            return this;
        },
        always(callback) { callback(); return this; },
    };
}

function jQuery(target) {
    return { html(value) { target.innerHTML = value; } };
}
jQuery.post = request;
jQuery.get = request;
const window = {
    jQuery,
    setInterval() {},
    bootstrap: { Modal: { getOrCreateInstance() { return { show() {} }; } } },
};
vm.runInNewContext(fs.readFileSync(script, 'utf8'), { document, window }, { filename: script });
if (args.subject === 'conversions') {
    for (const callback of listeners.click) {
        callback({ target: button, preventDefault() {} });
    }
}
const target = args.subject === 'conversions' ? body : panel;
process.stdout.write(JSON.stringify({
    html: target.innerHTML,
    busy: target.getAttribute('aria-busy'),
    opacity: panel.style.opacity,
    requests,
}));
