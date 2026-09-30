'use strict';

/*
 * The Account family on the v2 shell (U6), driven the way a person drives it.
 *
 * tests/live/account-pages.sh proves the forms over HTTP, refusals included.
 * What is here is what only a browser can answer: every page's baseline at a
 * desktop and a phone width and in both themes (lib/checks.js ACCOUNT_PAGES),
 * native validation letting through what the server then refuses in its own
 * words, the Advanced disclosures that remember, Reveal and Copy on API keys,
 * and every confirmation — saying no must change nothing, saying yes must do
 * what the dialog said.
 *
 * Everything that claims a write reads the database.
 */

const checks = require('../lib/checks');

const PROBE_USER = 'u6_browser_user';
const PROBE_EMAIL = 'u6-browser@example.test';

function dropProbeUser(db) {
  const ids = db.value("SELECT IFNULL(GROUP_CONCAT(user_id), '') FROM 202_users WHERE user_name='" + PROBE_USER + "'");
  if (ids) {
    db.write('DELETE FROM 202_user_role WHERE user_id IN (' + ids + ')');
    db.write('DELETE FROM 202_users_pref WHERE user_id IN (' + ids + ')');
    db.write('DELETE FROM 202_users WHERE user_id IN (' + ids + ')');
  }
}

module.exports = {
  name: 'account-pages',
  title: 'Account (U6)',

  async reset(db) {
    dropProbeUser(db);
    db.write("DELETE FROM 202_dni_networks WHERE networkId='u6browser'");
  },

  async setup(ctx) {
    await ctx.app.login();
    ctx.state.owner = ctx.db.value("SELECT user_id FROM 202_users WHERE user_name='" + ctx.config.user + "'");
    ctx.state.keys = ctx.db.value('SELECT COUNT(*) FROM 202_api_keys WHERE user_id=' + ctx.state.owner);
  },

  scenarios: [
    {
      name: 'Reaching Personal settings the way a user does',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/202-account/');
        await ui.click('#account-dropdown summary');
        await ui.clickThrough('#PersonalSettingsPage');
        expect.eq(new URL(ui.page.url()).pathname, '/202-account/account.php', 'the account menu leads to the page');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'Every Account page at 1280px, light',
      async run(ctx) {
        for (const page of checks.ACCOUNT_PAGES) {
          await checks.accountPageBaseline(ctx, page);
        }
      },
    },

    {
      name: 'Every Account page at 390px, light',
      async run(ctx) {
        const { withSession, expect, config } = ctx;
        await withSession({ viewport: { width: 390, height: 844 } }, async (phone) => {
          for (const page of checks.ACCOUNT_PAGES) {
            await checks.accountPageBaseline({ ...ctx, ...phone }, page);
          }
          await phone.app.goto('/202-account/account.php');
          await phone.page.screenshot({ path: require('path').join(config.shots, 'account-pages-phone-settings.png'), fullPage: true });
          expect.ok(true, 'phone screenshots written');
        });
      },
    },

    {
      name: 'Every Account page in the dark theme, at 1280px and 390px',
      async run(ctx) {
        const { withSession, config } = ctx;
        for (const width of [1280, 390]) {
          await withSession({ colorScheme: 'dark', viewport: { width, height: 900 } }, async (dark) => {
            for (const page of checks.ACCOUNT_PAGES) {
              const sub = { ...ctx, ...dark };
              await checks.accountPageBaseline(sub, page);
              await checks.darkThemeApplies(sub);
            }
            await dark.app.goto('/202-account/api-integrations.php');
            await dark.page.screenshot({ path: require('path').join(config.shots, 'account-pages-dark-' + width + '-integrations.png'), fullPage: true });
          });
        }
      },
    },

    {
      name: 'Personal settings: Advanced is closed by default and remembers being opened',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/202-account/account.php');
        const advanced = 'details[data-p202-remember="account-profile-advanced"]';
        expect.eq(await app.disclosureOpen(advanced), false, 'Advanced starts closed');
        expect.notOk(await ui.visible('#user_keyword_searched_or_bidded'), 'its fields are hidden until opened');
        await app.openDisclosure(advanced);
        expect.ok(await ui.visible('#user_keyword_searched_or_bidded'), 'opening it reveals the keyword preference');
        await ui.page.reload({ waitUntil: 'load' });
        await ui.ready();
        expect.eq(await app.disclosureOpen(advanced), true, 'and it is still open after a reload');
        // Put it back for the scenarios after this one.
        await ui.page.evaluate(() => { try { localStorage.clear(); } catch (e) { /* ignore */ } });
      },
    },

    {
      name: 'Personal settings: the browser lets through what the server refuses, and the server says so',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        const before = db.value('SELECT user_email FROM 202_users WHERE user_id=' + state.owner);
        await app.goto('/202-account/account.php');
        // A valid address to the browser, not to PHP's filter: the server's
        // sentence has to reach the page under the field.
        await ui.fill({ '#user_email': 'a@b' });
        expect.ok((await ui.validity('#user_email')).valid, 'the browser accepts a@b');
        await app.submit('#profile button[type="submit"]');
        expect.eq(await ui.text('#profile .invalid-feedback'), 'Please enter a valid email address.', 'the server refuses it under the field');
        expect.eq(await ui.value('#user_email'), 'a@b', 'what was typed is kept');
        expect.eq(db.value('SELECT user_email FROM 202_users WHERE user_id=' + state.owner), before, 'and nothing was saved');

        await ui.fill({ '#user_email': '' });
        expect.notOk((await ui.validity('#user_email')).valid, 'an empty email is stopped by the browser first');
      },
    },

    {
      name: 'Personal settings: saving the profile',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        const before = db.value('SELECT user_daily_email FROM 202_users_pref WHERE user_id=' + state.owner);
        await app.goto('/202-account/account.php');
        await ui.select('#user_daily_email', '06');
        await app.submit('#profile button[type="submit"]');
        expect.match(await app.messages(), /Your settings are saved\./, 'it says it saved');
        expect.eq(db.value('SELECT user_daily_email FROM 202_users_pref WHERE user_id=' + state.owner), '06', 'the daily email hour is stored');
        db.write("UPDATE 202_users_pref SET user_daily_email='" + before + "' WHERE user_id=" + state.owner);
      },
    },

    {
      name: 'API keys: generate, reveal, copy, and revoke only when confirmed',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        await app.goto('/202-account/account.php');
        await app.submit('#api-keys button:has-text("Generate")');
        expect.match(await app.messages(), /API key created/, 'generating says so');
        const key = db.value('SELECT api_key FROM 202_api_keys WHERE user_id=' + state.owner + ' ORDER BY created_at DESC, api_key LIMIT 1');
        expect.match(key, /^[0-9a-f]{64}$/, 'a 64-hex key was stored');

        const row = '#api-keys [data-api-key-row]:has(pre[data-p202-value="' + key + '"])';
        const masked = await ui.text(row + ' pre');
        expect.notOk(masked.includes(key), 'the key is masked on screen', masked);
        const revealed = await app.reveal(row + ' pre', row + ' [data-p202-reveal]');
        expect.eq(revealed, key, 'Reveal shows the whole key');
        await ui.page.context().grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => {});
        const copied = await app.copy(row + ' .p202-copy');
        expect.eq(copied, key, 'Copy puts the whole key on the clipboard');

        const said = await app.confirmAnd('dismiss', row + ' button:has-text("Revoke")');
        expect.match(said, /Revoke this API key\?/, 'revoking asks first', said || '(no dialog)');
        expect.eq(db.value("SELECT COUNT(*) FROM 202_api_keys WHERE api_key='" + key + "'"), '1', 'saying no keeps the key');
        await app.confirmAnd('accept', row + ' button:has-text("Revoke")');
        expect.eq(db.value("SELECT COUNT(*) FROM 202_api_keys WHERE api_key='" + key + "'"), '0', 'saying yes revokes it');
        expect.match(await app.messages(), /API key revoked/, 'and says so');
        expect.eq(db.value('SELECT COUNT(*) FROM 202_api_keys WHERE user_id=' + state.owner), state.keys, 'back to the keys it had');
      },
    },

    {
      name: 'Password: a wrong current password is refused under its field',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        const hash = db.value('SELECT user_pass FROM 202_users WHERE user_id=' + state.owner);
        await app.goto('/202-account/account.php#password');
        await ui.fill({ '#user_pass': 'not-the-password', '#new_user_pass': 'Browser-Pass-1', '#retype_new_user_pass': 'Browser-Pass-1' });
        await app.submit('#password button[type="submit"]');
        expect.eq(await ui.text('#password .invalid-feedback'), 'Your old password was typed incorrectly.', 'the sentence is under the current password');
        expect.eq(await ui.value('#user_pass'), '', 'no password is put back into a field');
        expect.eq(db.value('SELECT user_pass FROM 202_users WHERE user_id=' + state.owner), hash, 'and the password is unchanged');
      },
    },

    {
      name: 'Users: add, a server refusal, edit the role, remove only when confirmed',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto('/202-account/user-management.php');
        expect.ok(await ui.visible('.p202-empty__title'), 'an empty list says so and offers the first step');
        await ui.fill({
          '#user_fname': 'Browser', '#user_lname': 'Probe', '#user_email': PROBE_EMAIL,
          '#user_name': PROBE_USER, '#user_password': 'Browser-Pass-1', '#user_password2': 'Browser-Pass-1',
        });
        await ui.select('#user_role', '4');
        await app.submit('#user-form button[type="submit"]');
        expect.match(await app.messages(), /is added as Campaign optimizer/, 'adding says who and as what');
        const id = db.value("SELECT user_id FROM 202_users WHERE user_name='" + PROBE_USER + "' AND user_deleted=0");
        expect.ok(id !== '', 'the user row exists');
        expect.eq(db.value('SELECT role_id FROM 202_user_role WHERE user_id=' + (id || 0)), '4', 'with role 4');

        // Same username again: the browser has nothing to object to; the server does.
        await ui.fill({
          '#user_fname': 'Second', '#user_lname': 'Probe', '#user_email': 'u6-second@example.test',
          '#user_name': PROBE_USER, '#user_password': 'Browser-Pass-1', '#user_password2': 'Browser-Pass-1',
        });
        await app.submit('#user-form button[type="submit"]');
        expect.match(await app.fieldErrors().then((e) => e.join(' | ')), /The username you entered already exists\./, 'a taken username is refused under the field');

        await app.goto('/202-account/user-management.php');
        await ui.clickThrough('[data-user-id="' + id + '"] a:has-text("edit")');
        expect.eq(await ui.value('#user_email'), PROBE_EMAIL, 'the edit form opens with the user');
        await ui.select('#user_role', '3');
        await app.submit('#user-form button:has-text("Save changes")');
        expect.eq(db.value('SELECT role_id FROM 202_user_role WHERE user_id=' + id), '3', 'the role changed to Campaign manager');

        const remove = '[data-user-id="' + id + '"] button:has-text("remove")';
        const said = await app.confirmAnd('dismiss', remove);
        expect.match(said, /Remove Browser Probe\?/, 'removing asks first, by name', said || '(no dialog)');
        expect.eq(db.value('SELECT user_deleted FROM 202_users WHERE user_id=' + id), '0', 'saying no keeps the user');
        await app.confirmAnd('accept', remove);
        expect.eq(db.value('SELECT user_deleted FROM 202_users WHERE user_id=' + id), '1', 'saying yes removes them');
        dropProbeUser(db);
      },
    },

    {
      name: 'API integrations: a key saves from its disclosure; a DNI network is removed only when confirmed',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        const before = db.value("SELECT IFNULL(ipqs_api_key, '') FROM 202_users_pref WHERE user_id=" + state.owner);
        await app.goto('/202-account/api-integrations.php');
        await ui.click('#ipqs summary');
        await ui.fill({ '#ipqs_api_key': 'u6-browser-ipqs' });
        await app.submit('#ipqs button[type="submit"]');
        expect.match(await app.messages(), /IPQualityScore API key was saved/, 'saving says so');
        expect.eq(db.value('SELECT ipqs_api_key FROM 202_users_pref WHERE user_id=' + state.owner), 'u6-browser-ipqs', 'and stores it');
        db.write("UPDATE 202_users_pref SET ipqs_api_key='" + before + "' WHERE user_id=" + state.owner);

        db.write("INSERT INTO 202_dni_networks (user_id, networkId, name, type, apiKey, affiliateId, time, processed, shortDescription, favIcon) VALUES (1, 'u6browser', 'U6 Browser Network', 'HasOffers', 'u6-browser-dni-key', NULL, UNIX_TIMESTAMP(), 1, '', '')");
        await app.goto('/202-account/api-integrations.php');
        const remove = '#dni tr:has-text("U6 Browser Network") button:has-text("Remove")';
        const said = await app.confirmAnd('dismiss', remove);
        expect.match(said, /Remove U6 Browser Network\?/, 'removing asks first, by name', said || '(no dialog)');
        expect.eq(db.value("SELECT COUNT(*) FROM 202_dni_networks WHERE networkId='u6browser'"), '1', 'saying no keeps it');
        await app.confirmAnd('accept', remove);
        expect.eq(db.value("SELECT COUNT(*) FROM 202_dni_networks WHERE networkId='u6browser'"), '0', 'saying yes removes it');

        if (await ui.exists('#dni_network option[data-type="Cake"]')) {
          const cake = await ui.page.$eval('#dni_network option[data-type="Cake"]', (o) => o.value);
          await ui.select('#dni_network', cake);
          expect.ok(await ui.visible('#dni_network_affiliate_id'), 'a Cake network asks for the affiliate ID');
          expect.eq(await ui.value('#dni_network_type'), 'Cake', 'and sends its type');
        } else {
          expect.skip('a Cake network asks for the affiliate ID', 'no Cake network in the DNI list this instance received');
        }
      },
    },

    {
      name: 'Settings: deleting click data asks first, and saying no schedules nothing',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        const marker = () => db.value("SELECT IFNULL(user_delete_data_clickid, 'NULL') FROM 202_users_pref WHERE user_id=" + state.owner);
        const before = marker();
        await app.goto('/202-account/administration.php');
        await ui.click('#database summary');
        await ui.fill({ '#erase_clicks_date': '2020-01-01' });
        const said = await app.confirmAnd('dismiss', '#erase_clicks_form button[type="submit"]');
        expect.match(said, /delete all your click data from before this date/, 'it asks first', said || '(no dialog)');
        expect.eq(marker(), before, 'saying no schedules nothing');

        await ui.fill({ '#auto_erase_clicks_date': '45' });
        await app.submit('#database button:has-text("Save")');
        expect.match(await app.messages(), /older than 45 days/, 'retention saves');
        expect.eq(db.value('SELECT user_auto_database_optimization_days FROM 202_users_pref WHERE user_id=' + state.owner), '45', 'and is stored');
        db.write('UPDATE 202_users_pref SET user_auto_database_optimization_days=0 WHERE user_id=' + state.owner);
      },
    },

    {
      name: 'Home: the getting started card can be dismissed, per browser',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/202-account/');
        if (!(await ui.exists('#p202-getting-started'))) {
          expect.skip('the card can be dismissed', 'every step is done on this instance, so there is no card');
          return;
        }
        expect.ok(await ui.visible('#p202-getting-started'), 'the card shows until dismissed');
        await ui.click('#p202-gs-dismiss');
        expect.notOk(await ui.visible('#p202-getting-started'), 'dismissing hides it');
        await ui.page.reload({ waitUntil: 'load' });
        await ui.ready();
        expect.notOk(await ui.visible('#p202-getting-started'), 'and it stays hidden');
        await ui.page.evaluate(() => { try { localStorage.removeItem('p202_getting_started_dismissed'); } catch (e) { /* ignore */ } });
      },
    },

    {
      name: 'The primary action is reachable from the keyboard',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/202-account/account.php');
        let reached = false;
        for (let i = 0; i < 80 && !reached; i++) {
          await ui.page.keyboard.press('Tab');
          reached = await ui.page.evaluate(() => (document.activeElement ? document.activeElement.textContent.trim() : '') === 'Save settings');
        }
        expect.ok(reached, 'Tab reaches "Save settings"');
      },
    },

    {
      name: 'Nothing broke along the way',
      async run(ctx) {
        const { session, expect } = ctx;
        expect.ok(session.unexpectedDialogs.length === 0,
          'no confirm appeared where none was expected', session.unexpectedDialogs.join(' | '));
        expect.ok(session.errors.length === 0,
          'no JavaScript errors during the whole pass', session.errors.slice(0, 3).join(' | '));
      },
    },
  ],
};
