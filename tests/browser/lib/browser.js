'use strict';

/*
 * Browser sessions: launching, isolating, and watching what the page says.
 *
 * Three things here are not conveniences but corrections of mistakes made
 * while writing the first pass by hand:
 *
 *  - Requests leave the instance only for files the shell pins, and those are
 *    served from a local mirror. Everything else is aborted, so a pass never
 *    silently depends on the network and never reports a CDN outage as a
 *    failure of the page.
 *  - Dialogs run off a policy that the test sets before it clicks, not a
 *    one-shot listener. A one-shot left behind when the expected dialog never
 *    appears goes on to eat the NEXT dialog, which is exactly what a missing
 *    confirm looks like — the harness broke on the defect it should report.
 *  - Page errors and console errors are collected for the whole session, so a
 *    spec can assert that nothing threw anywhere along the way. Each one
 *    remembers the URL it came from: a spec that reaches its page by clicking
 *    through another one would otherwise inherit that page's errors, and
 *    "no JavaScript errors on this page" would be reporting a different page.
 */

const fs = require('fs');
const path = require('path');

/** Hosts the shell legitimately reaches, and how to answer them offline. */
const ADVERT_HOST = 'ads.tracking202.com';

async function launch(config) {
  const { chromium } = config.browser.playwright;
  return chromium.launch({
    executablePath: config.browser.chromium,
    headless: !config.browser.headed,
    slowMo: config.browser.slowMo || undefined,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
}

/** A URL reduced to the page it names: origin + path, no query, no hash. */
function samePage(url) {
  try {
    const parsed = new URL(url);
    return parsed.origin + parsed.pathname;
  } catch (error) {
    return String(url);
  }
}

/**
 * A page with its watchers attached.
 *
 * @param {import('playwright-core').Browser} browser
 * @param {object} config
 * @param {{viewport?: object, colorScheme?: string, permissions?: string[]}} [options]
 */
async function newSession(browser, config, options = {}) {
  const context = await browser.newContext({
    viewport: options.viewport || { width: 1280, height: 1000 },
    colorScheme: options.colorScheme || 'light',
    permissions: options.permissions || ['clipboard-read', 'clipboard-write'],
  });
  context.setDefaultTimeout(config.browser.timeout);

  const page = await context.newPage();

  const session = {
    context,
    page,
    /**
     * Errors the page threw, and console errors worth caring about. Each
     * entry stringifies to its message, so joining or printing the list
     * reads as it always did.
     *
     * @type {Array<{url: string, message: string}>}
     */
    errors: [],

    /**
     * Just the ones this page produced, for a per-page assertion.
     *
     * Matched on origin + path, not the whole URL: an error recorded before
     * a redirect, a replaceState or a query-string change would otherwise
     * match nothing, and a filter that always returns [] makes the caller's
     * "no JavaScript errors on this page" pass without asserting anything.
     * A spec still owes a session-wide check; this narrows where the blame
     * goes, it does not replace it.
     */
    errorsHere() {
      const here = samePage(session.page.url());
      return session.errors.filter((entry) => samePage(entry.url) === here);
    },
    /** Dialogs nobody asked for, which is a finding in itself. */
    unexpectedDialogs: [],
    lastDialog: '',
    /** 'none' | 'accept' | 'dismiss' — set this before the click that opens one. */
    dialogPolicy: 'none',

    /** Answer the next dialog this way, then go back to treating them as unexpected. */
    expectDialog(policy) {
      session.lastDialog = '';
      session.dialogPolicy = policy;
    },
    endDialogExpectation() {
      session.dialogPolicy = 'none';
    },
    async close() {
      await context.close();
    },
  };

  page.on('dialog', async (dialog) => {
    session.lastDialog = dialog.message();
    if (session.dialogPolicy === 'accept') { await dialog.accept(); return; }
    if (session.dialogPolicy === 'dismiss') { await dialog.dismiss(); return; }
    session.unexpectedDialogs.push(dialog.message());
    await dialog.dismiss();
  });

  const record = (message) => {
    session.errors.push({
      url: page.url(),
      message,
      toString() { return this.message + '  [' + this.url + ']'; },
    });
  };

  page.on('pageerror', (error) => {
    record('pageerror: ' + (error && error.message ? error.message : String(error)));
  });
  page.on('console', (message) => {
    if (message.type() !== 'error') { return; }
    const text = message.text();
    // A blocked third-party request is this harness's doing, not the page's.
    if (/net::ERR_FAILED|Failed to load resource/.test(text)) { return; }
    record('console: ' + text);
  });

  await routeOffline(page, config);
  return session;
}

/**
 * Keep the pass off the network.
 *
 * Same-host requests go through untouched. The advertising iframe the chrome
 * embeds is answered with a stub — note that this means its appearance in a
 * screenshot is the stub's, not the real one, so a dark-mode judgement about
 * that iframe cannot be made from here.
 */
async function routeOffline(page, config) {
  const mirror = config.cdnMirror;
  await page.route('**/*', (route) => {
    let url;
    try {
      url = new URL(route.request().url());
    } catch (error) {
      return route.abort();
    }
    if (url.host === config.host) { return route.continue(); }
    if (url.host.includes(ADVERT_HOST)) {
      return route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<!doctype html><body style="margin:0;font:600 18px/56px system-ui;text-align:center">Prosper202',
      });
    }
    if (mirror) {
      const file = path.join(mirror, (url.host + url.pathname).replace(/\//g, '__'));
      try {
        if (fs.existsSync(file) && fs.statSync(file).size > 100) {
          return route.fulfill({
            status: 200,
            contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript',
            body: fs.readFileSync(file),
          });
        }
      } catch (error) {
        // Fall through to the abort below; a broken mirror is not a page bug.
      }
    }
    return route.abort();
  });
}

module.exports = { launch, newSession };
