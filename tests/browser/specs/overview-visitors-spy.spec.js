'use strict';

/*
 * Overview, Visitors and Spy on the v2 shell (U2 of the UI migration).
 *
 * Eight pages, one recipe (202-config/functions-ui-overview.php): a GET filter
 * form whose values are applied to 202_users_pref as the page loads, and a
 * report panel that 202-js/p202-overview.js fills from an AJAX fragment which
 * reads those preferences. tests/Report/ReportPrefsTest pins how a request
 * becomes stored filters; what is here is what only a browser can answer:
 *
 *  - every page, light and dark, at 1280px and 390px: the shell, no script
 *    error, no legacy class, every class styled, no flex container eating
 *    its spaces, the current sub-menu entry on screen, and the report panel
 *    actually drawn (checks.OVERVIEW_FAMILY_PAGES);
 *  - that a filter applied in the form reaches the report — the figures
 *    change, the URL says so, the stored preference says so — and that a
 *    link carrying filters is those filters;
 *  - that a refused value is refused positively: its sentence under the
 *    field, the report not drawn, nothing stored;
 *  - the Advanced disclosure, the page links of Visitors, both downloads,
 *    the chart and its builder, the rotator's rule details, and Spy putting
 *    a new click on top of the table without a reload.
 *
 * It runs against the seeded agent-eval fixture (tests/fixtures/agent-eval/
 * seed.sh) and asserts figures read from the database or from the page
 * itself before the change, never constants, so it holds on any data that
 * has clicks today. It writes only report preferences and the chart setup,
 * and puts both back.
 */

const checks = require('../lib/checks');

const PREF_RESET = "UPDATE 202_users_pref SET user_pref_time_predefined='today', user_pref_time_from=NULL, user_pref_time_to=NULL,"
  + " user_pref_show='all', user_cpc_or_cpv='cpc', user_pref_limit=50, user_pref_breakdown='day',"
  + " user_pref_ppc_network_id=NULL, user_pref_ppc_account_id=NULL, user_pref_aff_network_id=NULL, user_pref_aff_campaign_id=NULL,"
  + " user_pref_text_ad_id=NULL, user_pref_landing_page_id=NULL, user_pref_method_of_promotion=NULL,"
  + " user_pref_country_id=NULL, user_pref_region_id=NULL, user_pref_isp_id=NULL, user_pref_device_id=NULL,"
  + " user_pref_browser_id=NULL, user_pref_platform_id=NULL, user_pref_subid=NULL, user_pref_ip=NULL,"
  + " user_pref_keyword=NULL, user_pref_referer=NULL, user_pref_group_1=1, user_pref_group_2=0,"
  + ' user_pref_group_3=0, user_pref_group_4=0 WHERE user_id=1';

function pref(db, column) {
  return db.value('SELECT IFNULL(' + column + ", '<NULL>') FROM 202_users_pref WHERE user_id=1");
}

/** The cells of a table's totals row, by column heading. */
async function totals(ui, tableSelector) {
  return ui.page.evaluate((sel) => {
    const table = document.querySelector(sel);
    if (!table) { return null; }
    const heads = Array.from(table.tHead.rows[0].cells).map((th) => (th.textContent || '').trim().toLowerCase());
    const row = table.querySelector('tr.p202-table__totals');
    if (!row) { return null; }
    const out = {};
    Array.from(row.cells).forEach((td, i) => { out[heads[i]] = (td.textContent || '').trim(); });
    return out;
  }, tableSelector);
}

async function applyFilters(ui, formId) {
  await ui.clickThrough('#' + formId + ' button[type="submit"]');
}

module.exports = {
  name: 'overview-visitors-spy',
  title: 'Overview, Visitors and Spy',

  async setup(ctx) {
    ctx.db.write(PREF_RESET);
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Every page of the family meets the baseline, light and dark, desktop and phone',
      async run(ctx) {
        const { withSession, expect } = ctx;
        for (const [width, scheme] of [[1280, 'light'], [1280, 'dark'], [390, 'light'], [390, 'dark']]) {
          await withSession({ viewport: { width, height: 900 }, colorScheme: scheme }, async (session) => {
            const sub = Object.assign({}, ctx, session);
            for (const entry of checks.OVERVIEW_FAMILY_PAGES) {
              expect.section(entry.path + ' at ' + width + 'px, ' + scheme);
              await session.app.goto(entry.path);
              await checks.overviewPageBaseline(sub, entry, { dark: scheme === 'dark' });
            }
          });
        }
      },
    },

    {
      name: 'The Overview strip reaches its pages and marks the current one',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/tracking202/overview/');
        await app.openFromSubMenu('Day Parting');
        expect.eq(new URL(ui.page.url()).pathname, '/tracking202/overview/day-parting.php', 'Day Parting opens its page');
        expect.eq(await app.currentSubMenuItem(), 'Day Parting', 'and is the current entry');
        await checks.overviewReportDrawn(ui, '#day-parting-report');
        expect.ok(await ui.exists('#day-parting-table'), 'the hourly table is drawn');
      },
    },

    {
      name: 'A filter applied in the form changes the report, the URL and the stored preference',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/overview/breakdown.php');
        await checks.overviewReportDrawn(ui, '#breakdown-report');
        const before = await totals(ui, '#breakdown-table');
        expect.ok(before !== null, 'the breakdown has a totals row to compare', JSON.stringify(before));
        const leads = Number((before.leads || '0').replace(/,/g, ''));
        expect.ok(leads > 0 && leads < Number(before.clicks.replace(/,/g, '')), 'the fixture has some converted clicks, and some not', JSON.stringify(before));

        await ui.select('#breakdown-filters-user_pref_show', 'leads');
        await applyFilters(ui, 'breakdown-filters');
        await checks.overviewReportDrawn(ui, '#breakdown-report');

        expect.eq(new URL(ui.page.url()).searchParams.get('user_pref_show'), 'leads', 'the URL carries the filter');
        expect.eq(pref(db, 'user_pref_show'), 'leads', 'the stored preference is the one the fragment reads');
        const after = await totals(ui, '#breakdown-table');
        expect.eq(after.clicks, String(leads), 'the report now counts only converted clicks: as many as there were leads');
        expect.eq(await ui.value('#breakdown-filters-user_pref_show'), 'leads', 'and the form still says so');

        await ui.clickThrough('#breakdown-filters a:has-text("Reset")');
        await checks.overviewReportDrawn(ui, '#breakdown-report');
        expect.eq(pref(db, 'user_pref_show'), 'all', 'Reset puts every filter back to its default');
        expect.eq((await totals(ui, '#breakdown-table')).clicks, before.clicks, 'and the report back to what it was');
      },
    },

    {
      name: 'A link is its filters, and typing a date chooses a custom window',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/overview/week-parting.php?range=yesterday&user_pref_show=real');
        await checks.overviewReportDrawn(ui, '#week-parting-report');
        expect.eq(await ui.value('#week-parting-filters-range'), 'yesterday', 'the range the link names is selected');
        expect.eq(pref(db, 'user_pref_time_predefined'), 'yesterday', 'and stored, so the fragment and the download use it');
        expect.eq(pref(db, 'user_pref_show'), 'real', 'along with the clicks setting');

        // Typing a date is choosing Custom Date (p202-ui.js); Apply then
        // submits both dates as YYYY-MM-DD, which the page reads and stores.
        await ui.page.fill('#week-parting-filters-range-from', '2026-01-05');
        await ui.page.fill('#week-parting-filters-range-to', '2026-01-11');
        expect.eq(await ui.value('#week-parting-filters-range'), 'custom', 'typing a date chose Custom Date');
        await applyFilters(ui, 'week-parting-filters');
        await checks.overviewReportDrawn(ui, '#week-parting-report');
        const url = new URL(ui.page.url());
        expect.eq([url.searchParams.get('range'), url.searchParams.get('from'), url.searchParams.get('to')], ['custom', '2026-01-05', '2026-01-11'], 'the URL carries the custom window in ISO dates');
        expect.eq(pref(db, 'user_pref_time_predefined'), '', 'a custom window is stored as the classic calendar stored one');
        expect.eq([await ui.value('#week-parting-filters-range-from'), await ui.value('#week-parting-filters-range-to')], ['2026-01-05', '2026-01-11'],
          'and the page reads the stored window back as the same two days');
        expect.eq(await ui.text('#week-parting-report .p202-empty__title'), 'No clicks in this range', 'a window with no clicks says so, with the first step');
        db.write(PREF_RESET);
      },
    },

    {
      name: 'A value that does not parse is refused where it was typed, and nothing is stored',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/overview/breakdown.php?range=custom&from=2026-02-30&to=2026-03-01&user_pref_show=leads');
        expect.eq(await ui.text('#breakdown-filters .invalid-feedback'), "The start date '2026-02-30' is not a date; use YYYY-MM-DD.",
          'the refusal is the sentence under the range, in the server\'s words');
        expect.eq(await ui.text('.p202-panel .p202-empty__title'), 'These filters were not applied', 'the report says why it is not drawn');
        expect.notOk(await ui.exists('[data-p202-report]'), 'and no fragment is asked for under filters it does not match');
        expect.eq(pref(db, 'user_pref_show'), 'all', 'the valid half of the request was not stored either');
        expect.eq(pref(db, 'user_pref_time_predefined'), 'today', 'nor the window');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'An Advanced filter narrows Visitors, and the disclosure stays open while it is set',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/visitors/');
        await checks.overviewReportDrawn(ui, '#visitors-report');
        await app.openDisclosure('#visitors-filters details');
        await ui.page.fill('#visitors-filters-keyword', 'running');
        await applyFilters(ui, 'visitors-filters');
        await checks.overviewReportDrawn(ui, '#visitors-report');

        expect.ok(await ui.page.$eval('#visitors-filters details', (d) => d.open), 'the Advanced disclosure opens itself while a filter in it is set');
        expect.match(await ui.text('#visitors-filters summary'), /1 set/, 'and says how many are set');
        const keywords = await ui.page.$$eval('#stats-table tbody tr', (rows) => rows.map((r) => (r.cells[12].textContent || '').trim()));
        const expected = Number(db.value("SELECT COUNT(*) FROM 202_dataengine AS d JOIN 202_keywords AS k ON (k.keyword_id = d.keyword_id)"
          + " WHERE d.user_id=1 AND k.keyword LIKE '%running%'"));
        expect.ok(keywords.length > 0 && keywords.every((k) => /running/i.test(k)), 'every row left has the keyword', JSON.stringify(keywords));
        expect.ok(keywords.length <= expected, 'and there are no more rows than the database has such clicks', keywords.length + ' vs ' + expected);
        expect.eq(pref(db, 'user_pref_keyword'), 'running', 'the keyword is stored for the fragment and the download');
        db.write(PREF_RESET);
      },
    },

    {
      name: 'Visitors pages through its clicks, and the page is in the address bar',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/visitors/?user_pref_limit=10');
        await checks.overviewReportDrawn(ui, '#visitors-report');
        const summary = await ui.text('#visitors-report p');
        const total = Number(((summary.match(/of ([\d,]+)/) || [])[1] || '0').replace(/,/g, ''));
        expect.ok(total > 10, 'the fixture has more than one page of clicks today', summary);
        expect.match(summary, /Clicks 1.10 of/, 'the first ten are shown');
        expect.eq(await ui.count('#stats-table tbody tr'), 10, 'ten rows');

        await ui.click('#visitors-report [data-p202-offset="1"]:not([aria-label])');
        await ui.untilInPage(() => /Clicks 11.20/.test((document.querySelector('#visitors-report p') || {}).textContent || ''), undefined, { describe: 'page two to load' });
        expect.eq(new URL(ui.page.url()).searchParams.get('offset'), '1', 'the address bar names the page');
        expect.eq(await ui.text('#visitors-report .page-item.active'), '2', 'and the links mark it');

        await ui.page.reload();
        await checks.overviewReportDrawn(ui, '#visitors-report');
        expect.match(await ui.text('#visitors-report p'), /Clicks 11.20/, 'a reload, or a link sent to someone, opens the same page');
        await checks.baseline(ctx);
        db.write(PREF_RESET);
      },
    },

    {
      // The stored filters are one row per user, and a second tab writes it.
      // What the first tab asks for afterwards — its next page, its download
      // — must still be the report its own URL and form describe.
      name: 'A second tab with other filters does not change what the first tab draws or downloads',
      async run(ctx) {
        const { app, ui, db, expect, config } = ctx;
        db.write(PREF_RESET);
        await app.goto('/tracking202/visitors/?user_pref_show=all&user_pref_limit=10');
        await checks.overviewReportDrawn(ui, '#visitors-report');
        const summary = await ui.text('#visitors-report p');
        const total = Number(((summary.match(/of ([\d,]+)/) || [])[1] || '0').replace(/,/g, ''));
        const leads = Number(db.value('SELECT COUNT(*) FROM 202_dataengine WHERE user_id=1 AND click_lead=1'
          + ' AND click_time BETWEEN UNIX_TIMESTAMP(CURDATE()) AND UNIX_TIMESTAMP(CURDATE() + INTERVAL 1 DAY)'));
        expect.ok(total > 10 && leads < total, 'tab A has more than one page of clicks, not all of them converted', summary + ' / leads ' + leads);
        const download = await ui.page.$eval('.p202-table-toolbar__aside a', (a) => a.href);

        const tabB = await ui.page.context().newPage();
        try {
          await tabB.goto(config.base + '/tracking202/visitors/?user_pref_show=leads&user_pref_limit=50');
          await tabB.waitForSelector('#visitors-report[aria-busy="false"]');
        } finally {
          await tabB.close();
        }
        expect.eq(pref(db, 'user_pref_show'), 'leads', 'tab B stored its filters: the row tab A would otherwise read has changed');
        expect.eq(pref(db, 'user_pref_limit'), '50', 'rows as well');

        await ui.click('#visitors-report [data-p202-offset="1"]:not([aria-label])');
        await ui.untilInPage(() => /Clicks 11.[0-9]/.test((document.querySelector('#visitors-report p') || {}).textContent || ''), undefined, { describe: 'tab A\'s page two to load' });
        expect.match(await ui.text('#visitors-report p'), new RegExp('of ' + total.toLocaleString('en-US') + ','),
          'tab A\'s next page is still every click, ten a page, as its URL says');
        expect.eq(await ui.value('#visitors-filters-user_pref_show'), 'all', 'and its form still says so');

        const response = await ui.page.request.get(download);
        expect.eq(response.status(), 200, 'tab A\'s download answers');
        const rows = (await response.text()).split('\n').filter((line) => /^\d+\t/.test(line)).length;
        // The classic download exports the first page at the page size; tab
        // A's is ten of every click, tab B's would be its converted ones.
        expect.ok(leads < 10, 'the fixture has fewer than ten converted clicks, so the two views export different rows', String(leads));
        expect.eq(rows, Math.min(total, 10), 'and exports tab A\'s clicks at tab A\'s page size, not tab B\'s converted ones');
        db.write(PREF_RESET);
      },
    },

    {
      name: 'Both downloads answer with a file of the report',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        for (const [page, report] of [['/tracking202/visitors/', '#visitors-report'], ['/tracking202/overview/group-overview.php', '#group-overview-report']]) {
          await app.goto(page);
          await checks.overviewReportDrawn(ui, report);
          const href = await ui.page.$eval('.p202-table-toolbar__aside a', (a) => a.href);
          const response = await ui.page.request.get(href);
          expect.eq(response.status(), 200, page + ': the download answers');
          expect.match(response.headers()['content-disposition'] || '', /attachment/, 'with a file');
          expect.ok((await response.body()).length > 50, 'that has the report in it');
        }
      },
    },

    {
      name: 'Group Overview decides a grouping when none is stored, says so, and regroups',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write('UPDATE 202_users_pref SET user_pref_group_1=NULL WHERE user_id=1');
        await app.goto('/tracking202/overview/group-overview.php');
        await checks.overviewReportDrawn(ui, '#group-overview-report');
        expect.match(await ui.text('.p202-decided'), /Grouped by Traffic Source/, 'the page says what it decided');
        expect.eq(pref(db, 'user_pref_group_1'), '1', 'and stored it, so the report and the download agree with the menu');
        expect.ok(await ui.exists('#group-overview-table'), 'the report is drawn rather than empty');

        await ui.select('#group-overview-filters-group_1', '4');
        await applyFilters(ui, 'group-overview-filters');
        await checks.overviewReportDrawn(ui, '#group-overview-report');
        const firstLevel = await ui.page.$$eval('#group-overview-table tbody tr:not(.p202-table__totals) td:first-child .fw-bold', (cells) => cells.map((c) => c.textContent.trim()));
        const campaigns = db.rows('SELECT aff_campaign_name FROM 202_aff_campaigns WHERE user_id=1').map((r) => r[0]);
        expect.ok(firstLevel.some((name) => campaigns.includes(name)), 'grouped by campaign, the first level names campaigns', JSON.stringify(firstLevel));
        expect.notOk(await ui.exists('.p202-decided'), 'and the note is gone once the user chose');
        db.write(PREF_RESET);
      },
    },

    {
      name: 'The account chart draws, switches to hours, and draws what the builder asks for',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        const savedChart = db.value('SELECT data FROM 202_charts WHERE user_id=1');
        const savedRange = db.value('SELECT chart_time_range FROM 202_charts WHERE user_id=1');
        db.temporarily('SELECT 1', "UPDATE 202_charts SET data='" + savedChart.replace(/'/g, "''") + "', chart_time_range='" + savedRange + "' WHERE user_id=1");
        db.write("UPDATE 202_charts SET chart_time_range='days' WHERE user_id=1");

        await app.goto('/tracking202/overview/');
        await checks.overviewReportDrawn(ui, '#overview-report');
        await ui.untilInPage(() => document.querySelector('#overview-chart svg.highcharts-root') !== null, undefined, { describe: 'the chart to draw' });
        const series = () => ui.page.evaluate(() => document.querySelectorAll('#overview-chart .highcharts-series-group .highcharts-series').length);
        const points = () => ui.page.evaluate(() => document.querySelectorAll('#overview-chart .highcharts-xaxis-labels text').length);
        const daily = await points();
        const lines = await series();

        await ui.click('label[for="overview-chart-hours"]');
        await ui.until(async () => (await points()) > daily, { describe: 'the chart to redraw by hour' });
        expect.eq(db.value('SELECT chart_time_range FROM 202_charts WHERE user_id=1'), 'hours', 'By hour is stored for next time');

        await ui.click('[data-bs-target="#overview-chart-builder"]');
        await ui.untilInPage(() => document.querySelector('#overview-chart-builder.show') !== null, undefined, { describe: 'the builder to open' });
        await ui.click('[data-p202-chart-add]');
        const rows = await ui.count('#p202-build-chart [data-p202-chart-line]');
        await ui.page.locator('#p202-build-chart [data-p202-chart-line] select[name="data_type[]"]').last().selectOption('net');
        await ui.click('#p202-build-chart button[type="submit"]');
        await ui.until(async () => (await series()) === lines + 1, { describe: 'the chart to redraw with one more line' });
        expect.eq(rows, lines + 1, 'Add a line added one');
        expect.match(db.value('SELECT data FROM 202_charts WHERE user_id=1'), /"net"/, 'the new line is saved');
        expect.notOk(await ui.exists('.modal-backdrop'), 'and the builder closed without leaving its backdrop behind');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'A rotator rule shows its criteria and redirects in place',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        db.write(PREF_RESET);
        if (db.count('202_rotator_rules') === 0) {
          expect.skip('rule details', 'the fixture has no rotator rules');
          return;
        }
        await app.goto('/tracking202/overview/rotator-breakdown.php');
        await checks.overviewReportDrawn(ui, '#rotator-breakdown-report');
        const summary = '#rotator-table details summary';
        expect.notOk(await ui.visible('#rotator-table details li'), 'the details start folded');
        await ui.click(summary);
        const criterion = db.rows('SELECT value FROM 202_rotator_rules_criteria LIMIT 1')[0][0];
        expect.match(await ui.text('#rotator-table details'), new RegExp(criterion.replace(/[()]/g, '\\$&')), 'a rule\'s criterion is there when opened');
        expect.eq((await totals(ui, '#rotator-table')) !== null, true, 'the totals row closes the table');
      },
    },

    {
      name: 'Spy puts a new click on top without reloading the page',
      async run(ctx) {
        const { app, ui, db, expect, config } = ctx;
        db.write(PREF_RESET);
        const tracker = db.value('SELECT tracker_id_public FROM 202_trackers WHERE landing_page_id=0 LIMIT 1');
        if (tracker === '') {
          expect.skip('a live click', 'the fixture has no direct-link tracker');
          return;
        }
        await app.goto('/tracking202/spy/');
        await checks.overviewReportDrawn(ui, '#spy-report');
        await ui.page.evaluate(() => { window.__p202SpyStillHere = true; });
        const first = await ui.attr('#stats-table tbody tr', 'data-click-id');

        const keyword = 'spy-probe-' + Date.now();
        await ui.page.request.get(config.base + '/tracking202/redirect/dl.php?t202id=' + tracker + '&t202kw=' + keyword, { maxRedirects: 0 });
        await ui.until(async () => (await ui.text('#stats-table tbody tr:first-child')).includes(keyword), { describe: 'the new click to appear on top', timeout: 20000 });
        expect.ok(await ui.page.evaluate(() => window.__p202SpyStillHere === true), 'the page was not reloaded to show it');
        expect.ne(await ui.attr('#stats-table tbody tr', 'data-click-id'), first, 'the top row is a new click');
        // The next polls must not add it again (the fixture's own rows may
        // share ids, so the new click is counted by its unique keyword).
        await ui.settle(6000);
        const copies = await ui.page.$$eval('#stats-table tbody tr', (rows, kw) => rows.filter((r) => r.textContent.includes(kw)).length, keyword);
        expect.eq(copies, 1, 'the new click is shown once, across the polls that follow');
        expect.eq(await ui.attr('[data-p202-spy-status]', 'class'), 'p202-pill p202-pill--good', 'the live pill says it is connected');
        await checks.baseline(ctx);
      },
    },
  ],
};
