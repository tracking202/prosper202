import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../202-js/consent-prompt.js', import.meta.url), 'utf8');

function mountPrompt(fetch) {
    class Element {
        constructor(tag) {
            this.tag = tag;
            this.children = [];
            this.style = {};
            this.attributes = {};
            this.listeners = {};
            this.disabled = false;
        }
        appendChild(child) { child.parentNode = this; this.children.push(child); }
        removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; }
        setAttribute(key, value) { this.attributes[key] = value; }
        getAttribute(key) { return this.attributes[key] || null; }
        addEventListener(name, listener) { this.listeners[name] = listener; }
        click() { this.listeners.click(); }
    }
    const host = new Element('host');
    const mount = new Element('div');
    mount.setAttribute('data-token', 'csrf-token');
    host.appendChild(mount);
    const document = {
        getElementById: () => mount,
        createElement: (tag) => new Element(tag),
    };
    runInNewContext(script, { document, fetch, URLSearchParams });
    const card = mount.children[0];
    const buttons = card.children[2].children;
    const error = card.children[3];
    return { host, mount, buttons, error };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a saved denial closes the prompt and sends the selected choice', async () => {
    let request;
    const ui = mountPrompt((url, options) => {
        request = { url, options };
        return Promise.resolve({ ok: true, json: async () => ({ ok: true }) });
    });
    ui.buttons[1].click();
    assert.equal(ui.buttons[0].disabled, true);
    assert.equal(ui.buttons[1].disabled, true);
    await settle();
    assert.equal(request.options.body, 'flag=analytics&state=denied&source=eu_prompt&token=csrf-token');
    assert.equal(ui.host.children.length, 0);
});

for (const [label, fetch] of [
    ['HTTP error', () => Promise.resolve({ ok: false })],
    ['negative acknowledgement', () => Promise.resolve({ ok: true, json: async () => ({ ok: false }) })],
    ['network failure', () => Promise.reject(new Error('network'))],
]) {
    test(`${label} keeps the prompt visible and enables retry`, async () => {
        const ui = mountPrompt(fetch);
        ui.buttons[1].click();
        await settle();
        assert.equal(ui.host.children.length, 1);
        assert.equal(ui.error.textContent, 'Your choice could not be saved. Please try again.');
        assert.equal(ui.buttons[0].disabled, false);
        assert.equal(ui.buttons[1].disabled, false);
    });
}
