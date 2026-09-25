'use strict';

/*
 * Prosper202 itself: signing in, moving around the shell, and reading what
 * the page says back.
 *
 * Everything a spec would otherwise re-derive per page lives here, so a new
 * wave's spec is about that wave rather than about how this application's
 * login form is shaped. When the chrome changes, it changes in one file.
 */

/** The component layer's own selectors, in one place so a rename is one edit. */
const SELECTORS = {
  shellBody: 'body',
  // Two shapes, one job: Setup renders a button grid, every other family a
  // scrolling strip. A spec names the entry, not the chrome it happens to be
  // in, so both are listed and openFromSubMenu tries each.
  subNavLink: ['.p202c-subnav__link', '.p202c-strip__list a'],
  subNavCurrent: '.p202c-subnav__link[aria-current="page"], .p202c-strip__list a[aria-current="page"]',
  flashBody: '.p202-flash__body',
  fieldError: '.invalid-feedback',
  panel: '.p202-panel',
  emptyTitle: '.p202-empty__title',
  disclosure: 'details[data-p202-remember]',
  copyButton: '[data-p202-copy]',
  revealButton: '[data-p202-reveal]',
  tableWrap: '.p202-table-wrap',
};

class App {
  /**
   * @param {import('./ui').Ui} ui
   * @param {object} session from lib/browser newSession
   * @param {object} config
   */
  constructor(ui, session, config) {
    this.ui = ui;
    this.page = ui.page;
    this.session = session;
    this.config = config;
  }

  url(pathname) {
    return this.config.base + (pathname.startsWith('/') ? pathname : '/' + pathname);
  }

  // ── Getting in ────────────────────────────────────────────────────

  /** Sign in. */
  async login() {
    await this.ui.goto(this.url('/202-login.php'));
    await this.ui.fill({
      'input[name="user_name"]': this.config.user,
      'input[type="password"]': this.config.pass,
    });
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
      this.page.press('input[type="password"]', 'Enter'),
    ]);
    await this.ui.goto(this.url('/202-account/'));

    // The login page is the only page with a password field; reaching one
    // without it is the proof the session took. Checking the URL is not
    // enough — a failed login re-renders the same address.
    const stillOnLogin = await this.ui.exists('input[type="password"]');
    if (stillOnLogin) {
      throw new Error('Login failed for "' + this.config.user + '" — the password field is still on the page');
    }
  }

  // ── Moving around ─────────────────────────────────────────────────

  async goto(pathname) {
    return this.ui.goto(this.url(pathname));
  }

  /** Click a sub-menu entry by its label, the way a person reaches a page. */
  async openFromSubMenu(label) {
    // :has-text() binds to the selector it sits on, so it goes on each
    // alternative rather than once after a comma-joined list.
    const selector = SELECTORS.subNavLink
      .map((base) => base + ':has-text("' + label + '")')
      .join(', ');
    const link = await this.page.$(selector);
    if (!link) {
      throw new Error('No sub-menu entry labelled "' + label + '" on ' + this.page.url());
    }
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
      link.click(),
    ]);
    await this.ui.ready();
  }

  /** Which sub-menu entry the page marks as current. */
  async currentSubMenuItem() {
    return this.ui.text(SELECTORS.subNavCurrent) || '(none)';
  }

  async shell() {
    const className = await this.page.$eval('body', (b) => b.className);
    const match = className.match(/p202-shell-(\w+)/);
    return match ? match[1] : '(none)';
  }

  // ── What the page says ────────────────────────────────────────────

  /** Flash messages, in order. */
  async flashes() {
    return this.ui.texts(SELECTORS.flashBody);
  }

  /** Per-field messages, in order. */
  async fieldErrors() {
    return this.ui.texts(SELECTORS.fieldError);
  }

  /** Everything the page is saying, for a single readable assertion. */
  async messages() {
    const [flashes, fields] = await Promise.all([this.flashes(), this.fieldErrors()]);
    return flashes.concat(fields).join(' | ');
  }

  // ── Acting ────────────────────────────────────────────────────────

  /** Submit a form by clicking its button, and wait for what comes back. */
  async submit(buttonSelector) {
    return this.ui.clickThrough(buttonSelector);
  }

  /**
   * Click something guarded by a confirm, answering it either way.
   *
   * The policy is set before the click and cleared after, so a dialog that
   * never appears cannot leave a handler behind to swallow the next one.
   *
   * @param {'accept'|'dismiss'} answer
   * @returns {Promise<string>} what the dialog said, or '' if none appeared
   */
  async confirmAnd(answer, selector) {
    this.session.expectDialog(answer);
    try {
      if (answer === 'accept') {
        await this.ui.clickThrough(selector);
      } else {
        // Dismissing cancels the submit, so there is no navigation to await.
        await this.page.click(selector);
        await this.ui.settle(300); // nothing to wait for: the point is that nothing happens
      }
    } finally {
      this.session.endDialogExpectation();
    }
    return this.session.lastDialog;
  }

  /** Toggle a masked value and return what is on screen afterwards. */
  async reveal(targetSelector, buttonSelector = SELECTORS.revealButton) {
    const before = await this.ui.text(targetSelector);
    await this.page.click(buttonSelector);
    await this.ui.until(async () => (await this.ui.text(targetSelector)) !== before, {
      describe: 'the masked value to change',
    });
    return this.ui.text(targetSelector);
  }

  /** Click a copy button and read what landed on the clipboard. */
  async copy(buttonSelector) {
    await this.page.click(buttonSelector);
    return this.ui.until(async () => {
      const text = await this.ui.clipboard();
      return text ? text : false;
    }, { describe: 'the clipboard to receive the copied text' });
  }

  /** Open an Advanced disclosure and wait for its contents. */
  async openDisclosure(selector = SELECTORS.disclosure) {
    await this.page.click(selector + ' summary');
    await this.ui.until(async () => this.page.$eval(selector, (d) => d.open), {
      describe: 'the disclosure to open',
    });
  }

  async disclosureOpen(selector = SELECTORS.disclosure) {
    return this.page.$eval(selector, (d) => d.open).catch(() => null);
  }
}

module.exports = { App, SELECTORS };
