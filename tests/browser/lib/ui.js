'use strict';

/*
 * Page interactions, waiting on conditions rather than on the clock.
 *
 * The hand-written pass this replaces had twenty `waitForTimeout` calls and
 * no real waits. Each one was a guess that held on the machine it was written
 * on; every one is a coin flip somewhere slower, and the failure it produces
 * ("the element was not there") looks exactly like the bug it is supposed to
 * catch. Everything here waits for a stated condition and reports what the
 * condition still was when it gave up.
 *
 * The one honest use of a delay is `settle`, for asserting that something
 * does NOT happen — there is no event for "nothing arrived".
 */

class TimeoutError extends Error {}

class Ui {
  /**
   * @param {import('playwright-core').Page} page
   * @param {{timeout: number}} options
   */
  constructor(page, options = {}) {
    this.page = page;
    this.timeout = options.timeout || 15000;
  }

  // ── Waiting ───────────────────────────────────────────────────────

  /**
   * Poll a predicate until it is truthy. The predicate runs in node, so it
   * can await page calls, read the database, or combine both.
   *
   * @param {() => any|Promise<any>} predicate
   * @param {{timeout?: number, interval?: number, describe?: string}} [options]
   * @returns the predicate's truthy value
   */
  async until(predicate, options = {}) {
    const limit = options.timeout || this.timeout;
    const interval = options.interval || 50;
    const deadline = Date.now() + limit;
    let last;
    for (;;) {
      try {
        last = await predicate();
        if (last) { return last; }
      } catch (error) {
        last = '(threw: ' + error.message.split('\n')[0] + ')';
      }
      if (Date.now() >= deadline) {
        throw new TimeoutError(
          'Waited ' + limit + 'ms for ' + (options.describe || 'a condition')
          + '; last saw ' + JSON.stringify(last === undefined ? null : last)
        );
      }
      await new Promise((resolve) => setTimeout(resolve, interval));
    }
  }

  /** Poll a predicate evaluated inside the page. */
  async untilInPage(fn, arg, options = {}) {
    return this.until(() => this.page.evaluate(fn, arg), options);
  }

  /**
   * Give something a moment to happen, for the cases where the assertion is
   * that it does not. Use sparingly and say why at the call site.
   */
  async settle(ms = 400) {
    await this.page.waitForTimeout(ms);
  }

  /** Wait until the document has finished loading and the shell's JS has run. */
  async ready() {
    await this.page.waitForLoadState('load');
    await this.untilInPage(() => document.readyState === 'complete', undefined,
      { describe: 'the document to finish loading' });
  }

  // ── Navigation ────────────────────────────────────────────────────

  async goto(url) {
    await this.page.goto(url, { waitUntil: 'load' });
    await this.ready();
    return this.page.url();
  }

  /**
   * Click something that navigates, and wait for the new page rather than
   * for a guessed number of milliseconds.
   */
  async clickThrough(selector, options = {}) {
    const before = this.page.url();
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
      this.page.click(selector, options),
    ]);
    await this.ready();
    return { from: before, to: this.page.url() };
  }

  /** Click something that changes the page in place. */
  async click(selector, options = {}) {
    await this.page.click(selector, options);
  }

  // ── Reading ───────────────────────────────────────────────────────

  async exists(selector) {
    return Boolean(await this.page.$(selector));
  }

  async visible(selector) {
    const handle = await this.page.$(selector);
    return handle ? handle.isVisible() : false;
  }

  /** Trimmed text of the first match, or '' when it is not there. */
  async text(selector) {
    const handle = await this.page.$(selector);
    if (!handle) { return ''; }
    return (await handle.textContent() || '').trim();
  }

  /** Trimmed text of every match. */
  async texts(selector) {
    return this.page.$$eval(selector, (els) => els.map((e) => (e.textContent || '').trim()));
  }

  async attr(selector, name) {
    const handle = await this.page.$(selector);
    return handle ? handle.getAttribute(name) : null;
  }

  async value(selector) {
    return this.exists(selector).then((there) => there ? this.page.inputValue(selector) : null);
  }

  async count(selector) {
    return this.page.$$eval(selector, (els) => els.length).catch(() => 0);
  }

  async html() {
    return this.page.content();
  }

  async bodyText() {
    return this.page.textContent('body').catch(() => '');
  }

  // ── Forms ─────────────────────────────────────────────────────────

  /** Fill several fields by selector. */
  async fill(fields) {
    for (const [selector, value] of Object.entries(fields)) {
      await this.page.fill(selector, String(value));
    }
  }

  async select(selector, value) {
    await this.page.selectOption(selector, String(value));
  }

  async check(selector) {
    await this.page.check(selector);
  }

  /**
   * HTML5 validity of a field — whether the browser would refuse to submit
   * it. Worth asserting directly: a `required` field never reaches the
   * server, so a server-side message for it can never be what a user sees.
   */
  async validity(selector) {
    return this.page.$eval(selector, (el) => ({
      required: Boolean(el.required),
      valueMissing: Boolean(el.validity && el.validity.valueMissing),
      valid: Boolean(el.validity && el.validity.valid),
    }));
  }

  // ── Layout ────────────────────────────────────────────────────────

  /** Rounded box of an element, for the measurements only a browser knows. */
  async box(selector) {
    return this.page.$eval(selector, (el) => {
      const r = el.getBoundingClientRect();
      return { x: Math.round(r.x), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) };
    });
  }

  /** How far the document scrolls sideways; anything above 0 is a bug. */
  async horizontalOverflow() {
    return this.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  }

  async computed(selector, properties) {
    return this.page.$eval(selector, (el, props) => {
      const style = getComputedStyle(el);
      const out = {};
      for (const prop of props) { out[prop] = style.getPropertyValue(prop); }
      return out;
    }, properties);
  }

  /** Perceived lightness 0..255 of a computed colour, for theme assertions. */
  async luminance(selector, property = 'background-color') {
    return this.page.$eval(selector, (el, prop) => {
      const value = getComputedStyle(el).getPropertyValue(prop);
      const parts = value.match(/[\d.]+/g);
      if (!parts || parts.length < 3) { return null; }
      return Number(parts[0]) * 0.299 + Number(parts[1]) * 0.587 + Number(parts[2]) * 0.114;
    }, property);
  }

  async setViewport(width, height = 900) {
    await this.page.setViewportSize({ width, height });
  }

  // ── Clipboard ─────────────────────────────────────────────────────

  async clipboard() {
    return this.page.evaluate(() => navigator.clipboard.readText());
  }
}

module.exports = { Ui, TimeoutError };
