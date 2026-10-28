// Runs Setup › Postback / Pixel's own script (202-js/p202-setup.js,
// postbackBuilder()) against a stand-in document and prints the snippets it
// writes, as JSON, for PostbackCodeTest to compare with PostbackCode.
//
//   node postback-builder-harness.js <p202-setup.js> '{"root_path": "...", "secure": true,
//        "amount": "", "cid": "", "subid": ""}'
//
// The stand-in has only what postbackBuilder() reads: the builder root, the
// form fields by id, and the code boxes it writes into. Every other section
// of the script looks for its page first and finds nothing here.
'use strict';

const fs = require('fs');
const vm = require('vm');

const [script, argsJson] = process.argv.slice(2);
const args = JSON.parse(argsJson);

function element(props) {
    return Object.assign({
        getAttribute() { return null; },
        setAttribute() {},
        addEventListener() {},
        querySelector() { return null; },
        querySelectorAll() { return []; },
        closest() { return null; },
    }, props);
}

const written = {};
const codeBoxes = ['unsecure_pixel', 'unsecure_postback', 'unsecure_pixel_2', 'unsecure_postback_2',
    'unsecure_universal_pixel', 'unsecure_universal_pixel_js'];
const byId = {
    secure_type1: element({ checked: !!args.secure }),
    amount_value: element({ value: args.amount }),
    aff_campaign_id: element({ value: args.cid }),
    subid_value: element({ value: args.subid }),
};
for (const id of codeBoxes) {
    const box = element({ parentNode: element({}) });
    // A setter, not a value: Object.assign() would copy a getter's result.
    Object.defineProperty(box, 'textContent', { set(text) { written[id] = text; } });
    byId[id] = box;
}
const root = element({
    getAttribute(name) { return name === 'data-root-path' ? args.root_path : null; },
});

const listeners = [];
const document = {
    addEventListener(type, fn) { if (type === 'DOMContentLoaded') { listeners.push(fn); } },
    querySelector(selector) { return selector === '[data-postback-builder]' ? root : null; },
    querySelectorAll() { return []; },
    getElementById(id) { return byId[id] || null; },
};
const context = { document, window: {}, console };
vm.createContext(context);
vm.runInContext(fs.readFileSync(script, 'utf8'), context, { filename: script });
for (const fn of listeners) {
    fn();
}

for (const id of codeBoxes) {
    if (!(id in written)) {
        console.error('the script never wrote ' + id);
        process.exit(2);
    }
}
process.stdout.write(JSON.stringify(written));
