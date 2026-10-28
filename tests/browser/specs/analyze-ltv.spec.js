'use strict';

/*
 * Analyze › Customer LTV on the v2 shell, driven the way a person drives it.
 *
 * The page is a frame (tracking202/analyze/ltv.php) whose views are partials
 * loaded into it by 202-js/ltv.js, so almost everything worth checking is a
 * browser question: that the router loads the view the URL names, that tabs,
 * pills, rows and Back move between views and the address bar keeps up, that
 * a mutation (a product edit) goes through and says so, that the merge
 * picker is a real modal that leaves nothing behind, and that every view
 * holds the standard's baseline at 1280px and 390px, light and dark.
 *
 * The customers are seeded here, deterministically; the spec truncates the
 * LTV tables, so point it at a scratch database (lib/db.js enforces that).
 */

const path = require('path');
const checks = require('../lib/checks');

const PAGE = '/tracking202/analyze/ltv.php';
const DAY = 86400;
const CUSTOMERS = 60;

/** The seeded customers: who, when first seen, what they bought. */
function buildModel() {
  let s = 20260925;
  const next = () => { s = (Math.imul(s, 1664525) + 1013904223) >>> 0; return s / 4294967296; };
  const now = Math.floor(Date.now() / 1000);
  const first = ['Ada', 'Grace', 'Alan', 'Linus', 'Ken', 'Barbara', 'Edsger', 'Donald', 'Frances', 'Margaret'];
  const customers = [];
  for (let c = 1; c <= CUSTOMERS; c++) {
    const daysBack = 1 + Math.floor(next() * 25);
    const orders = Math.floor(next() * 4);
    customers.push({
      id: c,
      name: first[c % 10],
      last: 'Tester' + c,
      seen: now - daysBack * DAY - Math.floor(next() * 3600),
      daysBack,
      orders,
      product: 1 + Math.floor(next() * 3),
    });
  }
  return customers;
}

const MODEL = buildModel();
const PRICES = { 1: 19, 2: 49, 3: 9 };

/** Wait until the router has put a view in #m-content. */
async function viewLoaded(ui) {
  await ui.untilInPage(() => {
    const content = document.getElementById('m-content');
    return content !== null && !content.hasAttribute('aria-busy') && content.querySelector('.p202-skeleton') === null
      && content.children.length > 0;
  }, undefined, { describe: 'the LTV view to load' });
}

async function tile(ui, label) {
  return ui.page.evaluate((wanted) => {
    const tiles = Array.from(document.querySelectorAll('#m-content .p202-tile'));
    const hit = tiles.find((t) => (t.querySelector('.p202-tile__label') || {}).textContent === wanted);
    return hit ? hit.querySelector('.p202-tile__value').textContent.trim() : null;
  }, label);
}

async function clickAndLoad(ui, selector) {
  await ui.page.click(selector);
  await ui.untilInPage(() => document.getElementById('m-content').hasAttribute('aria-busy')
    || document.querySelector('#m-content .p202-skeleton') !== null, undefined, { describe: 'the view to start loading' }).catch(() => {});
  await viewLoaded(ui);
}

module.exports = {
  name: 'analyze-ltv',
  title: 'Analyze › Customer LTV',

  async reset(db) {
    const q = (v) => (v === null ? 'NULL' : "'" + String(v).replace(/'/g, "''") + "'");
    const values = (rows) => rows.map((r) => '(' + r.map(q).join(', ') + ')').join(', ');
    db.truncate(['202_customers', '202_revenue_events', '202_revenue_line_items', '202_subscriptions', '202_products',
      '202_companies', '202_customer_aliases', '202_engagement_events']);
    const now = Math.floor(Date.now() / 1000);
    db.write('INSERT INTO 202_products (product_id, user_id, external_product_id, sku, name, price, currency, created_at, updated_at) VALUES '
      + values([[1, 1, 'p-basic', 'SKU-B', 'Basic plan', 19, 'USD', now, now], [2, 1, 'p-pro', 'SKU-P', 'Pro plan', 49, 'USD', now, now],
        [3, 1, 'p-book', 'SKU-E', 'E-book', 9, 'USD', now, now]]));
    db.write("INSERT INTO 202_companies (company_id, user_id, name, normalized_name, domain, created_at, updated_at) VALUES (1, 1, 'Acme Inc', 'acme inc', 'acme.example', " + now + ', ' + now + ')');
    const customers = [];
    const events = [];
    const lines = [];
    let event = 1;
    for (const c of MODEL) {
      const amount = PRICES[c.product];
      for (let o = 0; o < c.orders; o++) {
        events.push([event, 1, c.id, 'purchase', amount, 'USD', c.seen + o * 3600, 'api', c.seen]);
        lines.push([event, 1, event, c.product, 'SKU', 'P', 1, amount, amount, c.seen]);
        event++;
      }
      customers.push([c.id, 1, 'cust-' + c.id, c.name, c.last, c.name.toLowerCase() + c.id + '@example.com',
        c.id % 7 === 0 ? 'Acme Inc' : null, c.id % 7 === 0 ? 1 : null, 'US', c.seen, c.seen + c.orders * 3600,
        c.orders, c.orders * amount, 0, 0, 0, 'active', c.seen, c.seen]);
    }
    db.write('INSERT INTO 202_customers (customer_id, user_id, primary_ref, first_name, last_name, email, company, company_id, country,'
      + ' first_seen_time, last_activity_time, order_count, total_revenue, refunded_amount, active_subscription_count, mrr, status,'
      + ' created_at, updated_at) VALUES ' + values(customers));
    db.write('INSERT INTO 202_revenue_events (event_id, user_id, customer_id, event_type, amount, currency, occurred_at, source, created_at) VALUES ' + values(events));
    db.write('INSERT INTO 202_revenue_line_items (line_item_id, user_id, event_id, product_id, sku, product_name, quantity, unit_price, amount, created_at) VALUES ' + values(lines));
    db.write('INSERT INTO 202_subscriptions (subscription_id, user_id, customer_id, external_sub_id, plan_name, amount, currency, billing_interval,'
      + ' billing_interval_count, status, mrr, started_at, current_period_start, current_period_end, created_at, updated_at) VALUES '
      + values([[1, 1, 1, 'sub_1', 'Pro plan', 49, 'USD', 'month', 1, 'active', 49, now - 5 * DAY, now - 5 * DAY, now + 25 * DAY, now, now]]));
    db.write("UPDATE 202_customers SET active_subscription_count=1, mrr=49 WHERE customer_id=1");
    db.write("UPDATE 202_users_pref SET user_pref_time_predefined='last30', user_pref_time_from=NULL, user_pref_time_to=NULL WHERE user_id=1");
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'The page is reached from the Analyze strip and loads its report',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/tracking202/analyze/keywords.php');
        await app.openFromSubMenu('Customer LTV');
        expect.eq(new URL(ui.page.url()).pathname, PAGE, 'the strip leads to the page');
        await viewLoaded(ui);
        expect.eq(await app.currentSubMenuItem(), 'Customer LTV', 'and marks it as current');
        expect.eq(await ui.text('.p202-page-header__title'), 'Customer Lifetime Value', 'the page says what it is');
        await checks.v2PageBaseline(ctx);
        expect.eq(await ui.texts('#m-content .p202-tabs .nav-link'), ['Report', 'Subscriptions', 'Products', 'Companies', 'Settings'],
          'the section\'s views are tabs');
        expect.eq(await ui.attr('#m-content .p202-tabs .nav-link.active', 'aria-current'), 'page', 'and the current one says so');
        expect.eq(await tile(ui, 'Customers'), String(CUSTOMERS), 'every seeded customer is in the last 30 days');
        const revenue = MODEL.reduce((n, c) => n + c.orders * PRICES[c.product], 0);
        expect.eq(await tile(ui, 'Revenue'), '$' + revenue.toLocaleString('en-US', { minimumFractionDigits: 2 }), 'with their revenue');
        await ctx.shot('ltv-report');
      },
    },

    {
      name: 'Tabs, pills and rows move between views, and Back comes back',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE);
        await viewLoaded(ui);

        await clickAndLoad(ui, '#m-content .p202-tabs .nav-link:has-text("Subscriptions")');
        expect.eq(new URL(ui.page.url()).searchParams.get('view'), 'subscriptions', 'a tab records its view in the URL');
        expect.eq(await tile(ui, 'MRR'), '$49.00', 'and shows it');

        await ui.page.goBack();
        await viewLoaded(ui);
        expect.eq(new URL(ui.page.url()).searchParams.get('view'), null, 'Back returns to the report');
        expect.ok(await tile(ui, 'Customers') !== null, 'and re-renders it');

        await clickAndLoad(ui, '#m-content [data-ltv-chips="ltv-by-select"] a:has-text("Product")');
        expect.eq(new URL(ui.page.url()).searchParams.get('ltv_by'), 'product', 'a grouping pill is in the URL');
        expect.eq(await ui.text('#ltv-breakdown-table thead th:first-child'), 'Product', 'and regroups the breakdown');
        const names = await ui.texts('#ltv-breakdown-table tbody td:first-child');
        expect.eq([...names].sort(), ['Basic plan', 'E-book', 'Pro plan'], 'by the seeded products');
        expect.ok(await ui.exists('#m-content [data-ltv-chips="ltv-by-select"] a.p202-pill--accent:has-text("Product")'),
          'the chosen pill is the accented one');

        await clickAndLoad(ui, '#ltv-customers-table tbody tr:first-child');
        const url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('view'), 'customer', 'a customer row opens the customer');
        expect.ok(await ui.exists('#m-content .p202-page-header__title'), 'with the customer\'s name as its heading');
        await checks.v2PageBaseline(ctx);
        await clickAndLoad(ui, '#m-content a:has-text("Back to Customer LTV")');
        expect.eq(new URL(ui.page.url()).searchParams.get('view'), null, 'its back link returns to the report');
      },
    },

    {
      name: 'A long customer list pages on the server; a short breakdown sorts in place',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE);
        await viewLoaded(ui);
        expect.eq(await ui.count('#ltv-customers-table tbody tr'), 50, 'fifty customers a page');
        expect.eq(await ui.attr('#ltv-customers-table', 'data-p202-sort'), null,
          'a list that runs to another page does not pretend to sort in place');
        await clickAndLoad(ui, '#m-content button:has-text("Next")');
        expect.eq(new URL(ui.page.url()).searchParams.get('offset'), '50', 'Next asks for the next fifty');
        expect.eq(await ui.count('#ltv-customers-table tbody tr'), CUSTOMERS - 50, 'and shows the rest');

        await app.goto(PAGE + '?ltv_by=product');
        await viewLoaded(ui);
        expect.eq(await ui.attr('#ltv-breakdown-table', 'data-p202-sort-ready'), '1', 'the breakdown is wired for sorting');
        const revenueCol = await ui.page.$$eval('#ltv-breakdown-table thead th', (ths) => ths.findIndex((t) => t.textContent.trim() === 'Revenue'));
        await ui.click('#ltv-breakdown-table thead th:nth-child(' + (revenueCol + 1) + ') .p202-sort');
        const values = await ui.page.$$eval('#ltv-breakdown-table tbody tr', (trs, i) => trs.map((tr) => Number(tr.cells[i].textContent.replace(/[^0-9.]/g, ''))), revenueCol);
        expect.eq(values, [...values].sort((a, b) => a - b), 'Revenue sorts the products by their value');
      },
    },

    {
      name: 'The range applies, and a product edit saves',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(PAGE);
        await viewLoaded(ui);
        await ui.select('#ltv-range-range', 'last7');
        await ui.clickThrough('#ltv-range button[type="submit"]');
        await viewLoaded(ui);
        expect.eq(new URL(ui.page.url()).searchParams.get('range'), 'last7', 'the range is in the URL');
        const recent = db.value('SELECT COUNT(*) FROM 202_customers WHERE user_id=1 AND first_seen_time >= ' + (Math.floor(Date.now() / 1000) - 8 * DAY));
        const shown = Number(await tile(ui, 'Customers'));
        expect.ok(shown > 0 && shown <= Number(recent) && shown < CUSTOMERS, 'and the report counts only the customers first seen in it',
          shown + ' of ' + recent + ' seen in the last eight days');
        await clickAndLoad(ui, '#m-content .p202-tabs .nav-link:has-text("Products")');
        expect.eq(new URL(ui.page.url()).searchParams.get('range'), 'last7', 'changing view keeps the window in the URL');
        // The tabs are real links: a middle-click or a copied link must open
        // the same window, not whatever window is stored by then.
        const hrefs = await ui.page.$$eval('#m-content .p202-tabs .nav-link', (links) => links.map((a) => {
          const url = new URL(a.href);
          return (a.textContent || '').trim() + ':' + url.searchParams.get('range') + ':' + (url.searchParams.get('view') || 'report');
        }));
        expect.eq(hrefs, ['Report:last7:report', 'Subscriptions:last7:subscriptions', 'Products:last7:products', 'Companies:last7:companies', 'Settings:last7:settings'],
          'every tab\'s href carries the window on screen');
        await ui.page.goto(new URL('?range=custom&from=2026-08-01&to=2026-08-31&view=products', ui.page.url()).toString());
        await viewLoaded(ui);
        const custom = await ui.page.$eval('#m-content .p202-tabs .nav-link:first-child', (a) => a.href);
        expect.match(custom, /[?&]range=custom&from=2026-08-01&to=2026-08-31$/, 'a custom window travels with its two days');
        await ui.page.goto(new URL('?range=last7&view=products', ui.page.url()).toString());
        await viewLoaded(ui);

        await clickAndLoad(ui, '#m-content tr:has-text("E-book") button:has-text("Edit")');
        await ui.fill({ '#m-content input[name="product_name"]': 'E-book, second edition' });
        await clickAndLoad(ui, '#m-content button:has-text("Save")');
        expect.eq(db.value('SELECT name FROM 202_products WHERE product_id=3'), 'E-book, second edition', 'the edit is stored');
        expect.ok(await ui.exists('#m-content td:has-text("E-book, second edition")'), 'and shown');
        await app.goto(PAGE + '?range=last30');
        await viewLoaded(ui);
      },
    },

    {
      name: 'A second tab\'s window does not change the one on screen, and a stray sort is not the range\'s fault',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        // A pasted link with a page and a sort this page does not read: the
        // range still applies, and nothing blames it for the others.
        await app.goto(PAGE + '?range=last7&order=not-a-column&page=x');
        await viewLoaded(ui);
        const pageText = await ui.page.evaluate(() => document.body.textContent);
        expect.notOk(pageText.includes('The range was not applied'), 'a stray sort or page is not reported as a range error');
        expect.eq(db.value('SELECT user_pref_time_predefined FROM 202_users_pref WHERE user_id=1'), 'last7', 'and the range is stored');
        const shown = await tile(ui, 'Customers');
        expect.ok(Number(shown) > 0 && Number(shown) < CUSTOMERS, 'the last seven days are a part of the customers', String(shown));

        // Another tab stores another window.
        db.write("UPDATE 202_users_pref SET user_pref_time_predefined='last30', user_pref_time_from=NULL, user_pref_time_to=NULL WHERE user_id=1");
        await clickAndLoad(ui, '#m-content .p202-tabs .nav-link:has-text("Subscriptions")');
        await clickAndLoad(ui, '#m-content .p202-tabs .nav-link:has-text("Report")');
        expect.eq(await tile(ui, 'Customers'), shown, 'the report this tab loads next still draws the window on screen, not the one stored since');
        const hrefs = await ui.page.$$eval('#m-content .p202-tabs .nav-link', (links) => links.map((a) => new URL(a.href).searchParams.get('range')));
        expect.eq([...new Set(hrefs)], ['last7'], 'and its tabs still say that window');

        const download = await ui.page.$eval('#m-content a[href*="ltv_download.php"]', (a) => a.href);
        expect.eq(new URLSearchParams(new URL(download).searchParams.get('view') || '').get('range'), 'last7', 'the download link carries the view');
        const rows = await ui.page.evaluate(async (url) => {
          const body = await (await fetch(url, { credentials: 'same-origin' })).text();
          return (body.match(/<tr>/g) || []).length - 1;
        }, download);
        expect.eq(String(rows), shown, 'and exports the customers on screen');
        db.write("UPDATE 202_users_pref SET user_pref_time_predefined='last30', user_pref_time_from=NULL, user_pref_time_to=NULL WHERE user_id=1");
      },
    },

    {
      name: 'The merge picker is a modal that leaves nothing behind',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(PAGE + '?view=customer&customer_id=7');
        await viewLoaded(ui);
        await ui.page.click('#m-content button:has-text("Merge")');
        await ui.untilInPage(() => {
          const m = document.getElementById('ltv-merge-overlay');
          return m && m.classList.contains('show') && document.activeElement && document.activeElement.id === 'ltv-merge-input';
        }, undefined, { describe: 'the merge picker to open with the search focused' });
        await ui.page.keyboard.type('Ada');
        await ui.untilInPage(() => document.querySelectorAll('#ltv-merge-results .list-group-item').length > 0,
          undefined, { describe: 'search results' });
        expect.ok(await ui.exists('#ltv-merge-results .list-group-item.active'), 'the first result is highlighted for Enter');
        await ui.page.keyboard.press('Escape');
        await ui.untilInPage(() => document.querySelector('.modal-backdrop') === null
          && !document.getElementById('ltv-merge-overlay').classList.contains('show'), undefined, { describe: 'the picker to close' });
        expect.ok(true, 'Escape closes it, backdrop and all');

        await ui.page.click('#m-content button:has-text("Merge")');
        await ui.untilInPage(() => document.getElementById('ltv-merge-overlay').classList.contains('show'), undefined, { describe: 'reopen' });
        await ui.page.goBack();
        await viewLoaded(ui);
        await ui.untilInPage(() => document.querySelector('.modal-backdrop') === null, undefined,
          { describe: 'no backdrop over the view Back went to' });
        expect.notOk(await ui.page.evaluate(() => document.body.style.overflow === 'hidden'), 'and the page scrolls again');
      },
    },

    {
      name: 'Every view at 1280px and 390px, light and dark',
      async run(ctx) {
        const { withSession, expect } = ctx;
        const views = ['', '?view=subscriptions', '?view=products', '?view=companies', '?view=settings',
          '?view=customer&customer_id=1', '?view=company&company=Acme%20Inc'];
        for (const variant of [
          { viewport: { width: 1280, height: 900 }, colorScheme: 'light' },
          { viewport: { width: 1280, height: 900 }, colorScheme: 'dark' },
          { viewport: { width: 390, height: 900 }, colorScheme: 'light' },
          { viewport: { width: 390, height: 900 }, colorScheme: 'dark' },
        ]) {
          await withSession(variant, async (inner) => {
            const sub = { ...ctx, ...inner, expect };
            for (const view of views) {
              expect.section((view || 'report') + ' at ' + variant.viewport.width + 'px, ' + variant.colorScheme);
              await inner.app.goto(PAGE + view);
              await viewLoaded(inner.ui);
              await checks.v2PageBaseline(sub);
              if (variant.colorScheme === 'dark') {
                await checks.darkThemeApplies(sub);
              }
              if (view === '' || view === '?view=settings') {
                await inner.page.screenshot({
                  path: path.join(ctx.config.shots, 'ltv' + (view === '' ? '' : '-settings') + '-' + variant.viewport.width + '-' + variant.colorScheme + '.png'),
                  fullPage: true,
                });
              }
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
