'use strict';

/*
 * Analyze › the thirteen click reports (keywords … custom variables), driven
 * the way a person drives them. They share one controller and one template
 * (tracking202/analyze/AnalyzeReportController.php, templates/report.php),
 * so most scenarios run on one or two of them and the per-page ones walk
 * checks.ANALYZE_REPORT_PAGES.
 *
 * What is here is what only a browser can answer:
 *
 *  - every page renders on the v2 shell with the standard's baseline, at
 *    1280px and 390px, light and dark;
 *  - the filters, typed and chosen, take effect: the numbers on screen are
 *    the seeded clicks they describe, computed here from the seed rather
 *    than read back from the page;
 *  - a filter applied on one report is the default on the next, because the
 *    reports share the stored preferences (UI standard, rule 8);
 *  - sorting: in the browser when the report is one page, by link on the
 *    server when it runs to more;
 *  - pagination, the download beside the table, the custom-variable groups,
 *    and an empty state whose button does the first step.
 *
 * The click data is seeded here, deterministically, so the spec runs on an
 * install that has never tracked a click. It truncates the report tables:
 * point it at a scratch database (lib/db.js enforces that).
 */

const checks = require('../lib/checks');

const DAY = 86400;
const TZ = 'America/New_York';

/** A deterministic sequence, so the seed and its model never disagree. */
function lcg(seed) {
  let s = seed >>> 0;
  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

/** The calendar day a timestamp falls on for the account (its timezone). */
function accountDay(ts) {
  return new Date(ts * 1000).toLocaleDateString('en-CA', { timeZone: TZ });
}

const COUNTRIES = [['US', 'United States'], ['GB', 'United Kingdom'], ['DE', 'Germany'], ['FR', 'France'], ['CA', 'Canada'], ['JP', 'Japan']];
const REGIONS = [['California', 1], ['Texas', 1], ['Bavaria', 3], ['Ontario', 5], ['England', 2]];
const ISPS = ['Comcast', 'Verizon', 'Vodafone', 'Orange'];
const BROWSERS = ['Chrome', 'Safari', 'Firefox'];
const PLATFORMS = ['Windows', 'iOS', 'Android'];
const DEVICES = [['Desktop', 1], ['iPhone', 2], ['iPad', 3]];
const KEYWORDS = 25;
const NETWORKS = ['Google Ads', 'Meta'];

/**
 * The seeded clicks, as the spec's own model: one row per click, with every
 * dimension the reports group by. The SQL below is generated from this, so
 * an expected number is a sum over this list.
 */
function buildModel() {
  const next = lcg(20260925);
  const pick = (n) => Math.floor(next() * n);
  const noonUtcToday = Math.floor(Date.now() / 1000 / DAY) * DAY + 12 * 3600;
  const rows = [];
  for (let i = 0; i < 240; i++) {
    const daysBack = 1 + pick(20);
    const network = pick(3); // 2 = no traffic source
    rows.push({
      id: 700000 + i,
      time: noonUtcToday - daysBack * DAY + pick(3000),
      network: network === 2 ? null : network + 1,
      account: network === 2 ? 0 : network + 1,
      campaign: 1 + pick(2),
      keyword: 1 + (i % KEYWORDS),
      country: 1 + pick(COUNTRIES.length),
      region: 1 + pick(REGIONS.length),
      isp: 1 + pick(ISPS.length),
      browser: 1 + pick(BROWSERS.length),
      platform: 1 + pick(PLATFORMS.length),
      device: 1 + pick(DEVICES.length),
      referer: 1 + pick(6),
      ip: 1 + pick(8),
      textAd: pick(2) ? 1 : 0,
      landingPage: pick(2) ? 1 : 0,
      lead: pick(6) === 0 ? 1 : 0,
      varSet: network === 2 ? 0 : 1 + network * 2 + pick(2),
      cost: (5 + pick(80)) / 100,
    });
  }
  rows.forEach((r) => { r.day = accountDay(r.time); });
  return rows;
}

const MODEL = buildModel();

function sum(rows, key) {
  return rows.reduce((n, r) => n + (key === 'clicks' ? 1 : r[key]), 0);
}

/** "1,412" → 1412, "(12.50)" → -12.5, "$3.20" → 3.2 */
function num(text) {
  const t = String(text).trim();
  const n = Number(t.replace(/[^0-9.]/g, ''));
  return t.startsWith('(') || t.startsWith('-') ? -n : n;
}

/** The report table's body rows as {label, cells}, totals included. */
async function tableRows(ui) {
  return ui.page.$$eval('#stats-table tbody tr', (trs) => trs.map((tr) => ({
    cls: tr.className,
    cells: Array.from(tr.cells).map((c) => (c.textContent || '').replace(/\s+/g, ' ').trim()),
  })));
}

async function totalsRow(ui) {
  const rows = await tableRows(ui);
  return rows[rows.length - 1];
}

/** Put the stored filters back to "nothing set, last 30 days, 50 a page". */
function resetPrefs(db) {
  db.write("UPDATE 202_users_pref SET user_pref_limit=50, user_pref_show='all', user_cpc_or_cpv='cpc',"
    + " user_pref_time_predefined='last30', user_pref_time_from=NULL, user_pref_time_to=NULL,"
    + ' user_pref_ppc_network_id=NULL, user_pref_ppc_account_id=NULL, user_pref_aff_network_id=NULL,'
    + ' user_pref_aff_campaign_id=NULL, user_pref_text_ad_id=NULL, user_pref_method_of_promotion=NULL,'
    + ' user_pref_landing_page_id=NULL, user_pref_country_id=NULL, user_pref_region_id=NULL, user_pref_isp_id=NULL,'
    + ' user_pref_device_id=NULL, user_pref_browser_id=NULL, user_pref_platform_id=NULL, user_pref_ip=NULL,'
    + ' user_pref_referer=NULL, user_pref_keyword=NULL, user_pref_subid=NULL WHERE user_id=1');
}

const PAGE = (name) => '/tracking202/analyze/' + name + '.php';

/**
 * Open the bar's Advanced section unless it is open already: it remembers
 * being opened (per browser), so an earlier scenario may have left it so.
 */
async function openAdvanced(app) {
  if (!(await app.disclosureOpen('#report-filters details'))) {
    await app.openDisclosure('#report-filters details');
  }
}

module.exports = {
  name: 'analyze-reports',
  title: 'Analyze › the click reports',

  async reset(db) {
    const q = (v) => (v === null ? 'NULL' : "'" + String(v).replace(/'/g, "''") + "'");
    const values = (rows) => rows.map((r) => '(' + r.map(q).join(', ') + ')').join(', ');
    db.truncate([
      '202_dataengine', '202_keywords', '202_locations_country', '202_locations_region', '202_locations_city',
      '202_locations_isp', '202_browsers', '202_platforms', '202_device_models', '202_site_urls', '202_site_domains',
      '202_ips', '202_text_ads', '202_landing_pages', '202_ppc_networks', '202_ppc_accounts', '202_aff_networks',
      '202_aff_campaigns', '202_variable_sets2', '202_ppc_network_variables', '202_custom_variables',
    ]);
    const now = Math.floor(Date.now() / 1000);
    db.write('INSERT INTO 202_ppc_networks (ppc_network_id, user_id, ppc_network_deleted, ppc_network_name, ppc_network_time) VALUES '
      + values(NETWORKS.map((n, i) => [i + 1, 1, 0, n, now])));
    db.write('INSERT INTO 202_ppc_accounts (ppc_account_id, user_id, ppc_network_id, ppc_account_name, ppc_account_deleted, ppc_account_time, ppc_account_default) VALUES '
      + values(NETWORKS.map((n, i) => [i + 1, 1, i + 1, n + ' main', 0, now, 0])));
    db.write("INSERT INTO 202_aff_networks (aff_network_id, dni_network_id, user_id, aff_network_name, aff_network_deleted, aff_network_time) VALUES (1, 0, 1, 'Offer Network', 0, " + now + ')');
    db.write('INSERT INTO 202_aff_campaigns (aff_campaign_id, aff_campaign_id_public, user_id, aff_network_id, aff_campaign_deleted, aff_campaign_name, aff_campaign_url, aff_campaign_url_2, aff_campaign_url_3, aff_campaign_url_4, aff_campaign_url_5, aff_campaign_payout, aff_campaign_cloaking, aff_campaign_time, aff_campaign_rotate, aff_campaign_currency, aff_campaign_foreign_payout) VALUES '
      + values([[1, 1, 1, 1, 0, 'Campaign A', 'https://example.com/a', '', '', '', '', 10, 0, now, 0, 'USD', 0], [2, 2, 1, 1, 0, 'Campaign B', 'https://example.com/b', '', '', '', '', 10, 0, now, 0, 'USD', 0]]));
    db.write('INSERT INTO 202_landing_pages (landing_page_id, user_id, landing_page_id_public, aff_campaign_id, landing_page_nickname, landing_page_url, leave_behind_page_url, landing_page_deleted, landing_page_time, landing_page_type) VALUES '
      + values([[1, 1, 1, 1, 'Quiz page', 'https://example.com/lp', '', 0, now, 0]]));
    db.write('INSERT INTO 202_text_ads (text_ad_id, user_id, aff_campaign_id, landing_page_id, text_ad_deleted, text_ad_name, text_ad_headline, text_ad_description, text_ad_display_url, text_ad_time, text_ad_type) VALUES '
      + values([[1, 1, 1, 0, 0, 'Headline A', 'Head', 'Desc', 'example.com', now, 0]]));
    db.write('INSERT INTO 202_keywords (keyword_id, keyword) VALUES '
      + values(Array.from({ length: KEYWORDS }, (_, i) => [i + 1, 'keyword ' + String(i + 1).padStart(2, '0')])));
    db.write('INSERT INTO 202_locations_country (country_id, country_code, country_name) VALUES ' + values(COUNTRIES.map((c, i) => [i + 1, c[0], c[1]])));
    db.write('INSERT INTO 202_locations_region (region_id, main_country_id, region_name) VALUES ' + values(REGIONS.map((r, i) => [i + 1, r[1], r[0]])));
    db.write('INSERT INTO 202_locations_city (city_id, main_country_id, city_name) VALUES ' + values(REGIONS.map((r, i) => [i + 1, r[1], 'City of ' + r[0]])));
    db.write('INSERT INTO 202_locations_isp (isp_id, isp_name) VALUES ' + values(ISPS.map((n, i) => [i + 1, n])));
    db.write('INSERT INTO 202_browsers (browser_id, browser_name) VALUES ' + values(BROWSERS.map((n, i) => [i + 1, n])));
    db.write('INSERT INTO 202_platforms (platform_id, platform_name) VALUES ' + values(PLATFORMS.map((n, i) => [i + 1, n])));
    db.write('INSERT INTO 202_device_models (device_id, device_name, device_type) VALUES ' + values(DEVICES.map((d, i) => [i + 1, d[0], d[1]])));
    db.write('INSERT INTO 202_site_domains (site_domain_id, site_domain_host) VALUES ' + values(Array.from({ length: 6 }, (_, i) => [i + 1, 'ref' + (i + 1) + '.example.com'])));
    db.write('INSERT INTO 202_site_urls (site_url_id, site_domain_id, site_url_address) VALUES ' + values(Array.from({ length: 6 }, (_, i) => [i + 1, i + 1, 'https://ref' + (i + 1) + '.example.com/'])));
    db.write('INSERT INTO 202_ips (ip_id, ip_address, location_id) VALUES ' + values(Array.from({ length: 8 }, (_, i) => [i + 1, '198.51.100.' + (i + 1), 0])));
    // Two variables per traffic source, two values each; a click's set
    // carries one value of each.
    db.write('INSERT INTO 202_ppc_network_variables (ppc_variable_id, ppc_network_id, name, parameter, placeholder, deleted) VALUES '
      + values([[1, 1, 'placement', 'p', '{p}', 0], [2, 1, 'creative', 'c', '{c}', 0], [3, 2, 'placement', 'p', '{p}', 0], [4, 2, 'creative', 'c', '{c}', 0]]));
    db.write('INSERT INTO 202_custom_variables (custom_variable_id, ppc_variable_id, variable) VALUES '
      + values([[1, 1, 'feed'], [2, 2, 'banner'], [3, 1, 'story'], [4, 2, 'video'], [5, 3, 'feed'], [6, 4, 'banner'], [7, 3, 'reels'], [8, 4, 'video']]));
    db.write('INSERT INTO 202_variable_sets2 (variable_set_id, variables) VALUES '
      + values([[1, 1], [1, 2], [2, 3], [2, 4], [3, 5], [3, 6], [4, 7], [4, 8]]));

    db.write('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_network_id, ppc_account_id, aff_network_id, aff_campaign_id,'
      + ' landing_page_id, keyword_id, text_ad_id, click_referer_site_url_id, country_id, region_id, city_id, isp_id, browser_id,'
      + ' device_id, platform_id, ip_id, variable_set_id, click_lead, click_filtered, click_bot, click_alp, clicks, click_out,'
      + ' leads, payout, income, cost) VALUES '
      + values(MODEL.map((r) => [1, r.id, r.time, r.network, r.account, 1, r.campaign, r.landingPage, r.keyword, r.textAd, r.referer,
        r.country, r.region, r.region, r.isp, r.browser, r.device, r.platform, r.ip, String(r.varSet), r.lead, 0, 0, 0, 1, 1,
        r.lead, 10, r.lead * 10, r.cost])));
    db.write("UPDATE 202_users_pref SET user_account_currency='USD' WHERE user_id=1");
    resetPrefs(db);
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Every report, reached from the Analyze strip',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('keywords') + '?range=last30');
        for (const page of checks.ANALYZE_REPORT_PAGES) {
          expect.section(page.menu);
          await app.openFromSubMenu(page.menu);
          expect.eq(new URL(ui.page.url()).pathname, page.path, 'the strip leads to ' + page.path);
          expect.eq(await app.currentSubMenuItem(), page.menu, 'and marks it as current');
          expect.eq(await ui.text('.p202-page-header__title'), page.heading, 'the page says what it is');
          await checks.v2PageBaseline(ctx);
          const rows = await tableRows(ui);
          expect.ok(rows.length > 1, 'the report has rows', String(rows.length));
          expect.match(rows[rows.length - 1].cls, /p202-table__totals/, 'and the totals row is last');
          // Custom variables count only the clicks that carried some.
          const counted = page.menu === 'Custom Variables' ? MODEL.filter((r) => r.varSet !== 0) : MODEL;
          expect.eq(num(rows[rows.length - 1].cells[1]), counted.length,
            'which counts every seeded click it covers in the last 30 days');
          if (page.menu === 'Keywords' || page.menu === 'Custom Variables') {
            await ctx.shot('analyze-' + page.menu.toLowerCase().replace(/\s+/g, '-'));
          }
        }
      },
    },

    {
      name: 'The numbers are the clicks the rows describe',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('countries') + '?range=last30');
        const rows = (await tableRows(ui)).slice(0, -1);
        expect.eq(rows.length, new Set(MODEL.map((r) => r.country)).size, 'one row per country with clicks');
        for (const row of rows) {
          const index = COUNTRIES.findIndex((c) => row.cells[0] === c[1] + ' (' + c[0] + ')');
          const mine = MODEL.filter((r) => r.country === index + 1);
          expect.ok(index >= 0, row.cells[0] + ' is a seeded country');
          expect.eq(num(row.cells[1]), mine.length, row.cells[0] + ': clicks');
          expect.eq(num(row.cells[4]), sum(mine, 'lead'), row.cells[0] + ': leads');
        }
        const leads = rows.map((r) => num(r.cells[4]));
        expect.eq(leads, [...leads].sort((a, b) => b - a), 'most leads first, the order the classic report used');
        expect.eq(await ui.attr('#stats-table th:nth-child(5)', 'aria-sort'), 'descending', 'and the Leads header says so');
      },
    },

    {
      name: 'A filter applies, and becomes the default on the other reports',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(PAGE('keywords') + '?range=last30');
        await openAdvanced(app);
        await ui.select('#report-filters-country_id', '3');
        await ui.clickThrough('#report-filters button[type="submit"]');

        const url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('country_id'), '3', 'the filter is in the URL');
        const germany = MODEL.filter((r) => r.country === 3);
        expect.eq(num((await totalsRow(ui)).cells[1]), germany.length, 'and the report counts only German clicks');
        expect.eq(db.value('SELECT user_pref_country_id FROM 202_users_pref WHERE user_id=1'), '3', 'it is stored as the default');
        expect.eq(await ui.text('#report-filters details .p202-disclosure__hint'), '1 set', 'and Advanced says one filter is set');

        await app.openFromSubMenu('Countries');
        const rows = (await tableRows(ui)).slice(0, -1);
        expect.eq(rows.map((r) => r.cells[0]), ['Germany (DE)'], 'the next report opens with the same filter');
        expect.eq(await ui.value('#report-filters-country_id'), '3', 'and its bar shows it');

        await ui.clickThrough('#report-filters a:has-text("Reset")');
        expect.eq(num((await totalsRow(ui)).cells[1]), MODEL.length, 'Reset clears it');
        expect.eq(db.value('SELECT IFNULL(user_pref_country_id, 0) FROM 202_users_pref WHERE user_id=1'), '0', 'for the stored default too');
      },
    },

    {
      name: 'A region is typed, from suggestions, and a wrong one is refused in words',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('regions') + '?range=last30');
        const suggestions = await ui.page.$$eval('#report-filters-region-list option', (os) => os.map((o) => o.value));
        expect.includes(suggestions, 'Texas (US)', 'the suggestions are the regions the clicks came from, with their country');

        await openAdvanced(app);
        await ui.fill({ '#report-filters-region': 'Texas (US)' });
        await ui.clickThrough('#report-filters button[type="submit"]');
        const rows = (await tableRows(ui)).slice(0, -1);
        expect.eq(rows.map((r) => r.cells[0]), ['Texas (US)'], 'the report is Texas alone');
        expect.eq(num(rows[0].cells[1]), MODEL.filter((r) => r.region === 2).length, 'with its clicks');

        await ui.fill({ '#report-filters-region': 'Atlantis' });
        await ui.clickThrough('#report-filters button[type="submit"]');
        expect.match(await app.messages(), /There is no region called “Atlantis”/, 'an unknown name is refused under the field');
        expect.match(await app.messages(), /Nothing you asked for was applied/, 'and the page says the report did not change');
        expect.eq((await tableRows(ui)).slice(0, -1).map((r) => r.cells[0]), ['Texas (US)'], 'which it did not');
        await app.goto(PAGE('regions') + '?range=last30');
      },
    },

    {
      name: 'A custom window: typing a date chooses it, one Apply applies it',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('browsers') + '?range=last30');
        const days = [...new Set(MODEL.map((r) => r.day))].sort();
        const from = days[3];
        const to = days[days.length - 4];
        await ui.fill({ '#report-filters-range-from': from, '#report-filters-range-to': to });
        expect.eq(await ui.value('#report-filters-range'), 'custom', 'typing a date selects Custom Date');
        await ui.clickThrough('#report-filters button[type="submit"]');

        const url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('range') + ' ' + url.searchParams.get('from') + ' ' + url.searchParams.get('to'),
          'custom ' + from + ' ' + to, 'the window travels in the URL as ISO dates');
        const inside = MODEL.filter((r) => r.day >= from && r.day <= to);
        expect.eq(num((await totalsRow(ui)).cells[1]), inside.length, 'and the report counts the clicks between them, in the account\'s timezone');

        await ui.select('#report-filters-range', 'last30');
        await ui.clickThrough('#report-filters button[type="submit"]');
        expect.eq(new URL(ui.page.url()).searchParams.get('from'), null, 'a preset leaves the dates behind');
      },
    },

    {
      name: 'One page sorts in the browser; more pages sort on the server',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('countries') + '?range=last30');
        expect.eq(await ui.attr('#stats-table', 'data-p202-sort-ready'), '1', 'a one-page report is sortable in place');
        await ui.click('#stats-table th:nth-child(2) .p202-sort');
        let rows = await tableRows(ui);
        let clicks = rows.slice(0, -1).map((r) => num(r.cells[1]));
        expect.eq(clicks, [...clicks].sort((a, b) => a - b), 'Clicks puts the fewest first');
        expect.match(rows[rows.length - 1].cls, /p202-table__totals/, 'and the totals stay last');
        await ui.click('#stats-table th:nth-child(1) .p202-sort');
        const names = (await tableRows(ui)).slice(0, -1).map((r) => r.cells[0]);
        expect.eq(names, [...names].sort((a, b) => a.localeCompare(b)), 'the country column sorts too');

        await app.goto(PAGE('keywords') + '?range=last30&user_pref_limit=10');
        expect.eq(await ui.attr('#stats-table', 'data-p202-sort'), null, 'a paginated report does not pretend to sort in place');
        await ui.clickThrough('#stats-table th:nth-child(2) a.p202-sort');
        expect.eq(new URL(ui.page.url()).searchParams.get('order'), 'sort_breakdown_clicks desc', 'the Clicks heading asks the server');
        expect.eq(new URL(ui.page.url()).searchParams.get('user_pref_limit'), '10', 'keeping the rest of the report');
        const byKeyword = {};
        MODEL.forEach((r) => { byKeyword[r.keyword] = (byKeyword[r.keyword] || 0) + 1; });
        const most = Math.max(...Object.values(byKeyword));
        rows = await tableRows(ui);
        expect.eq(num(rows[0].cells[1]), most, 'the busiest keyword of all pages is first');
        expect.eq(await ui.attr('#stats-table th:nth-child(2)', 'aria-sort'), 'descending', 'and the heading says so');
        await ui.clickThrough('#stats-table th:nth-child(2) a.p202-sort');
        expect.eq(new URL(ui.page.url()).searchParams.get('order'), 'sort_breakdown_clicks asc', 'again reverses it');
        clicks = (await tableRows(ui)).slice(0, -1).map((r) => num(r.cells[1]));
        expect.eq(clicks[0], Math.min(...Object.values(byKeyword)), 'the quietest first');
      },
    },

    {
      name: 'Pages run on from each other and keep the filters',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('keywords') + '?range=last30&user_pref_limit=10&user_pref_show=all');
        const pages = Math.ceil(KEYWORDS / 10);
        expect.match(await ui.text('.p202-table-toolbar .text-secondary'), new RegExp('Page 1 of ' + pages), 'the pager counts the pages');
        const first = (await tableRows(ui)).slice(0, -1).map((r) => r.cells[0]);
        expect.eq(await ui.text('.p202-table__totals td'), 'Totals for this page', 'the totals say they are this page\'s');
        await ui.clickThrough('.pagination a:has-text("2")');
        const url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('page'), '2', 'page two is in the URL');
        expect.eq(url.searchParams.get('user_pref_limit'), '10', 'with the rest of the report');
        const second = (await tableRows(ui)).slice(0, -1).map((r) => r.cells[0]);
        expect.eq(second.length, 10, 'ten more rows');
        expect.eq(second.filter((k) => first.includes(k)), [], 'none of which were on page one');
        await app.goto(PAGE('keywords') + '?range=last30');
      },
    },

    {
      name: 'The download is the report on the screen',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('platforms') + '?range=last30&user_pref_show=all');
        const href = await ui.attr('.p202-table-toolbar__aside a', 'href');
        expect.match(href || '', /platform_download\.php$/, 'the button is the platform export');
        const response = await ui.page.request.get(new URL(href, ui.page.url()).toString());
        expect.eq(response.status(), 200, 'which answers');
        const lines = (await response.text()).trim().split('\n');
        expect.eq(lines[0].split('\t').slice(0, 2), ['Platform', 'Clicks'], 'as a tab-separated sheet');
        const sheet = lines.slice(1).map((l) => l.split('\t')).map((c) => c[0] + '=' + c[1]).sort();
        const table = (await tableRows(ui)).slice(0, -1).map((r) => r.cells[0] + '=' + r.cells[1]).sort();
        expect.eq(sheet, table, 'with the same platforms and clicks as the table');
      },
    },

    {
      name: 'Custom variables are grouped by traffic source and variable',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('variables') + '?range=last30');
        const groups = await ui.texts('#stats-table tr.p202-table__group th');
        expect.eq(groups.sort(), ['Google Ads · creative', 'Google Ads · placement', 'Meta · creative', 'Meta · placement'],
          'one heading per traffic source and variable');
        const feed = MODEL.filter((r) => r.varSet === 1);
        const row = await ui.page.$$eval('#stats-table tbody', (bodies) => {
          const body = bodies.find((b) => (b.textContent || '').includes('Google Ads · placement'));
          const tr = Array.from(body.rows).find((r) => r.cells[0] && r.cells[0].textContent.trim() === 'feed');
          return tr ? tr.cells[1].textContent.trim() : null;
        });
        expect.eq(num(row), feed.length, 'and each value counts its clicks');
        expect.match((await totalsRow(ui)).cls, /p202-table__totals/, 'the totals come last');
      },
    },

    {
      name: 'An empty report offers the way back in one click',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE('referers') + '?range=last30&keyword=no-such-keyword');
        expect.eq(await ui.text('.p202-empty__title'), 'No clicks match these filters', 'the empty state says why');
        await ui.clickThrough('.p202-empty__action a');
        expect.eq(num((await totalsRow(ui)).cells[1]), MODEL.length, 'its button clears the filters');
        expect.eq(await ui.value('#report-filters-keyword'), '', 'and the keyword with them');
      },
    },

    {
      name: 'Every report at phone width and in the dark',
      async run(ctx) {
        const { withSession, expect } = ctx;
        for (const variant of [
          { viewport: { width: 1280, height: 900 }, colorScheme: 'dark' },
          { viewport: { width: 390, height: 900 }, colorScheme: 'light' },
          { viewport: { width: 390, height: 900 }, colorScheme: 'dark' },
        ]) {
          await withSession(variant, async (inner) => {
            const sub = { ...ctx, ...inner, expect };
            for (const page of checks.ANALYZE_REPORT_PAGES) {
              expect.section(page.menu + ' at ' + variant.viewport.width + 'px, ' + variant.colorScheme);
              await inner.app.goto(page.path + '?range=last30');
              await checks.v2PageBaseline(sub);
              if (variant.colorScheme === 'dark') {
                await checks.darkThemeApplies(sub);
              }
              if (page.menu === 'Keywords') {
                await inner.page.screenshot({
                  path: require('path').join(ctx.config.shots, 'analyze-keywords-' + variant.viewport.width + '-' + variant.colorScheme + '.png'),
                  fullPage: true,
                });
              }
              const bar = await inner.ui.box('#report-filters');
              expect.ok(bar !== null && bar.width <= variant.viewport.width, 'the filter bar fits the screen', JSON.stringify(bar));
            }
          });
        }
      },
    },

    {
      name: 'Nothing broke along the way',
      async run(ctx) {
        const { session, expect } = ctx;
        expect.ok(session.errors.length === 0, 'no JavaScript errors during the whole pass', session.errors.slice(0, 3).join(' | '));
        expect.ok(session.unexpectedDialogs.length === 0, 'and no confirm appeared anywhere', session.unexpectedDialogs.join(' | '));
      },
    },
  ],
};
