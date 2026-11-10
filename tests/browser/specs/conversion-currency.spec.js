'use strict';

/*
 * Each conversion keeps the currency it was recorded in, on the pages:
 *
 *  - Personal settings › Account currency says what a change does to the
 *    conversions already recorded before anything is saved, offers to
 *    record one currency for conversions that have none, and says what a
 *    change did after it;
 *  - a report whose period holds revenue in more than one currency says so
 *    above its figures and in its chart, and the conversion log shows each
 *    amount in its own currency; with one currency neither says anything.
 *
 * Light and dark, at 1280x800 and 390x844. The spec brings its own account
 * state: an account that counts in EUR with three conversions on one
 * campaign in the last hour, one in EUR, one in USD and one with no
 * currency recorded. It needs no exchange rate: the campaign's payout is 0
 * for the currency change, so nothing is asked of the rate service.
 */

const fs = require('fs');
const path = require('path');
const checks = require('../lib/checks');

/** A full-page screenshot of a second session's page (ctx.shot() is the first session's). */
async function shoot(ctx, session, label) {
  fs.mkdirSync(ctx.config.shots, { recursive: true });
  await session.page.screenshot({ path: path.join(ctx.config.shots, 'conversion-currency-' + label + '.png'), fullPage: true });
}

const VIEWS = [[1280, 800, 'light'], [1280, 800, 'dark'], [390, 844, 'light'], [390, 844, 'dark']];
const BEFORE_SAVING = 'Conversions already recorded are not converted: each keeps the currency it was recorded in';
const NOTICE = /^Not all revenue in this period is in EUR\. Your account also recorded USD 12\.50 \(1 conversion\), 5\.00 with no currency recorded \(1 conversion\)\./;

/** The three conversions, as the scenarios find them. */
function seed(db) {
  // Fixture rows leave out the columns the tracking code fills.
  const write = (sql) => db.write("SET SESSION sql_mode=''; " + sql);
  write("UPDATE 202_users_pref SET user_account_currency='EUR', user_pref_time_predefined='last7', user_pref_time_from=NULL, user_pref_time_to=NULL WHERE user_id=1");
  write('DELETE FROM 202_conversion_logs WHERE user_id=1');
  write('DELETE FROM 202_dataengine WHERE user_id=1');
  write('DELETE FROM 202_clicks WHERE user_id=1');
  write('DELETE FROM 202_clicks_spy WHERE user_id=1');
  write('DELETE FROM 202_click_latest_visits WHERE click_id IN (501, 502, 503)');
  write('DELETE FROM 202_exchange_rates');
  write('DELETE FROM 202_aff_campaigns WHERE user_id=1');
  write('DELETE FROM 202_aff_networks WHERE user_id=1');
  write("INSERT INTO 202_aff_networks SET aff_network_id=1, user_id=1, aff_network_name='Store', aff_network_time=1");
  write("INSERT INTO 202_aff_campaigns SET aff_campaign_id=1, user_id=1, aff_network_id=1, aff_campaign_name='Coffee subscriptions',"
    + " aff_campaign_url='https://shop.example/coffee', aff_campaign_payout=0, aff_campaign_time=1, aff_campaign_foreign_payout=0,"
    + " aff_campaign_currency='EUR', payout_mode='accumulate', aff_campaign_id_public=4101");
  [[501, '20.00', 'EUR', 'ORD-1001'], [502, '12.50', 'USD', 'ORD-1002'], [503, '5.00', '', 'ORD-1003']].forEach(([click, amount, currency, tx], i) => {
    const t = 'UNIX_TIMESTAMP() - 3600 - ' + (i * 600);
    ['202_clicks', '202_clicks_spy'].forEach((table) => {
      write('INSERT INTO ' + table + ' SET click_id=' + click + ', user_id=1, aff_campaign_id=1, ppc_account_id=0, click_payout=' + amount
        + ', click_cpc=0.10, click_lead=1, click_filtered=0, click_bot=0, click_alp=0, click_time=' + t);
    });
    write('INSERT INTO 202_click_latest_visits SET click_id=' + click + ', click_time=' + t);
    write('INSERT INTO 202_dataengine SET user_id=1, click_id=' + click + ', click_time=' + t
      + ', aff_campaign_id=1, aff_network_id=1, clicks=1, click_out=1, leads=1, click_lead=1, income=' + amount + ', cost=0.10, payout=0');
    write('INSERT INTO 202_conversion_logs SET click_id=' + click + ", transaction_id='" + tx + "', campaign_id=1, click_payout=" + amount
      + ', user_id=1, click_time=' + t + ', conv_time=' + t + " + 300, time_difference='5 minutes', ip='203.0.113.7', pixel_type=2,"
      + " user_agent='network-postback', source='postback', dedupe_key='tx:" + tx + "', match_method='click_id', currency='" + currency + "'");
  });
}

/** What the Account currency panel says, as a reader sees it. */
async function currencyPanel(ui) {
  return ui.page.evaluate(() => {
    const panel = document.querySelector('#currency');
    if (!panel) { return null; }
    const text = (el) => (el ? (el.innerText || '').replace(/\s+/g, ' ').trim() : null);
    const record = panel.querySelector('form input[name="record_unrecorded_currency"]');
    const form = record ? record.closest('form') : null;
    return {
      help: text(panel.querySelector('form .form-text')),
      account: (panel.querySelector('#account_currency') || {}).value || null,
      offer: form ? text(form.querySelector('label')) : null,
      offerHelp: form ? text(form.querySelector('.form-text')) : null,
      offerButton: form ? text(form.querySelector('button[type="submit"]')) : null,
      offerChoice: form ? form.querySelector('select').value : null,
      flashes: Array.from(panel.querySelectorAll('.p202-flash__body')).map(text),
    };
  });
}

/**
 * Whether the element comes to rest inside the window, where the page
 * landed: the browser scrolls to the fragment, and p202-ui.js moves the
 * flashes into the panel, a moment after the load.
 */
async function onScreen(ui, selector) {
  try {
    await ui.untilInPage((sel) => {
      const el = document.querySelector(sel);
      if (!el) { return false; }
      const box = el.getBoundingClientRect();
      return box.height > 0 && box.top >= 0 && box.top < window.innerHeight;
    }, selector, { describe: selector + ' to be on screen' });
    return true;
  } catch (error) {
    return false;
  }
}

/** The Highcharts subtitle the Overview's chart draws, '' when it has none. */
async function chartSubtitle(ui) {
  return ui.page.evaluate(() => {
    const el = document.querySelector('#overview-chart .highcharts-subtitle');
    // Where Highcharts wraps a line it puts a zero-width space for the space.
    return el ? (el.textContent || '').replace(/[\s\u200b]+/g, ' ').trim() : '';
  });
}

/** The conversion log's Payout cells by transaction id, as a reader sees them. */
async function logAmounts(ui) {
  return ui.page.evaluate(() => {
    const table = document.querySelector('#conversion-logs-table');
    if (!table) { return null; }
    const heads = Array.from(table.tHead.rows[0].cells).map((th) => (th.textContent || '').trim());
    const tx = heads.indexOf('Transaction ID');
    const payout = heads.indexOf('Payout');
    const out = {};
    Array.from(table.tBodies[0].rows).forEach((tr) => {
      out[(tr.cells[tx].innerText || '').trim()] = (tr.cells[payout].innerText || '').replace(/\s+/g, ' ').trim();
    });
    return out;
  });
}

module.exports = {
  name: 'conversion-currency',
  title: 'Conversion currency: Personal settings, the reports and the conversion log',
  replacesFixture: true,

  async reset(db) {
    seed(db);
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Personal settings says what a change of currency does before it is saved, and offers to record one for conversions that have none',
      async run(ctx) {
        const { withSession, expect, db } = ctx;
        seed(db);
        for (const [width, height, scheme] of VIEWS) {
          await withSession({ viewport: { width, height }, colorScheme: scheme }, async (session) => {
            const sub = Object.assign({}, ctx, session);
            const { ui, app } = session;
            expect.section('Personal settings at ' + width + 'x' + height + ', ' + scheme);
            await app.goto('/202-account/account.php#currency');
            const panel = await currencyPanel(ui);
            expect.ok(panel !== null, 'the Account currency panel is on the page');
            expect.eq(panel.account, 'EUR', 'the account\'s currency is the one selected');
            expect.ok(String(panel.help).includes(BEFORE_SAVING), 'what a change does to earlier conversions is said before saving', String(panel.help));
            expect.ok(String(panel.help).includes('new ones are recorded in the new currency'), 'and what it does to new ones', String(panel.help));
            expect.eq(panel.offer, '1 earlier conversion has no currency recorded', 'the conversions with no currency are counted');
            expect.eq(panel.offerButton, 'Record this currency', 'with a control to record one');
            expect.eq(panel.offerChoice, '', 'which chooses no currency for the owner');
            expect.ok(String(panel.offerHelp).includes('If they were all recorded in one currency, choose it'), 'and why it is the owner\'s to say', String(panel.offerHelp));
            expect.ok(await onScreen(ui, '#currency'), 'the page lands on the panel');
            const select = await ui.box('#earlier_currency');
            const button = await ui.box('#currency form + form button[type="submit"]');
            expect.ok(select !== null && button !== null && select.width >= 80, 'the currency select keeps a usable width beside its button', JSON.stringify(select));
            await checks.baseline(sub);
            await checks.componentClassesAreStyled(sub);
            await checks.flexContainersKeepTheirSpaces(sub);
            if (scheme === 'dark') { await checks.darkThemeApplies(sub); }
            await shoot(ctx, session, 'currency-settings-' + width + '-' + scheme);
          });
        }
      },
    },
    {
      name: 'A report and its chart say how much of the period is in each other currency, the conversion log shows each amount in its own, and one currency says nothing',
      async run(ctx) {
        const { withSession, expect, db } = ctx;
        seed(db);
        for (const [width, height, scheme] of VIEWS) {
          await withSession({ viewport: { width, height }, colorScheme: scheme }, async (session) => {
            const sub = Object.assign({}, ctx, session);
            const { ui, app } = session;
            db.write("UPDATE 202_conversion_logs SET currency='USD' WHERE transaction_id='ORD-1002'");
            db.write("UPDATE 202_conversion_logs SET currency='' WHERE transaction_id='ORD-1003'");

            expect.section('Campaign Overview at ' + width + 'x' + height + ', ' + scheme + ', three currencies');
            await app.goto('/tracking202/overview/');
            await checks.overviewReportDrawn(ui, '#overview-report');
            const notices = await ui.texts('#overview-report .alert-warning .p202-flash__body');
            expect.eq(notices.length, 1, 'one notice above the figures', notices.join(' | '));
            expect.match(notices[0] || '', NOTICE, 'it says how much is in each other currency');
            expect.ok(String(notices[0] || '').includes('nothing converts them to EUR'), 'and that the totals add them as recorded', String(notices[0] || ''));
            expect.eq(await chartSubtitle(ui), 'Money lines add EUR and, unconverted: USD 12.50; 5.00 unrecorded', 'the chart says it in itself');
            const plot = await ui.box('#overview-chart .highcharts-plot-background');
            expect.ok(plot !== null && plot.height >= 100, 'and the note leaves the plot its room', JSON.stringify(plot));
            await checks.overviewPageBaseline(sub, checks.OVERVIEW_FAMILY_PAGES[0], { dark: scheme === 'dark' });
            await shoot(ctx, session, 'currency-overview-mixed-' + width + '-' + scheme);

            expect.section('Breakdown Analysis at ' + width + 'x' + height + ', ' + scheme);
            await app.goto('/tracking202/overview/breakdown.php');
            await checks.overviewReportDrawn(ui, '#breakdown-report');
            expect.match((await ui.texts('#breakdown-report .alert-warning .p202-flash__body'))[0] || '', NOTICE, 'the same notice above the breakdown');
            await checks.overviewPageBaseline(sub, checks.OVERVIEW_FAMILY_PAGES[1], { dark: scheme === 'dark' });

            expect.section('Conversion log at ' + width + 'x' + height + ', ' + scheme);
            await app.goto('/tracking202/logs/?range=last7');
            const amounts = await logAmounts(ui);
            expect.ok(amounts !== null, 'the conversion log is drawn');
            expect.eq(amounts['ORD-1001'], '€20.00', 'an amount in the account\'s currency stands alone');
            expect.eq(amounts['ORD-1002'], '$12.50 in USD, not converted to EUR', 'one in another currency is shown in it, and says so');
            expect.eq(amounts['ORD-1003'], '5.00 currency not recorded', 'one with none recorded shows a bare number, and says so');
            await checks.baseline(sub);
            await checks.componentClassesAreStyled(sub);
            await checks.flexContainersKeepTheirSpaces(sub);
            await checks.tablesScrollThemselves(sub);
            if (scheme === 'dark') { await checks.darkThemeApplies(sub); }
            await shoot(ctx, session, 'currency-log-mixed-' + width + '-' + scheme);

            expect.section('One currency at ' + width + 'x' + height + ', ' + scheme);
            db.write("UPDATE 202_conversion_logs SET currency='EUR' WHERE user_id=1");
            await app.goto('/tracking202/overview/');
            await checks.overviewReportDrawn(ui, '#overview-report');
            expect.eq(await ui.texts('#overview-report .alert-warning'), [], 'no notice when every amount is in the account\'s currency');
            expect.eq(await chartSubtitle(ui), '', 'and no chart subtitle');
            await app.goto('/tracking202/logs/?range=last7');
            const single = await logAmounts(ui);
            expect.eq([single['ORD-1001'], single['ORD-1002'], single['ORD-1003']], ['€20.00', '€12.50', '€5.00'], 'the log shows each amount alone');
            await shoot(ctx, session, 'currency-log-single-' + width + '-' + scheme);
          });
        }
      },
    },
    {
      name: 'Recording a currency for the conversions that have none, and changing the account\'s, each say what they did where the page lands',
      async run(ctx) {
        const { withSession, expect, db } = ctx;
        seed(db);
        for (const [width, height] of [[1280, 800], [390, 844]]) {
          db.write("UPDATE 202_conversion_logs SET currency='' WHERE transaction_id='ORD-1003'");
          db.write("UPDATE 202_users_pref SET user_account_currency='EUR' WHERE user_id=1");
          await withSession({ viewport: { width, height } }, async (session) => {
            const { ui, app } = session;
            expect.section('Recording a currency at ' + width + 'x' + height);
            await app.goto('/202-account/account.php#currency');
            await ui.select('#earlier_currency', 'USD');
            await app.submit('#currency form + form button[type="submit"]');
            let panel = await currencyPanel(ui);
            expect.eq(panel.flashes, ['1 earlier conversion is now recorded in USD.'], 'the panel says what was recorded');
            expect.ok(await onScreen(ui, '#currency .p202-flash__body'), 'where the page lands');
            expect.eq(panel.offer, null, 'and the offer is gone: no conversion is without a currency');
            expect.eq(db.value("SELECT currency FROM 202_conversion_logs WHERE transaction_id='ORD-1003'"), 'USD', 'the conversion holds it');
            expect.eq(db.value("SELECT currency FROM 202_conversion_logs WHERE transaction_id='ORD-1001'"), 'EUR', 'and one that had a currency keeps it');

            expect.section('Changing the account\'s currency at ' + width + 'x' + height);
            await ui.select('#account_currency', 'GBP');
            await app.submit('#currency form button[type="submit"]');
            panel = await currencyPanel(ui);
            expect.eq(panel.flashes, ['Account currency saved. New conversions are recorded in GBP. The conversions already recorded stay in EUR,'
              + ' unconverted: reports say how much of a period is in each currency.'], 'the panel says what the change did to earlier conversions');
            expect.ok(await onScreen(ui, '#currency .p202-flash__body'), 'where the page lands');
            expect.eq(panel.account, 'GBP', 'the new currency is the one selected');
            expect.eq(db.value('SELECT user_account_currency FROM 202_users_pref WHERE user_id=1'), 'GBP', 'and stored');
            expect.eq(db.rows("SELECT currency FROM 202_conversion_logs WHERE user_id=1 ORDER BY transaction_id").map((r) => r[0]), ['EUR', 'USD', 'USD'],
              'no conversion was relabelled');
            await shoot(ctx, session, 'currency-changed-' + width);
          });
        }
        seed(db);
      },
    },
  ],
};
