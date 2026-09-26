'use strict';

/*
 * The update banner under the header (202-config/functions-update-banner.php,
 * drawn by 202-js/p202-chrome.js). U8 moved it from the classic shell's
 * Bootstrap 3 panels to the component layer; no v2 page drew it before.
 *
 * What is real and what is stood in for, stated plainly: the instance is
 * newer than any release the feed advertises, so the real update-needed.php
 * answers with nothing, and there is no way to make it answer otherwise from
 * the browser. The banner's markup is therefore produced by the real
 * p202_update_banner() — run through the PHP CLI here, not written by hand —
 * and served in place of that one response. Everything else is the real
 * path: the chrome script's idle-time request to check-for-update.php, its
 * drawing into #update_needed, Bootstrap's own dismissal, and the POST to
 * delay-alert.php that snoozes it. After the snooze the page is reloaded with
 * nothing stood in for, and the real endpoints answer.
 *
 * Checked at 1280px and 390px, light and dark.
 */

const path = require('path');
const { execFileSync } = require('child_process');
const checks = require('../lib/checks');

const ROOT = path.resolve(__dirname, '..', '..', '..');

/** The real renderer's markup for a state, through the PHP CLI. */
function bannerHtml(state) {
  const code = 'require ' + JSON.stringify(path.join(ROOT, '202-config/functions-update-banner.php')) + ';'
    + ' echo p202_update_banner(json_decode($argv[1], true), "/");';
  return execFileSync(process.env.P202_PHP || 'php', ['-r', code, JSON.stringify(state)], { encoding: 'utf8' });
}

const STATE = {
  show: true,
  premium: true,
  premium_details: {
    headline: 'Prosper202 Pro 2.0 is out',
    body: 'Faster reports and a new attribution engine.',
    'release-date': '9/1/2026',
    'register-link': 'https://my.tracking202.com/register',
    'register-button-text': 'Get Started',
  },
};

async function drawnBanner(ctx) {
  const { ui } = ctx;
  await ui.until(async () => ui.visible('[data-p202-update-banner]'), { describe: 'the update banner to be drawn' });
}

/** Draw the banner on the Account home, check it, dismiss it, reload. */
async function bannerPass(ctx, label) {
  const { app, ui, page, expect } = ctx;
  const html = bannerHtml(STATE);
  expect.ok(html.includes('data-p202-update-banner'), label + ': the renderer produced a banner');

  const checked = page.waitForResponse((r) => r.url().includes('/202-account/ajax/check-for-update.php'), { timeout: 15000 });
  await page.route('**/202-account/ajax/update-needed.php', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: html }));
  try {
    await app.goto('/202-account/');
    const check = await checked;
    expect.eq(check.status(), 200, label + ': the chrome asked check-for-update.php first');
    await drawnBanner(ctx);

    await checks.baseline(ctx);
    await checks.componentClassesAreStyled(ctx);
    await checks.flexContainersKeepTheirSpaces(ctx);
    await checks.noLegacyClasses(ctx, ['panel', 'panel-body', 'panel-heading', 'btn-xs', 'btn-default', 'close']);

    const header = await ui.box('.p202c-header');
    const banner = await ui.box('[data-p202-update-banner]');
    const main = await ui.box('.p202-frame .main');
    expect.ok(header && banner && main && banner.y >= header.y + header.height && banner.y + banner.height <= main.y,
      label + ': the banner sits between the header and the content', JSON.stringify({ header, banner, main }));
    expect.ok(banner && main && Math.abs(banner.x - main.x) <= 1 && Math.abs(banner.width - main.width) <= 1,
      label + ': and spans the content column', JSON.stringify({ banner, main }));
    const text = await ui.text('[data-p202-update-banner] .p202-flash__body');
    expect.ok(text.includes('Prosper202 Pro 2.0 is out') && text.includes('Personal Settings'), label + ': it says what is out and what to do', text);
    await page.screenshot({ path: path.join(ctx.config.shots, 'update-banner-' + label.replace(/\s+/g, '-') + '.png'), clip: { x: 0, y: 0, width: page.viewportSize().width, height: 420 } });

    const snoozed = page.waitForResponse((r) => r.url().includes('/202-account/ajax/delay-alert.php') && r.request().method() === 'POST');
    await page.click('[data-p202-update-banner] .btn-close');
    const snooze = await snoozed;
    expect.eq(snooze.status(), 200, label + ': closing it posts the snooze, and the server takes it');
    expect.eq(snooze.request().postData(), 'delay=1', label + ': with the field delay-alert.php reads');
    await ui.until(async () => !(await ui.exists('[data-p202-update-banner]')), { describe: 'the banner to close' });
    expect.ok(true, label + ': the banner is gone');
  } finally {
    await page.unroute('**/202-account/ajax/update-needed.php');
  }

  // Nothing stood in for now: the real endpoints answer, and draw nothing.
  const realCheck = page.waitForResponse((r) => r.url().includes('/202-account/ajax/check-for-update.php'), { timeout: 15000 });
  const realBanner = page.waitForResponse((r) => r.url().includes('/202-account/ajax/update-needed.php'), { timeout: 15000 });
  await app.goto('/202-account/');
  expect.eq((await realCheck).status(), 200, label + ': the real check answers');
  const answered = await realBanner;
  expect.eq(answered.status(), 200, label + ': the real banner endpoint answers');
  expect.eq((await answered.text()).trim(), '', label + ': with nothing, for an install this new or a snoozed banner');
  await ui.settle();
  expect.eq(await ui.exists('[data-p202-update-banner]'), false, label + ': and nothing is drawn');
  await checks.baseline(ctx);
}

module.exports = {
  name: 'update-banner',
  title: 'The update banner under the header',

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'The banner draws, closes and snoozes at 1280px, light',
      async run(ctx) {
        await bannerPass(ctx, '1280 light');
      },
    },
    {
      name: 'The banner at 390px and in the dark theme',
      async run(ctx) {
        const { withSession } = ctx;
        await withSession({ viewport: { width: 390, height: 844 } }, async (phone) => {
          await bannerPass({ ...ctx, ...phone }, '390 light');
        });
        for (const width of [1280, 390]) {
          await withSession({ colorScheme: 'dark', viewport: { width, height: 900 } }, async (dark) => {
            const sub = { ...ctx, ...dark };
            await bannerPass(sub, width + ' dark');
            await checks.darkThemeApplies(sub);
          });
        }
      },
    },
  ],
};
