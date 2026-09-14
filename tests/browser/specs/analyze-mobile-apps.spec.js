'use strict';

/*
 * Analyze › Mobile Apps, driven the way a person drives it.
 *
 * tests/live/analyze-mobile-apps.sh checks what this page answers over HTTP —
 * the numbers against the database, every grouping, the filters, the CSV, the
 * three views. None of that is repeated here. What is here is what only a
 * rendering engine can answer:
 *
 *  - the range picker, whose whole behaviour is a change handler. The date
 *    inputs stay editable at every range — a field a person cannot type into
 *    is the wrong way to say "this is not the control you want" — but they
 *    are only SUBMITTED for a custom window, and typing in one selects
 *    Custom Date. Without JavaScript the same page is still correct but costs
 *    two round trips, so the thing being checked is the enhancement.
 *  - that one Apply is enough to get a custom window, which is the claim the
 *    handler exists to make.
 *  - layout: a ten-column table at phone width, tiles that wrap, and nothing
 *    that pushes the page sideways.
 *  - the copy button on a verify result, which is a clipboard write.
 *
 * The rows are seeded here rather than assumed, so the spec is runnable
 * against an install that has never received a postback.
 */

const path = require('path');
const checks = require('../lib/checks');

const PAGE = '/tracking202/analyze/mobile_apps.php';
const APP_ID = 990077001;
const OTHER_APP_ID = 990077002;
const DAY = 86400;

/** Midnight UTC today, the anchor every preset is measured from. */
function todayUtc() {
  return Math.floor(Date.now() / 1000 / DAY) * DAY;
}

/** A YYYY-MM-DD string in UTC, n whole days back. */
function utcDay(daysBack) {
  return new Date((todayUtc() - daysBack * DAY) * 1000).toISOString().slice(0, 10);
}

/**
 * One postback row. Written as SQL rather than through the receiver because
 * the receiver would reject an unsigned body, and the signature is not what
 * this spec is about.
 */
function postbackSql(n, daysBack, overrides) {
  const row = Object.assign({
    app_id: APP_ID,
    protocol: 'skadnetwork',
    version: '4.0',
    ad_network_id: 'acme.skadnetwork',
    conversion_type: 'download',
    did_win: 1,
    signature_state: 'valid',
    signature_valid: 1,
    country_code: 'US',
    source_identifier: '12',
    conversion_value: 3,
  }, overrides || {});

  const at = todayUtc() - daysBack * DAY + 3600 + n * 61;
  const quoted = (v) => (v === null ? 'NULL' : "'" + String(v).replace(/'/g, "''") + "'");

  return "INSERT INTO 202_attribution_postbacks "
    + '(user_id, received_at, protocol, version, ad_network_id, transaction_id, app_id, '
    + 'source_identifier, conversion_value, postback_sequence_index, conversion_type, '
    + 'redownload, did_win, country_code, attribution_signature, signature_state, '
    + 'signature_valid, dedupe_hash, raw_payload, remote_ip, created_at) VALUES ('
    + ['1', at, quoted(row.protocol), quoted(row.version), quoted(row.ad_network_id),
      quoted('browser-' + n), row.app_id, quoted(row.source_identifier),
      row.conversion_value === null ? 'NULL' : row.conversion_value, '0',
      quoted(row.conversion_type), '0', row.did_win, quoted(row.country_code),
      quoted('sig'), quoted(row.signature_state),
      row.signature_valid === null ? 'NULL' : row.signature_valid,
      "SHA1('browser-" + n + "')", quoted('{}'), quoted('198.51.100.7'), at].join(', ')
    + ')';
}

/**
 * What the range picker and its two date inputs currently are.
 *
 * Two separate questions: whether a field is EDITABLE, and whether it is
 * SUBMITTED. Without JavaScript the server answers both with `disabled`;
 * p202-ui.js separates them, leaving the field typeable and dropping its
 * `name` — which is what makes "type a date, get a custom range" possible
 * at all.
 */
async function rangeState(ui) {
  return ui.page.evaluate(() => {
    const select = document.querySelector('#range');
    const read = (el) => (el === null ? null : {
      editable: !el.disabled && !el.readOnly,
      submitted: el.hasAttribute('name'),
      value: el.value,
    });
    return {
      range: select ? select.value : null,
      from: read(document.querySelector('#from')),
      to: read(document.querySelector('#to')),
    };
  });
}

module.exports = {
  name: 'analyze-mobile-apps',
  title: 'Analyze › Mobile Apps',

  async reset(db) {
    db.truncate([
      '202_attribution_apps',
      '202_attribution_conversion_values',
      '202_attribution_postbacks',
    ]);
    db.write("UPDATE 202_users_pref SET user_account_currency='USD' WHERE user_id=1");

    const now = Math.floor(Date.now() / 1000);
    db.write("INSERT INTO 202_attribution_apps "
      + '(user_id, app_id, app_name, platform, accept_development_postbacks, schema_token, created_at, updated_at) VALUES '
      + "(1, " + APP_ID + ", 'Summit Run', 'ios', 0, 'browser-token-aaaaaaaaaaaaaaaaaaaaaaaa', " + now + ', ' + now + '), '
      + "(1, " + OTHER_APP_ID + ", 'Summit Racer', 'ios', 0, 'browser-token-bbbbbbbbbbbbbbbbbbbbbbbb', " + now + ', ' + now + ')');
    db.write('INSERT INTO 202_attribution_conversion_values '
      + '(user_id, app_id, fine_value, coarse_value, event_name, revenue, created_at, updated_at) VALUES '
      + "(1, " + APP_ID + ", 3, NULL, 'purchase', 4.99000, " + now + ', ' + now + ')');

    // Today, so every preset from Today upwards has something; and eight days
    // back, which only the longer presets and a custom window reach.
    db.write(postbackSql(1, 0, {}));
    db.write(postbackSql(2, 0, { conversion_type: 're-engagement', country_code: 'GB' }));
    db.write(postbackSql(3, 0, { app_id: OTHER_APP_ID, ad_network_id: 'beta.skadnetwork', did_win: 0 }));
    db.write(postbackSql(4, 1, { protocol: 'adattributionkit', version: '1.0', country_code: null }));
    db.write(postbackSql(5, 8, { signature_state: 'invalid', signature_valid: 0 }));
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Reaching the page the way a user does',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/tracking202/analyze/');
        await app.openFromSubMenu('Mobile Apps');

        expect.eq(new URL(ui.page.url()).pathname, PAGE, 'the Analyze strip leads to the page');
        expect.eq(await app.currentSubMenuItem(), 'Mobile Apps', 'and marks it as current');
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);
        await checks.currentSubMenuItemIsVisible(ctx);
        await checks.noLegacyClasses(ctx, ['col-xs-12', 'col-md-6', 'panel', 'panel-body', 'well', 'form-horizontal']);
      },
    },

    {
      name: 'The three views are three tabs',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE);

        expect.eq(await ui.texts('.p202-tabs .nav-link'), ['Report', 'Postbacks', 'Verify'],
          'the tabs are Report, Postbacks and Verify');
        expect.eq(await ui.attr('.p202-tabs .nav-link.active', 'aria-current'), 'page',
          'the current tab says so to a screen reader');

        await ui.clickThrough('.p202-tabs .nav-link:has-text("Postbacks")');
        expect.eq(new URL(ui.page.url()).searchParams.get('view'), 'postbacks', 'Postbacks opens its view');
        expect.eq(await ui.text('.p202-tabs .nav-link.active'), 'Postbacks', 'and becomes the current tab');

        await ui.clickThrough('.p202-tabs .nav-link:has-text("Verify")');
        expect.ok(await ui.exists('textarea[name="payload"]'), 'Verify opens a place to paste a postback');
        expect.notOk(await ui.exists('#range'),
          'and drops the range filters, which mean nothing to a signature check');
      },
    },

    {
      name: 'The range picker decides whether the dates are live',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE);

        let state = await rangeState(ui);
        expect.eq(state.range, 'last30', 'the page opens on Last 30 Days');
        expect.ok(!state.from.submitted && !state.to.submitted,
          'the date inputs are withheld from the request while a preset is selected');
        expect.ok(state.from.editable && state.to.editable,
          'but they stay editable, which is what lets typing in one mean anything');
        expect.notOk(await ui.visible('[data-p202-range-hint]'),
          'and the line telling a no-JavaScript reader to use the picker is not shown');
        expect.eq(state.from.value, utcDay(30), 'and show the window that is being reported');
        expect.eq(state.to.value, utcDay(0), 'up to today');

        await ui.select('#range', 'custom');
        state = await rangeState(ui);
        expect.ok(state.from.submitted && state.to.submitted,
          'choosing Custom Date puts them in the request at once, without a round trip');

        await ui.select('#range', 'last7');
        state = await rangeState(ui);
        expect.ok(!state.from.submitted, 'and choosing a preset again withdraws them');

        // The other half of the handler, driven the way a person drives it:
        // no DOM surgery. An earlier version of this spec re-enabled the
        // field itself before dispatching a synthetic event, which is why it
        // passed while the branch could not fire at all (error pattern #9).
        await ui.fill({ '#to': utcDay(3) });
        state = await rangeState(ui);
        expect.eq(state.range, 'custom', 'typing in a date selects Custom Date');
        expect.ok(state.from.submitted && state.to.submitted,
          'and puts the pair in the request');
        expect.eq(state.to.value, utcDay(3), 'keeping what was typed');
      },
    },

    {
      name: 'One Apply is enough for a custom window',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE);

        // Nine days back reaches the eighth-day row that Last 7 Days does not.
        await ui.select('#range', 'custom');
        await ui.fill({ '#from': utcDay(9), '#to': utcDay(0) });
        await ui.clickThrough('.p202-table-toolbar button:has-text("Apply")');

        const url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('range'), 'custom', 'the window travels in the URL');
        expect.eq(url.searchParams.get('from'), utcDay(9), 'with the start that was picked');
        expect.eq(await ui.text('.p202-tile:has-text("Postbacks") .p202-tile__value'), '5',
          'and the report counts all five seeded postbacks');

        const state = await rangeState(ui);
        expect.eq(state.range, 'custom', 'the picker comes back on Custom Date');
        expect.ok(state.from.editable && state.to.editable, 'with the dates still editable');
        expect.ok(state.from.submitted && state.to.submitted,
          'and now submitted, because a custom window is what they describe');

        await ui.select('#range', 'last7');
        await ui.clickThrough('.p202-table-toolbar button:has-text("Apply")');
        expect.eq(new URL(ui.page.url()).searchParams.get('from'), null,
          'and switching back to a preset leaves the dates behind');
        expect.eq(await ui.text('.p202-tile:has-text("Postbacks") .p202-tile__value'), '4',
          'which is a different four-postback window');
      },
    },

    {
      name: 'Grouping and filters survive each other',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE + '?range=last30');

        await ui.select('#app_id', String(OTHER_APP_ID));
        await ui.clickThrough('.p202-table-toolbar button:has-text("Apply")');
        expect.eq(await ui.text('.p202-tile:has-text("Postbacks") .p202-tile__value'), '1',
          'the app filter narrows the report');

        await ui.clickThrough('.p202-pill:has-text("Country")');
        const url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('group_by'), 'country', 'a grouping pill regroups');
        expect.eq(url.searchParams.get('app_id'), String(OTHER_APP_ID), 'and keeps the app filter');
        expect.eq(await ui.value('#app_id'), String(OTHER_APP_ID), 'which the toolbar still shows');
        expect.ok(await ui.exists('.p202-pill--accent:has-text("Country")'),
          'and the pill for the current grouping is the accented one');

        // The pill shape is the affordance; a browser's default link
        // underline on top of it makes the row read as prose.
        const pill = await ui.computed('a.p202-pill', ['text-decoration-line']);
        expect.eq(pill['text-decoration-line'], 'none', 'a grouping pill is not underlined');
      },
    },

    {
      name: 'Verify checks a signature and hands back the bytes',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE + '?view=verify');

        await ui.fill({
          'textarea[name="payload"]': JSON.stringify({
            version: '4.0',
            'ad-network-id': 'acme.skadnetwork',
            'source-identifier': '12',
            'app-id': APP_ID,
            'transaction-id': '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28',
            'attribution-signature': 'MEQCIEQlmZRNfYzK',
            redownload: false,
            'fidelity-type': 1,
            'did-win': true,
            'postback-sequence-index': 0,
          }),
        });
        await app.submit('button:has-text("Check signature")');

        expect.eq(await ui.text('.p202-strip__row .p202-pill'), 'invalid',
          'a signature that is not Apple\'s reads invalid');
        expect.ok(await ui.visible('text=Signed message'), 'and the bytes Apple signs are offered');

        const shown = await ui.text('.p202-code__value');
        await app.copy('.p202-copy');
        expect.eq((await ui.clipboard()).trim(), shown.trim(),
          'Copy puts exactly those bytes on the clipboard');

        await ui.fill({ 'textarea[name="payload"]': '{ this is not json' });
        await app.submit('button:has-text("Check signature")');
        expect.match(await app.messages(), /not JSON: Syntax error/i,
          'malformed JSON is refused with the parser\'s own reason');
        expect.notOk(await ui.exists('.p202-strip__row'),
          'and no verdict is invented for it');
      },
    },

    {
      name: 'A ten-column table at phone width',
      async run(ctx) {
        const { app, ui, expect, shot } = ctx;

        // The markup is server-rendered and width-independent, so each URL is
        // loaded once per sweep rather than once per width; setViewport is
        // what re-lays it out.
        await app.goto(PAGE + '?range=last30');
        await checks.atWidths(ctx, [1280, 900, 400], async (width) => {
          await checks.tablesScrollThemselves(ctx);

          const tiles = await ui.page.evaluate(() => {
            const boxes = Array.from(document.querySelectorAll('.p202-tile'))
              .map((el) => el.getBoundingClientRect());
            const rows = new Set(boxes.map((b) => Math.round(b.top)));
            return { count: boxes.length, rows: rows.size, widest: Math.round(Math.max(...boxes.map((b) => b.width))) };
          });
          expect.eq(tiles.count, 6, 'all six tiles are on the page');
          expect.ok(tiles.widest <= width, 'and none is wider than the screen', String(tiles.widest));
          if (width === 400) {
            expect.ok(tiles.rows > 1, 'the tiles wrap onto more than one row on a phone', String(tiles.rows));
          }

        });

        await app.goto(PAGE + '?view=postbacks&range=last30');
        await checks.atWidths(ctx, [1280, 400], async () => {
          await checks.tablesScrollThemselves(ctx);
          expect.eq(await ui.count('table.p202-table thead th'), 10, 'the postbacks table keeps all ten columns');
        });

        await app.goto(PAGE + '?range=last30');
        await shot('report');
        await app.goto(PAGE + '?view=postbacks&range=last30');
        await shot('postbacks');
      },
    },

    {
      name: 'Nothing broke along the way',
      async run(ctx) {
        const { session, expect } = ctx;
        // checks.baseline() is scoped to the page it runs on, so without this
        // the seven scenarios above — the range picker, the tabs, Copy, the
        // CSV link — would have no error assertion at all.
        expect.ok(session.errors.length === 0,
          'no JavaScript errors during the whole pass', session.errors.slice(0, 3).join(' | '));
        expect.ok(session.unexpectedDialogs.length === 0,
          'and no confirm appeared anywhere', session.unexpectedDialogs.join(' | '));
      },
    },

    {
      name: 'Dark mode',
      async run(ctx) {
        const { withSession } = ctx;
        await withSession({ colorScheme: 'dark' }, async (dark) => {
          await dark.app.goto(PAGE + '?range=last30');
          await checks.darkThemeApplies({ ...ctx, ui: dark.ui, page: dark.page });
          await dark.page.screenshot({
            path: path.join(ctx.config.shots, 'analyze-mobile-apps-dark.png'),
            fullPage: true,
          });
        });
      },
    },
  ],
};
