'use strict';

/*
 * A fresh install's license-key step, driven the way a customer drives it:
 * the portal's "Get my API key" returns to get_apikey.php with the key in the
 * query string, the page's Save takes it to the installer, and the installer
 * finishes with that key stored.
 *
 * From 1.9.76 the middle step could not succeed: Save checked the key through
 * an endpoint that answered only a signed-in user, and before the account
 * exists nobody is signed in. CI walks the same path over HTTP
 * (tests/fixtures/agent-eval/ci/install-instance.sh); this is the half only a
 * browser answers, the page's own script.
 *
 * It needs an instance that is NOT installed yet. It installs it as
 * P202_USER / P202_PASS, so the other specs can sign in afterwards. The
 * installer checks the key with my.tracking202.com from the server, so the
 * instance's PHP must reach a stub that answers "Key valid" (as CI's
 * licence-stub.sh does), never the real service with a made-up key: set
 * P202_LICENCE_STUB=1 to say it does. The browser itself never reaches the
 * real service either (lib/browser.js aborts every external host).
 *
 *   P202_LICENCE_STUB=1 node tests/browser/run.js --spec installer-key-step
 */

const KEY = process.env.P202_CUSTOMER_KEY || 'agent-eval-ci';

module.exports = {
  name: 'installer-key-step',
  title: 'Fresh install: the license-key step',

  async setup(ctx) {
    const { db } = ctx;
    if (process.env.P202_LICENCE_STUB !== '1') {
      throw new Error('Refusing to run: the installer checks the key with my.tracking202.com. Point the instance\'s PHP at a stub and set P202_LICENCE_STUB=1.');
    }
    const hasUsers = db.value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '202_users'");
    if (hasUsers !== '0' && db.value('SELECT COUNT(*) FROM 202_users') !== '0') {
      throw new Error('Refusing to run: this instance is already installed. Point P202_BASE and P202_DB at one that is not.');
    }
  },

  scenarios: [
    {
      name: 'Save on the portal\'s return opens the installer',
      async run(ctx) {
        const { ui, expect, session, config, shot } = ctx;
        await ui.goto(config.base + '/202-config/get_apikey.php?customers_api_key=' + encodeURIComponent(KEY));
        expect.eq(await ui.value('#user_api'), KEY, 'the page shows the key the portal returned');
        expect.ok(await ui.visible('#getapikey button[type="submit"]'), 'the Save button is there');

        await ui.clickThrough('#getapikey button[type="submit"]');
        await ui.until(async () => /\/202-config\/install\.php/.test(ctx.page.url()) || (await ui.text('#getapikey-error')) !== '', {
          describe: 'the installer to open, or the page to say why not',
        }).catch(() => {});
        await shot('after-save');

        expect.match(ctx.page.url(), /\/202-config\/install\.php/, 'Save opened the installer');
        expect.eq(session.unexpectedDialogs.join(' | '), '', 'no dialog asked for help finding the key');
        expect.ok(await ui.exists('#install-prosper202 #user_email'), 'the account form is on screen');
        expect.eq(await ui.value('#install-prosper202 #user_api'), KEY, 'the installer carries the same key');
      },
    },
    {
      name: 'The installer finishes and stores that key',
      async run(ctx) {
        const { ui, expect, db, config, shot } = ctx;
        if (!(await ui.exists('#install-prosper202 #user_email'))) {
          expect.fail('the account form is on screen', 'the previous scenario did not reach the installer');
          return;
        }
        await ui.fill({
          '#user_email': 'installer-key-step@example.com',
          '#user_name': config.user,
          '#user_pass': config.pass,
          '#verify_user_pass': config.pass,
        });
        await ui.click('#install-prosper202 button[type="submit"]');
        await ui.until(async () => /Prosper202 is installed/.test(await ui.bodyText()) || (await ui.text('#install-general-error')) !== '', {
          describe: 'the success panel, or the installer\'s error',
          timeout: 60000,
        }).catch(() => {});
        await shot('after-install');

        expect.match(await ui.bodyText(), /Success! Prosper202 is installed/, 'the installer says it finished');
        expect.eq(
          db.value("SELECT IFNULL(p202_customer_api_key, '') FROM 202_users WHERE user_name = '" + config.user.replace(/'/g, "''") + "'"),
          KEY,
          'the account holds the key the portal returned'
        );
      },
    },
  ],
};
