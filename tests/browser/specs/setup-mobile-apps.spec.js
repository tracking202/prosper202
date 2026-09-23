'use strict';

/*
 * Setup › Mobile Apps, driven the way a person drives it.
 *
 * The point of doing this in a browser rather than with curl is the roughly
 * forty checks below that a request cannot make: the receiver probes are
 * fetch calls, Reveal and Copy and the confirms are event handlers, the
 * Advanced disclosure remembers itself in localStorage, and layout and theme
 * are questions only a rendering engine can answer.
 *
 * Everything that claims something was stored also reads the database, so a
 * page that says "saved" over a write that never happened cannot pass.
 */

const checks = require('../lib/checks');

const APP_ID = 990077001;
const STORE_LINK = 'https://apps.apple.com/us/app/summit-run/id' + APP_ID;
const PLAY_LINK = 'https://play.google.com/store/apps/details?id=com.example.app';
const PAGE = '/tracking202/setup/mobile_apps.php';
const DOT = String.fromCharCode(0x2022);

/** Classes that are script hooks rather than styling, so legitimately unstyled. */
const SCRIPT_ONLY_CLASSES = ['p202-copy-label'];

/** Type into the one register field and submit; return everything the page says. */
async function register(ctx, value) {
  const { app, ui } = ctx;
  await ui.fill({ 'input[name="app_reference"]': value });
  await app.submit('button:has-text("Register app")');
  return app.messages();
}

module.exports = {
  name: 'setup-mobile-apps',
  title: 'Setup › Mobile Apps',

  async reset(db) {
    db.truncate([
      '202_attribution_apps',
      '202_attribution_conversion_values',
      '202_attribution_postbacks',
    ]);
    db.write("UPDATE 202_users_pref SET user_account_currency='USD' WHERE user_id=1");
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Reaching the page the way a user does',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/tracking202/setup/');
        await app.openFromSubMenu('Mobile Apps');

        expect.eq(new URL(ui.page.url()).pathname, PAGE, 'the sub-menu leads to the page');
        expect.eq(await app.currentSubMenuItem(), 'Mobile Apps', 'and marks it as current');
        await checks.baseline(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);
        await checks.currentSubMenuItemIsVisible(ctx);
      },
    },

    {
      name: 'First run, with nothing registered',
      async run(ctx) {
        const { ui, expect, shot } = ctx;
        expect.ok(await ui.visible('text=No apps registered yet'), 'the empty state explains what to do');
        expect.ok(await ui.exists('input[name="app_reference"]'), 'the register field is there');
        expect.notOk(await ui.exists('[data-receiver-summary]'),
          'the Getting started checklist waits until there is an app');
        await shot('empty');
      },
    },

    {
      name: 'Receiver checks run in the browser',
      async run(ctx) {
        const { ui, expect } = ctx;
        // Wait for the probes to settle rather than for a guessed delay.
        await ui.untilInPage(
          () => Array.from(document.querySelectorAll('[data-receiver-pill]'))
            .every((el) => !/Checking/i.test(el.textContent || '')),
          undefined,
          { describe: 'both receiver probes to finish' }
        );

        const pills = await ui.texts('[data-receiver-pill]');
        expect.eq(pills.length, 2, 'both receivers are listed');
        // This instance is plain HTTP, which Apple cannot call. A green
        // "Ready" here would be a lie, and used to be exactly what it said.
        // The label covers the port as well as the scheme now: Apple calls
        // these endpoints on 443 and nothing else, so https://host:8443 is
        // just as unreachable as http:// and says so in the same words.
        expect.ok(pills.every((p) => p === 'Apple cannot reach this'),
          'an origin Apple cannot call is flagged rather than shown ready', JSON.stringify(pills));

        await ui.page.$eval('[data-receiver-pill]', (el) => { el.textContent = 'stale'; });
        await ui.click('[data-receiver-recheck]');
        await ui.until(async () => (await ui.text('[data-receiver-pill]')) !== 'stale',
          { describe: 'Re-check to re-run the probes' });
        expect.ok(true, 'Re-check re-runs them');
      },
    },

    {
      name: 'Advanced is closed by default and remembers being opened',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        expect.eq(await app.disclosureOpen(), 'false', 'Advanced starts closed');
        expect.notOk(await ui.visible('input[name="notes"]'), 'its fields are hidden until opened');

        await app.openDisclosure();
        expect.ok(await ui.visible('input[name="notes"]'), 'opening it reveals notes');

        await ui.page.reload({ waitUntil: 'load' });
        await ui.ready();
        expect.eq(await app.disclosureOpen(), 'true', 'and it is still open after a reload');
      },
    },

    {
      name: 'What the page refuses, and what it says',
      async run(ctx) {
        const { db, ui, expect, shot } = ctx;

        expect.match(await register(ctx, PLAY_LINK), /Android apps are not supported yet/,
          'a Play Store link is refused by name');
        expect.eq(db.count('202_attribution_apps'), 0, 'and nothing was registered');

        expect.match(await register(ctx, 'not-a-link'), /Must be an App Store link/,
          'junk gets the App Store sentence');

        // The field is `required`, so an empty submit never reaches the
        // server: what a user meets is the browser's own refusal.
        await ui.fill({ 'input[name="app_reference"]': '' });
        const before = ui.page.url();
        await ui.click('button:has-text("Register app")');
        const validity = await ui.validity('input[name="app_reference"]');
        expect.ok(validity.required && validity.valueMissing,
          'an empty field is caught by the browser before it is sent', JSON.stringify(validity));
        expect.eq(ui.page.url(), before, 'so the form does not submit at all');
        expect.eq(db.count('202_attribution_apps'), 0, 'still nothing registered');
        await shot('refusal');
      },
    },

    {
      name: 'Registering an app from one pasted link',
      async run(ctx) {
        const { app, db, ui, expect, state, shot } = ctx;
        const said = await register(ctx, STORE_LINK);

        expect.eq(db.count('202_attribution_apps'), 1, 'exactly one app row exists');
        expect.eq(db.value('SELECT app_id FROM 202_attribution_apps'), APP_ID, 'the id came from the link');
        expect.eq(db.value('SELECT platform FROM 202_attribution_apps'), 'ios', 'the platform was derived');
        expect.eq(db.value('SELECT LENGTH(schema_token) FROM 202_attribution_apps'), 64, 'a schema token was minted');
        expect.match(said, /App registered/, 'the page confirms it');

        state.rowId = db.value('SELECT attribution_app_id FROM 202_attribution_apps');
        expect.match(ui.page.url(), new RegExp('app=' + state.rowId), 'and lands on the app it just made');

        const body = await ui.bodyText();
        expect.ok(/Summit Run/.test(body) && /iOS/.test(body) && new RegExp(APP_ID).test(body),
          'showing the name, platform and id it derived');
        expect.notMatch(await ui.html(), /\bIOS\b/, 'and writes it iOS, never IOS');
        await shot('registered');
      },
    },

    {
      name: 'The Getting started checklist, once there is an app',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE);
        await ctx.ui.untilInPage(
          () => {
            const el = document.querySelector('[data-receiver-summary]');
            return el && !/checking/i.test(el.textContent || '');
          },
          undefined,
          { describe: 'the checklist to report a count' }
        );
        expect.eq(await ui.text('[data-receiver-summary]'), '0 of 2 ready', 'it reports the real receiver count');
        expect.includes(await ui.texts('.p202-list__meta'), '1 registered', 'and counts the registered app');
      },
    },

    {
      name: 'Starter schema',
      async run(ctx) {
        const { app, db, ui, expect, state } = ctx;
        await app.goto(PAGE + '?app=' + state.rowId);
        expect.ok(await ui.visible('text=No rules yet, so nothing decodes'), 'an app with no rules says so');

        await app.submit('button:has-text("Use starter schema")');
        expect.eq(db.count('202_attribution_conversion_values', 'app_id=' + APP_ID), 6,
          'one click adds six rules');
        expect.ok(await ui.count('table.p202-table tbody tr') >= 6, 'and the table shows them');
      },
    },

    {
      name: 'The add-rule form swaps fields with the kind',
      async run(ctx) {
        const { ui, expect } = ctx;
        expect.ok(await ui.visible('select[name="fine_value"]'), 'fine value is shown by default');

        await ui.check('#kind_coarse');
        await ui.until(() => ui.visible('select[name="coarse_value"]'), { describe: 'the coarse field to appear' });
        expect.ok(true, 'choosing Coarse shows the coarse field');
        expect.notOk(await ui.visible('select[name="fine_value"]'), 'and hides the fine one');

        await ui.check('#kind_fine');
        await ui.until(() => ui.visible('select[name="fine_value"]'), { describe: 'the fine field to return' });
        expect.ok(true, 'switching back restores fine');
      },
    },

    {
      name: 'Adding a rule by typing',
      async run(ctx) {
        const { app, db, ui, expect } = ctx;
        await ui.select('select[name="fine_value"]', 7);
        await ui.fill({ 'input[name="event_name"]': 'subscribed', 'input[name="revenue"]': '9.99' });
        await app.submit('button:has-text("Add rule")');

        const where = 'app_id=' + APP_ID + ' AND fine_value=7';
        expect.eq(db.value('SELECT event_name FROM 202_attribution_conversion_values WHERE ' + where),
          'subscribed', 'the rule is stored');
        expect.eq(db.value('SELECT revenue FROM 202_attribution_conversion_values WHERE ' + where),
          '9.99000', 'with its revenue');
      },
    },

    {
      name: 'Revenue reads as money',
      async run(ctx) {
        const { ui, expect } = ctx;
        const amounts = (await ui.texts('td.num')).filter((t) => /\d/.test(t));
        expect.includes(amounts, '$9.99', 'the rule shows $9.99');
        expect.ok(amounts.every((a) => !/^[\d.]+$/.test(a)), 'no amount is left as a bare number',
          JSON.stringify(amounts.slice(0, 8)));
        expect.eq(await ui.text('.input-group-text'), '$', 'the revenue field names its currency');
      },
    },

    {
      name: 'Editing a rule',
      async run(ctx) {
        const { app, db, ui, expect } = ctx;
        const where = 'app_id=' + APP_ID + ' AND fine_value=7';

        await app.submit('tr:has-text("subscribed") a:has-text("edit")');
        expect.eq(await ui.value('input[name="event_name"]'), 'subscribed', 'the form opens with the rule in it');

        await ui.fill({ 'input[name="revenue"]': '14.50' });
        await app.submit('button:has-text("Save rule"), button:has-text("Add rule")');
        expect.eq(db.value('SELECT revenue FROM 202_attribution_conversion_values WHERE ' + where),
          '14.50000', 'saving updates it');
        expect.eq(db.count('202_attribution_conversion_values', 'app_id=' + APP_ID), 7,
          'and does not add a second rule');
      },
    },

    {
      name: 'A rule the API refuses says so, on the page it came from',
      async run(ctx) {
        const { app, db, ui, expect, state } = ctx;
        // handleGet() reads the app id from the query string and a POST has
        // none, so this used to re-render the apps LIST: no rules form, no
        // field error, and a rejected value the page never mentioned.
        await app.goto(PAGE + '?app=' + state.rowId);
        await ui.select('select[name="fine_value"]', 12);
        await ui.fill({ 'input[name="event_name"]': 'refused_probe', 'input[name="revenue"]': '-5' });
        await app.submit('button:has-text("Add rule")');

        const cameBack = await ui.exists('input[name="event_name"]');
        expect.ok(cameBack, 'it stays on the app, not the apps list');
        expect.match((await app.fieldErrors()).join(' | '), /Must be between 0 and/,
          'the API sentence is under the field');
        if (cameBack) {
          expect.eq(await ui.value('input[name="event_name"]'), 'refused_probe',
            'and what was typed is still there');
        } else {
          expect.fail('and what was typed is still there', 'the rules form is not on the page that came back');
        }
        expect.eq(db.count('202_attribution_conversion_values', "event_name='refused_probe'"), 0,
          'no rule was created');
      },
    },

    {
      name: 'The schema token reveals and hides',
      async run(ctx) {
        const { app, db, ui, expect, state } = ctx;
        await app.goto(PAGE + '?app=' + state.rowId);
        state.token = db.value('SELECT schema_token FROM 202_attribution_apps');

        const masked = await ui.text('#schema-token');
        expect.ok(masked.includes(DOT), 'it starts masked', masked.slice(0, 12));
        expect.notOk(masked.includes(state.token), 'the whole token is not on screen');

        expect.eq(await app.reveal('#schema-token'), state.token, 'Reveal shows the real token');
        expect.eq(await ui.text('[data-p202-reveal]'), 'Hide', 'and the button becomes Hide');
        expect.ok((await app.reveal('#schema-token')).includes(DOT), 'clicking again masks it');
      },
    },

    {
      name: 'Copy puts the real values on the clipboard',
      async run(ctx) {
        const { app, ui, expect, state } = ctx;
        expect.ok(await ui.count('[data-p202-copy]') >= 3,
          'the token, Info.plist and SDK snippet each copy');

        expect.eq(await app.copy('.p202-code button[data-p202-copy]'), state.token,
          'the token copies in full, not masked');
        expect.eq(await ui.text('.p202-code button[data-p202-copy]'), 'Copied', 'and the button says so');

        const buttons = await ui.page.$$('[data-p202-copy]');
        await buttons[1].click();
        const plist = await ui.until(async () => {
          const text = await ui.clipboard();
          return /NSAdvertisingAttributionReportEndpoint/.test(text) ? text : false;
        }, { describe: 'the Info.plist snippet to reach the clipboard' });
        expect.match(plist, new RegExp(ctx.config.host.replace('.', '\\.')),
          'Info.plist copies with this install origin');
      },
    },

    {
      name: 'Rotating the token asks first',
      async run(ctx) {
        const { app, db, expect, state } = ctx;

        const said = await app.confirmAnd('dismiss', 'button:has-text("Rotate")');
        expect.match(said, /Replace this schema token/, 'it warns what rotating costs',
          said || '(no dialog appeared)');
        expect.eq(db.value('SELECT schema_token FROM 202_attribution_apps'), state.token,
          'saying no changes nothing');

        await app.confirmAnd('accept', 'button:has-text("Rotate")');
        const rotated = db.value('SELECT schema_token FROM 202_attribution_apps');
        expect.ne(rotated, state.token, 'saying yes mints a new one');
        expect.eq(rotated.length, 64, 'of the same length');
        state.token = rotated;
      },
    },

    {
      name: 'Removing a rule asks first',
      async run(ctx) {
        const { app, db, expect } = ctx;
        const selector = 'tr:has-text("subscribed") button:has-text("remove"), tr:has-text("subscribed") a:has-text("remove")';
        const before = db.count('202_attribution_conversion_values', 'app_id=' + APP_ID);

        await app.confirmAnd('dismiss', selector);
        expect.eq(db.count('202_attribution_conversion_values', 'app_id=' + APP_ID), before,
          'saying no keeps the rule');

        await app.confirmAnd('accept', selector);
        expect.eq(db.count('202_attribution_conversion_values', 'app_id=' + APP_ID), before - 1,
          'saying yes removes it');
      },
    },

    {
      name: 'The development-postback nudge',
      async run(ctx) {
        const { app, db, ui, expect, shot } = ctx;
        db.write(
          'INSERT INTO 202_attribution_postbacks (user_id, received_at, protocol, version, ad_network_id,'
          + ' transaction_id, app_id, conversion_value, conversion_type, signature_state, signature_valid,'
          + ' dedupe_hash, attribution_signature, raw_payload, remote_ip, created_at, did_win) VALUES'
          + " (1, UNIX_TIMESTAMP(), 'skadnetwork', '4.0', 'acme.skadnetwork', 'txn-dev-1', " + APP_ID + ","
          + " 3, 'install', 'development', NULL, SHA1('dev1'), 'sig', '{}', '127.0.0.1', UNIX_TIMESTAMP(), 1),"
          + " (1, UNIX_TIMESTAMP(), 'skadnetwork', '4.0', 'acme.skadnetwork', 'txn-dev-2', " + APP_ID + ","
          + " 5, 'install', 'development', NULL, SHA1('dev2'), 'sig', '{}', '127.0.0.1', UNIX_TIMESTAMP(), 1)"
        );

        await app.goto(PAGE);
        expect.match(await ui.text('.alert-warning'), /2 development postbacks have arrived/,
          'it appears where the app is listed');
        expect.eq(db.value('SELECT accept_development_postbacks FROM 202_attribution_apps'), 0,
          'and they are not counted yet');
        await shot('nudge');

        await app.submit('button:has-text("Accept")');
        expect.eq(db.value('SELECT accept_development_postbacks FROM 202_attribution_apps'), 1,
          'one click accepts them');
        expect.notOk(await ui.visible('.alert-warning'), 'and the nudge is gone');
      },
    },

    {
      name: 'Editing the app',
      async run(ctx) {
        const { app, db, ui, expect } = ctx;
        await app.goto(PAGE);
        await app.submit('a:has-text("edit")');
        expect.eq(await ui.value('input[name="app_name"]'), 'Summit Run',
          'the edit form opens with the app in it');

        await ui.fill({ 'input[name="app_name"]': 'Summit Run Pro' });
        await app.submit('button:has-text("Save")');
        expect.eq(db.value('SELECT app_name FROM 202_attribution_apps'), 'Summit Run Pro',
          'the rename is saved');
      },
    },

    {
      name: 'Re-pasting a link for an app you already have',
      async run(ctx) {
        const { app, db, ui, expect } = ctx;
        await app.goto(PAGE);
        expect.match(await register(ctx, STORE_LINK), /already registered this app/,
          'it says you already have it');
        expect.eq(db.count('202_attribution_apps'), 1, 'and does not duplicate it');
        expect.match(ui.page.url(), /app=/, 'landing on the app itself');
      },
    },

    {
      name: 'An account in another currency',
      async run(ctx) {
        const { app, db, ui, expect, state } = ctx;
        // Put back in the runner's finally, even if this scenario throws.
        db.temporarily(
          "UPDATE 202_users_pref SET user_account_currency='EUR' WHERE user_id=1",
          "UPDATE 202_users_pref SET user_account_currency='USD' WHERE user_id=1"
        );

        await app.goto(PAGE + '?app=' + state.rowId);
        await ui.select('select[name="fine_value"]', 12);
        await ui.fill({ 'input[name="event_name"]': 'euro_rule', 'input[name="revenue"]': '19.95' });
        await app.submit('button:has-text("Add rule")');

        const amounts = (await ui.texts('td.num')).filter((t) => /\d/.test(t));
        expect.ok(amounts.some((a) => a.startsWith('€')), 'amounts render in euros',
          JSON.stringify(amounts.slice(0, 4)));
        expect.ok(!amounts.some((a) => a.startsWith('$')), 'and not in dollars',
          JSON.stringify(amounts.slice(0, 4)));
        expect.eq(await ui.text('.input-group-text'), '€', 'the field names euros too');
      },
    },

    {
      name: 'Read-only for a user who cannot manage apps',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        // 23 is manage_attribution_models; restored by the runner even on a throw.
        db.temporarily(
          'DELETE FROM 202_role_permission WHERE role_id=1 AND permission_id=23',
          'INSERT IGNORE INTO 202_role_permission (role_id, permission_id) VALUES (1, 23)'
        );

        await app.goto(PAGE);
        expect.notOk(await ui.exists('input[name="app_reference"]'),
          'the register form is not offered on the apps list');
        expect.notOk(await ui.exists('button:has-text("remove")'), 'and apps cannot be removed');

        await app.goto(PAGE + '?app=' + state.rowId);
        expect.notOk(await ui.exists('button:has-text("Add rule")'), 'rules cannot be added');
        expect.notOk(await ui.exists('button:has-text("Rotate")'), 'the token cannot be rotated');
        expect.ok(await ui.visible('text=Conversion values'), 'but the rules are still readable');

        db.restore();
        await app.goto(PAGE + '?app=' + state.rowId);
        expect.ok(await ui.exists('button:has-text("Add rule")'),
          'and the controls come back with the permission');
      },
    },

    {
      name: 'The component layer holds up on this page',
      async run(ctx) {
        const { app, state } = ctx;
        await app.goto(PAGE + '?app=' + state.rowId);
        await checks.componentClassesAreStyled(ctx, SCRIPT_ONLY_CLASSES);
        await checks.tablesScrollThemselves(ctx);
      },
    },

    {
      name: 'At phone width',
      async run(ctx) {
        const { app, ui, expect, state, shot } = ctx;
        await checks.atWidths(ctx, [400], async () => {
          await app.goto(PAGE + '?app=' + state.rowId);

          const layout = await ui.page.evaluate(() => {
            const value = document.querySelector('#schema-token');
            const row = value.closest('.p202-code');
            const buttons = Array.from(row.children).filter((child) => child !== value);
            const valueBox = value.getBoundingClientRect();
            return {
              valueWidth: Math.round(valueBox.width),
              rowWidth: Math.round(row.getBoundingClientRect().width),
              valueHeight: Math.round(valueBox.height),
              buttonsBelow: buttons.every((b) => b.getBoundingClientRect().top >= valueBox.bottom - 2),
            };
          });

          expect.ok(layout.valueWidth >= layout.rowWidth - 2, 'the token gets the full row',
            layout.valueWidth + ' of ' + layout.rowWidth);
          expect.ok(layout.valueHeight < 60, 'on one line, not a column of dots', layout.valueHeight + 'px');
          expect.ok(layout.buttonsBelow, 'with its buttons below it');
          await checks.tablesScrollThemselves(ctx);
          await shot('phone');
        });
      },
    },

    {
      name: 'In dark mode',
      async run(ctx) {
        const { state, withSession } = ctx;
        await withSession({ colorScheme: 'dark' }, async (dark) => {
          await dark.app.goto(PAGE + '?app=' + state.rowId);
          await checks.darkThemeApplies({ ...ctx, ui: dark.ui, page: dark.page });
          await dark.page.screenshot({
            path: require('path').join(ctx.config.shots, 'setup-mobile-apps-dark.png'),
            fullPage: true,
          });
        });
      },
    },

    {
      name: 'Removing the app asks first',
      async run(ctx) {
        const { app, db, expect } = ctx;
        await app.goto(PAGE);
        const selector = 'button:has-text("remove"), a:has-text("remove")';

        await app.confirmAnd('dismiss', selector);
        expect.eq(db.count('202_attribution_apps'), 1, 'saying no keeps the app');

        await app.confirmAnd('accept', selector);
        expect.eq(db.count('202_attribution_apps'), 0, 'saying yes removes it');
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
