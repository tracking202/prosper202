'use strict';

/*
 * The breakdown reads on the v2 pages (PR 1b of the measurement plan):
 *
 *  - Visitors and Spy: a click with conversions offers them on its row, and
 *    the button opens the click history's modal with the click's breakdown —
 *    every conversion, counted or not and why, adding up to the click's
 *    value — light and dark, at 1280px and 390px, with the component and
 *    flex checks run over the open modal;
 *  - Group Overview: choosing Transaction ID or Goal / source as the second
 *    grouping splits a campaign by its conversions without changing the
 *    campaign's figures, and says how in one line.
 *
 * The setup converts two of today's fixture clicks through the global
 * postback with fixed transaction ids (two sales on one click, so the first
 * is superseded in the replace campaigns seed.sh makes), so a second run
 * records nothing new. Figures are read from the database or the page, never
 * constants.
 */

const checks = require('../lib/checks');

const PREF_RESET = "UPDATE 202_users_pref SET user_pref_time_predefined='today', user_pref_time_from=NULL, user_pref_time_to=NULL,"
  + " user_pref_show='all', user_cpc_or_cpv='cpc', user_pref_limit=50,"
  + ' user_pref_ppc_network_id=NULL, user_pref_ppc_account_id=NULL, user_pref_aff_network_id=NULL, user_pref_aff_campaign_id=NULL,'
  + ' user_pref_text_ad_id=NULL, user_pref_landing_page_id=NULL, user_pref_country_id=NULL, user_pref_region_id=NULL,'
  + ' user_pref_isp_id=NULL, user_pref_device_id=NULL, user_pref_browser_id=NULL, user_pref_platform_id=NULL,'
  + ' user_pref_subid=NULL, user_pref_ip=NULL, user_pref_keyword=NULL, user_pref_referer=NULL,'
  + ' user_pref_group_1=4, user_pref_group_2=0, user_pref_group_3=0, user_pref_group_4=0 WHERE user_id=1';

/** A dollar figure as the pages print it (dollar_format), from a stored decimal. */
function dollars(value) {
  const n = Number(value);
  return (n < 0 ? '($' : '$') + n.toFixed(2) + (n < 0 ? ')' : '');
}

/** The rows of the breakdown the modal shows, by column heading. */
async function breakdownRows(ui) {
  return ui.page.evaluate(() => {
    const table = document.querySelector('#p202-click-conversions #click-conversions-table');
    if (!table) { return null; }
    const heads = Array.from(table.tHead.rows[0].cells).map((th) => (th.textContent || '').trim());
    return Array.from(table.tBodies[0].rows).map((tr) => {
      const out = { totals: tr.classList.contains('p202-table__totals') };
      // innerText, as a reader sees it: the pill and the sentence under it
      // are two lines, not one run-on word.
      Array.from(tr.cells).forEach((td, i) => { out[heads[i]] = (td.innerText || '').replace(/\s+/g, ' ').trim(); });
      return out;
    });
  });
}

async function openBreakdown(ui, clickId) {
  await ui.click('tr[data-click-id="' + clickId + '"] [data-p202-breakdown]');
  await ui.untilInPage((id) => {
    const modal = document.querySelector('#p202-click-conversions');
    const body = modal && modal.querySelector('[data-p202-breakdown-body]');
    return modal !== null && modal.classList.contains('show') && body.getAttribute('aria-busy') === 'false'
      && (modal.querySelector('.modal-title') || {}).textContent === 'Conversions on click ' + id;
  }, String(clickId), { describe: 'the breakdown of click ' + clickId + ' to open' });
}

async function closeBreakdown(ui) {
  await ui.click('#p202-click-conversions .btn-close');
  await ui.untilInPage(() => {
    const modal = document.querySelector('#p202-click-conversions');
    return modal === null || !modal.classList.contains('show');
  }, undefined, { describe: 'the breakdown to close' });
}

/** The group rows of a Group Overview table, with their depth and figures. */
async function groupRows(ui) {
  return ui.page.evaluate(() => {
    const table = document.querySelector('#group-overview-table');
    if (!table) { return null; }
    const heads = Array.from(table.tHead.rows[0].cells).map((th) => (th.textContent || '').trim().toLowerCase());
    return Array.from(table.tBodies[0].rows).map((tr) => {
      const label = tr.cells[0].querySelector('span');
      const out = { depth: label ? Math.round(parseFloat(label.style.paddingLeft || '0') / 1.25) : -1, totals: tr.classList.contains('p202-table__totals') };
      Array.from(tr.cells).forEach((td, i) => { out[heads[i]] = (td.textContent || '').trim(); });
      return out;
    });
  });
}

/** A campaign's row and the rows under it. */
function campaignGroup(rows, name) {
  const at = rows.findIndex((r) => r.depth === 0 && r.group === name);
  if (at < 0) { return null; }
  const out = [rows[at]];
  for (let i = at + 1; i < rows.length && rows[i].depth > 0; i++) { out.push(rows[i]); }
  return out;
}

module.exports = {
  name: 'click-breakdown',
  title: 'Click breakdown and Group Overview ledger levels',

  // The Visitors history lists 202_dataengine rows, newest first. Report rows
  // whose click is gone (a live pass that deleted its clicks and not their
  // report rows, on an instance other suites share) would push this spec's
  // clicks off the first page; a report row without its click is garbage.
  async reset(db) {
    db.write('DELETE d FROM 202_dataengine d LEFT JOIN 202_clicks c ON c.click_id = d.click_id WHERE c.click_id IS NULL');
  },

  async setup(ctx) {
    const { db, ui, config, app, state } = ctx;
    db.write(PREF_RESET);
    await app.login();
    const clicks = db.rows('SELECT c.click_id FROM 202_clicks c JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id'
      + " WHERE c.user_id=1 AND ac.payout_mode='replace' AND c.click_time >= UNIX_TIMESTAMP(CURDATE())"
      // Clicks nothing else has converted: another suite on the same
      // instance (the agent-eval events case) may have given the newest
      // click a conversion of its own, which this spec's figures do not know.
      + " AND NOT EXISTS (SELECT 1 FROM 202_conversion_logs l WHERE l.click_id = c.click_id AND (l.transaction_id IS NULL OR l.transaction_id NOT LIKE 'breakdown-spec-%'))"      + ' ORDER BY c.click_id DESC LIMIT 2').map((r) => String(r[0]));
    if (clicks.length < 2) {
      throw new Error('the fixture has ' + clicks.length + ' clicks today in a replace campaign; seed it (tests/fixtures/agent-eval/seed.sh) first');
    }
    for (const [click, amounts] of [[clicks[0], ['3', '4']], [clicks[1], ['6']]]) {
      for (let i = 0; i < amounts.length; i++) {
        const response = await ui.page.request.get(config.base + '/tracking202/static/gpb.php?subid=' + click + '&amount=' + amounts[i] + '&txid=breakdown-spec-' + click + '-' + (i + 1));
        if (response.status() >= 400) {
          throw new Error('the postback for click ' + click + ' answered ' + response.status());
        }
      }
    }
    state.two = clicks[0];
    state.one = clicks[1];
    state.campaign = db.value('SELECT ac.aff_campaign_name FROM 202_clicks c JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id WHERE c.click_id=' + clicks[0]);
  },

  scenarios: [
    {
      name: 'A Visitors row opens its click\'s conversions, which add up to the click, light and dark, desktop and phone',
      async run(ctx) {
        const { withSession, expect, db, state } = ctx;
        const rows = db.rows('SELECT conv_id, click_payout, transaction_id FROM 202_conversion_logs WHERE click_id=' + state.two + ' ORDER BY conv_id');
        const value = db.value('SELECT click_payout FROM 202_clicks WHERE click_id=' + state.two);
        for (const [width, scheme] of [[1280, 'light'], [1280, 'dark'], [390, 'light'], [390, 'dark']]) {
          await withSession({ viewport: { width, height: 900 }, colorScheme: scheme }, async (session) => {
            const sub = Object.assign({}, ctx, session);
            const { ui, app } = session;
            expect.section('Visitors at ' + width + 'px, ' + scheme);
            await app.goto('/tracking202/visitors/');
            await checks.overviewReportDrawn(ui, '#visitors-report');
            expect.eq(await ui.text('tr[data-click-id="' + state.two + '"] [data-p202-breakdown]'), rows.length + ' conversions', 'the row offers the click\'s conversions, counted or not');
            await openBreakdown(ui, state.two);
            const shown = await breakdownRows(ui);
            expect.ok(shown !== null, 'the breakdown table is drawn in the modal');
            const body = shown.filter((r) => !r.totals);
            expect.eq(body.map((r) => r.Conversion), rows.map((r) => '#' + r[0]), 'every conversion of the click, oldest first');
            expect.eq(body.map((r) => r['Transaction ID']), rows.map((r) => r[2]), 'with its transaction id');
            expect.eq(body.map((r) => r.Amount), rows.map((r) => dollars(r[1])), 'and its amount');
            expect.match(body[0]['In the value'], /^superseded · replace /, 'the first sale says it was replaced');
            expect.match(body[0]['In the value'], new RegExp('Replaced by conversion ' + rows[rows.length - 1][0] + '\\.'), 'and by which conversion');
            expect.eq(body[body.length - 1]['In the value'], 'counted', 'the latest counts');
            expect.eq(shown.filter((r) => r.totals).map((r) => r.Amount), [dollars(value)], 'the counted rows add up to the click\'s value');
            await checks.baseline(sub);
            await checks.componentClassesAreStyled(sub);
            await checks.flexContainersKeepTheirSpaces(sub);
            await checks.tablesScrollThemselves(sub);
            if (scheme === 'dark') {
              await checks.darkThemeApplies(sub);
            }
            await closeBreakdown(ui);
          });
        }
      },
    },

    {
      name: 'A second row\'s button shows that click, not the first one again',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        await app.goto('/tracking202/visitors/');
        await checks.overviewReportDrawn(ui, '#visitors-report');
        await openBreakdown(ui, state.two);
        await closeBreakdown(ui);
        await openBreakdown(ui, state.one);
        const shown = (await breakdownRows(ui)).filter((r) => !r.totals);
        expect.eq(shown.map((r) => r['Transaction ID']), db.rows('SELECT transaction_id FROM 202_conversion_logs WHERE click_id=' + state.one + ' ORDER BY conv_id').map((r) => r[0]),
          'the modal shows the second click\'s conversions');
        const unconverted = db.value('SELECT d.click_id FROM 202_dataengine d WHERE d.user_id=1 AND d.click_time >= UNIX_TIMESTAMP(CURDATE())'
          + ' AND NOT EXISTS (SELECT 1 FROM 202_conversion_logs cl WHERE cl.click_id = d.click_id) ORDER BY d.click_time DESC LIMIT 1');
        if (unconverted) {
          expect.notOk(await ui.exists('tr[data-click-id="' + unconverted + '"] [data-p202-breakdown]'), 'a click with no conversions offers none');
        }
        await closeBreakdown(ui);
        await checks.baseline(ctx);
      },
    },

    {
      name: 'Spy offers the same breakdown',
      async run(ctx) {
        const { app, ui, expect, state } = ctx;
        await app.goto('/tracking202/spy/');
        await checks.overviewReportDrawn(ui, '#spy-report');
        await openBreakdown(ui, state.two);
        expect.ok((await breakdownRows(ui)).some((r) => r.totals), 'the breakdown is drawn with its total');
        await closeBreakdown(ui);
        await checks.baseline(ctx);
      },
    },

    {
      name: 'Group Overview by Transaction ID and by Goal / source splits a campaign without changing it',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/overview/group-overview.php?group_1=4&group_2=0&range=today');
        await checks.overviewReportDrawn(ui, '#group-overview-report');
        const plain = campaignGroup(await groupRows(ui), state.campaign);
        expect.ok(plain !== null, 'the campaign is in the report grouped by campaign');
        expect.notOk(await ui.exists('[data-p202-ledger-note]'), 'no ledger note without a ledger level');

        await ui.select('#group-overview-filters-group_2', '35');
        await ui.clickThrough('#group-overview-filters button[type="submit"]');
        await checks.overviewReportDrawn(ui, '#group-overview-report');
        expect.eq(new URL(ui.page.url()).searchParams.get('group_2'), '35', 'the URL carries Transaction ID');
        const byTx = campaignGroup(await groupRows(ui), state.campaign);
        expect.eq(byTx[0], plain[0], 'the campaign\'s figures are unchanged');
        const tx = Object.fromEntries(byTx.slice(1).map((r) => [r.group, r.income]));
        expect.eq(tx['breakdown-spec-' + state.two + '-2'], dollars(4), 'the counted sale shows its own amount');
        expect.notOk('breakdown-spec-' + state.two + '-1' in tx, 'the superseded one adds nothing and has no row');
        expect.eq(tx['breakdown-spec-' + state.one + '-1'], dollars(6), 'the other click\'s sale is its own row');
        expect.ok(await ui.visible('[data-p202-ledger-note]'), 'the page says how a ledger level splits a click');

        await ui.select('#group-overview-filters-group_2', '37');
        await ui.clickThrough('#group-overview-filters button[type="submit"]');
        await checks.overviewReportDrawn(ui, '#group-overview-report');
        const bySource = campaignGroup(await groupRows(ui), state.campaign);
        expect.eq(bySource[0], plain[0], 'grouped by goal / source, the campaign\'s figures are unchanged too');
        expect.ok(bySource.slice(1).some((r) => r.group === 'Postback'), 'and its postback income has a Postback row', JSON.stringify(bySource.map((r) => r.group)));
        await checks.baseline(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);
        db.write(PREF_RESET);
      },
    },
  ],
};
