'use strict';

/*
 * The standalone and pre-login pages on the v2 shell (U7), driven the way a
 * signed-out visitor drives them.
 *
 * tests/live/prelogin-pages.sh proves the forms over HTTP, refusals included;
 * tests/live/upgrade-csrf.sh the upgrader; install-instance.sh the installer.
 * What is here is what only a browser can answer: every standalone page's
 * baseline at a desktop and a phone width and in both themes (lib/checks.js
 * STANDALONE_PAGES), in a session that has NOT signed in; the column taking
 * clicks over the wallpaper link behind it; native validation beside the
 * server's own sentences; the password reset from the emailed link to the
 * new password signing in; and, signed in, TV202, Hot Deals and the App
 * Store (FEED_SECTION_PAGES).
 *
 * This spec's main session stays signed out until its last scenarios; the
 * signed-in passes run in their own sessions (withSession signs in).
 * The admin's password is changed through the reset link and put back, and
 * the failed sign-ins it makes are removed so the brute-force throttle does
 * not carry them into the next spec.
 */

const checks = require('../lib/checks');

function forgetFailedSignIns(db, user) {
  db.write("DELETE FROM 202_users_log WHERE user_name='" + user + "' AND login_success=0");
}

module.exports = {
  name: 'prelogin-pages',
  title: 'Standalone and pre-login (U7)',

  async reset(db, ctx) {
    forgetFailedSignIns(db, ctx.config.user);
  },

  async setup(ctx) {
    const { db, state, config } = ctx;
    state.owner = db.value("SELECT user_id FROM 202_users WHERE user_name='" + config.user + "'");
    state.email = db.value('SELECT user_email FROM 202_users WHERE user_id=' + state.owner);
    state.hash = db.value('SELECT user_pass FROM 202_users WHERE user_id=' + state.owner);
    // Whatever a scenario does to the password, it comes back.
    db.temporarily('SELECT 1', "UPDATE 202_users SET user_pass='" + state.hash.replace(/'/g, "''") + "', user_pass_key=NULL, user_pass_time=0 WHERE user_id=" + state.owner);
    db.temporarily('SELECT 1', "DELETE FROM 202_users_log WHERE user_name='" + config.user + "' AND login_success=0");
  },

  scenarios: [
    {
      name: 'Every standalone page, signed out, at 1280px and 390px, light',
      async run(ctx) {
        const { ui, shot } = ctx;
        for (const width of [1280, 390]) {
          await ui.setViewport(width, 900);
          for (const entry of checks.STANDALONE_PAGES) {
            await checks.standalonePageBaseline(ctx, entry);
            await shot('standalone-' + entry.path.replace(/[^a-z0-9]+/gi, '-') + '-' + width + '-light');
          }
        }
        await ui.setViewport(1280, 900);
      },
    },

    {
      name: 'Every standalone page, signed out, in the dark theme at 1280px and 390px',
      async run(ctx) {
        const { ui, page, shot } = ctx;
        await page.emulateMedia({ colorScheme: 'dark' });
        try {
          for (const width of [1280, 390]) {
            await ui.setViewport(width, 900);
            for (const entry of checks.STANDALONE_PAGES) {
              await checks.standalonePageBaseline(ctx, entry, { dark: true });
            }
            await ctx.app.goto('/202-login.php');
            await shot('standalone-login-' + width + '-dark');
          }
        } finally {
          await page.emulateMedia({ colorScheme: 'light' });
          await ui.setViewport(1280, 900);
        }
      },
    },

    {
      name: 'Sign in: the browser asks first, the server refuses in its own words',
      async run(ctx) {
        const { app, ui, expect, db, config, state } = ctx;
        await app.goto('/202-login.php');
        expect.notOk((await ui.validity('#user_name')).valid, 'an empty username is stopped by the browser first');
        expect.eq(await ui.attr('#user_pass', 'autocomplete'), 'current-password', 'the password field lets a password manager fill it');
        await ui.fill({ '#user_name': config.user, '#user_pass': 'not-the-password' });
        await app.submit('#login-form button[type="submit"]');
        expect.eq((await app.flashes()).join(' | '), 'Your username or password is incorrect.', 'a wrong password is refused in words');
        expect.eq(await ui.value('#user_name'), config.user, 'the username typed is kept');
        expect.eq(await ui.page.evaluate(() => document.activeElement && document.activeElement.id), 'user_pass', 'and the cursor waits in the password field');
        expect.eq(db.value('SELECT COUNT(*) FROM 202_users_log WHERE user_name=\'' + config.user + '\' AND login_success=0') > 0, true, 'the failed attempt is logged for the throttle');
        expect.ok(state.owner !== '', 'the account exists');
      },
    },

    {
      name: 'Password reset: request, follow the link, choose a password, sign in with it',
      async run(ctx) {
        const { app, ui, expect, db, config, state, shot } = ctx;
        await app.goto('/202-login.php');
        await ui.clickThrough('a[href$="202-lost-pass.php"]');
        expect.match(ui.page.url(), /202-lost-pass\.php$/, 'the sign-in page links to the reset');
        await ui.fill({ '#user_name': config.user, '#user_email': 'not-an-address' });
        expect.notOk((await ui.validity('#user_email')).valid, 'an email that is not an address is stopped by the browser');
        await ui.fill({ '#user_email': state.email });
        await app.submit('#lost-pass-form button[type="submit"]');
        expect.eq(await ui.text('.p202-standalone__title'), 'Check your email', 'the request says to check the email');
        const key = db.value('SELECT IFNULL(user_pass_key, \'\') FROM 202_users WHERE user_id=' + state.owner);
        expect.eq(key.length, 64, 'and a reset key is stored');

        await app.goto('/202-pass-reset.php?key=' + key);
        expect.eq(await ui.value('#user_name'), config.user, 'the link names the account it resets');
        await ui.fill({ '#user_pass': 'short', '#verify_user_pass': 'short' });
        expect.notOk((await ui.validity('#user_pass')).valid, 'a password under 8 characters is stopped by the browser');
        await ui.fill({ '#user_pass': 'u7-browser-pass-1', '#verify_user_pass': 'u7-browser-pass-2' });
        await app.submit('#pass-reset-form button[type="submit"]');
        expect.eq((await app.flashes()).join(' | '), 'Your passwords did not match, please try again', 'two different passwords are refused in the server\'s words');
        expect.ok(await ui.exists('#user_pass.is-invalid'), 'and the field says it');
        await shot('pass-reset-mismatch');
        await ui.fill({ '#user_pass': 'u7-browser-pass-1', '#verify_user_pass': 'u7-browser-pass-1' });
        await app.submit('#pass-reset-form button[type="submit"]');
        expect.eq(await ui.text('.p202-standalone__message h6'), 'Password changed', 'the password is changed');
        await ui.clickThrough('.p202-standalone__message a[href$="202-login.php"]');
        await ui.fill({ '#user_name': config.user, '#user_pass': 'u7-browser-pass-1' });
        await app.submit('#login-form button[type="submit"]');
        expect.match(new URL(ui.page.url()).pathname, /\/202-account\/?$/, 'and the new password signs in');
        // Signed out again, and the old password back, for the scenarios after
        // this one (setup's undo puts it back too, should this scenario crash).
        await app.goto('/202-account/signout.php');
        db.write("UPDATE 202_users SET user_pass='" + state.hash.replace(/'/g, "''") + "' WHERE user_id=" + state.owner);
      },
    },

    {
      name: 'The license-key page: the browser asks for a key, the server says why it refused',
      async run(ctx) {
        const { app, ui, expect, db, state } = ctx;
        const before = db.value('SELECT IFNULL(p202_customer_api_key, \'\') FROM 202_users WHERE user_id=' + state.owner);
        await app.goto('/api-key-required.php');
        expect.notOk((await ui.validity('#api_key')).valid, 'an empty key is stopped by the browser first');
        await ui.fill({ '#api_key': '   ' });
        await app.submit('#api-key-form button[type="submit"]');
        const flashes = await app.flashes();
        expect.includes(flashes, 'Please enter your API key.', 'spaces are refused in the server\'s words');
        expect.ok(await ui.exists('.alert-danger.p202-flash'), 'as an error, beside the note about the key already saved');
        expect.eq(db.value('SELECT IFNULL(p202_customer_api_key, \'\') FROM 202_users WHERE user_id=' + state.owner), before, 'and nothing is saved');
      },
    },

    {
      name: 'The retired mobile site leads to the responsive pages',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/202-Mobile/');
        expect.match(new URL(ui.page.url()).pathname, /\/202-login\.php$/, '202-Mobile/ ends on the sign-in page');
        await app.goto('/202-Mobile/mini-stats/');
        expect.match(ui.page.url(), /202-login\.php/, 'the mini stats ask for a sign-in, on the way to Campaign Overview');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'Signed in: TV202, Hot Deals and the App Store at 1280px and 390px, light and dark',
      async run(ctx) {
        const { withSession, config } = ctx;
        for (const colorScheme of ['light', 'dark']) {
          for (const width of [1280, 390]) {
            await withSession({ colorScheme, viewport: { width, height: 900 } }, async (other) => {
              for (const entry of checks.FEED_SECTION_PAGES) {
                await checks.feedSectionBaseline({ ...ctx, ...other }, entry, { dark: colorScheme === 'dark' });
              }
              await other.app.goto('/202-resources/');
              await other.page.screenshot({ path: require('path').join(config.shots, 'feeds-resources-' + width + '-' + colorScheme + '.png'), fullPage: true });
            });
          }
        }
      },
    },
  ],
};
