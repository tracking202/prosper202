'use strict';

/*
 * The shared report partials, driven on the UI kit where they are rendered in
 * every state (202-config/functions-ui-partials.php).
 *
 * tests/Api/V3/UiPartialsTest reads their markup. What is here is what only a
 * browser can answer:
 *
 *  - the v2 shell's page scripts are deferred and still run: tablesort and
 *    p202-ui.js find the body, and nothing throws;
 *  - the sort order actually on screen, and that the header's aria-sort says
 *    the same thing — p202-ui.js derives it from tablesort's classes, whose
 *    names read backwards (`sort-down` is ascending), and the first version
 *    of that mapping, written from the minified source, was wrong for
 *    number columns; this reads the rows rather than trusting it;
 *  - the totals row stays last whatever is clicked;
 *  - the range picker's handler, the Advanced disclosure that must not fold
 *    an active filter away, and what Apply really puts in the URL;
 *  - phone width and the dark theme.
 *
 * Nothing is seeded and nothing is truncated: the kit's figures are its own.
 */

const checks = require('../lib/checks');

const KIT = '/202-account/ui-kit.php';

/** The first cell of every body row of a table, in screen order. */
async function column(ui, tableId, index) {
  return ui.page.$$eval('#' + tableId + ' tbody tr', (rows, i) => rows.map((row) => (row.cells[i].textContent || '').trim()), index);
}

async function ariaSorts(ui, tableId) {
  return ui.page.$$eval('#' + tableId + ' thead th', (cells) => cells.map((th) => th.getAttribute('aria-sort') || ''));
}

module.exports = {
  name: 'ui-kit-partials',
  title: 'UI kit › report partials',

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'The kit renders on v2 with its page scripts deferred',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(KIT);
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);
        await checks.noLegacyClasses(ctx, ['col-xs-6', 'col-xs-12', 'panel', 'panel-body', 'well', 'form-horizontal', 'input-sm']);

        const scripts = await ui.page.evaluate(() => Array.from(document.querySelectorAll('head script[src]')).map((s) => ({
          src: new URL(s.src).pathname.replace(/^.*\/(202-js\/)/, '$1'),
          defer: s.defer,
        })));
        const byName = (part) => scripts.find((s) => s.src.includes(part));
        for (const part of ['tablesort', 'p202-ui.js', 'p202-chrome.js']) {
          const script = byName(part);
          expect.ok(script && script.defer, part + ' loads deferred', JSON.stringify(script));
        }
        for (const part of ['jquery', 'bootstrap']) {
          const script = byName(part);
          expect.ok(script && !script.defer, part + ' still blocks, for inline scripts that call it', JSON.stringify(script));
        }
        expect.ok(await ui.page.evaluate(() => typeof window.Tablesort === 'function'), 'tablesort ran');
        expect.eq(await ui.attr('#kit-sortable', 'data-p202-sort-ready'), '1', 'and p202-ui.js wired the sortable table to it');
        expect.eq(await ui.attr('#kit-ordered', 'data-p202-sort-ready'), null, 'but left the table that did not opt in alone');
      },
    },

    {
      name: 'Sorting orders the rows the header says, and the totals stay last',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(KIT + '#reports');

        const before = await column(ui, 'kit-sortable', 0);
        expect.eq(before[before.length - 1], 'Totals for report', 'the totals row starts last');
        expect.eq(await ariaSorts(ui, 'kit-sortable'), ['', '', '', '', ''], 'no column claims an order before one is chosen');

        await ui.click('#kit-sortable th:nth-child(1) .p202-sort');
        expect.eq(await column(ui, 'kit-sortable', 0), ['Marathon plan', 'running shoes', 'trail running', 'Totals for report'],
          'the first click on a text column puts it A to Z, ignoring case');
        expect.eq((await ariaSorts(ui, 'kit-sortable'))[0], 'ascending', 'and the header says ascending');

        await ui.click('#kit-sortable th:nth-child(1) .p202-sort');
        expect.eq(await column(ui, 'kit-sortable', 0), ['trail running', 'running shoes', 'Marathon plan', 'Totals for report'],
          'the second click reverses it');
        expect.eq((await ariaSorts(ui, 'kit-sortable'))[0], 'descending', 'and the header says descending');

        await ui.click('#kit-sortable th:nth-child(2) .p202-sort');
        expect.eq(await column(ui, 'kit-sortable', 1), ['98', '310', '1,412', '1,820'],
          'the first click on a number column puts the smallest first, by value rather than by text');
        expect.eq(await ariaSorts(ui, 'kit-sortable'), ['', 'ascending', '', '', ''],
          'the header says ascending, and the column sorted before no longer claims an order');

        await ui.click('#kit-sortable th:nth-child(2) .p202-sort');
        expect.eq(await column(ui, 'kit-sortable', 1), ['1,412', '310', '98', '1,820'], 'the second click puts the biggest first');
        expect.eq((await ariaSorts(ui, 'kit-sortable'))[1], 'descending', 'and says descending');

        // Formatted money must sort by value: as text, "$1,304.00" would
        // come before "$612.00".
        await ui.page.focus('#kit-sortable th:nth-child(4) .p202-sort');
        await ui.page.keyboard.press('Enter');
        expect.eq(await column(ui, 'kit-sortable', 3), ['$0.00', '$612.00', '$1,304.00', '$1,916.00'],
          'Enter on a focused header sorts too, and money sorts by its value');
        expect.eq((await ariaSorts(ui, 'kit-sortable'))[3], 'ascending', 'and the header says so');

        const statusBefore = await column(ui, 'kit-sortable', 0);
        await ui.click('#kit-sortable th:nth-child(5)');
        await ui.settle(200); // asserting that nothing happens
        expect.eq(await column(ui, 'kit-sortable', 0), statusBefore, 'a column marked unsortable does nothing when clicked');
        expect.eq(await ui.count('#kit-sortable th:nth-child(5) button'), 0, 'and offers no button to press');
      },
    },

    {
      name: 'The range picker: typing a date chooses Custom Date',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(KIT + '#reports');

        const state = () => ui.page.evaluate(() => {
          const read = (id) => {
            const el = document.getElementById(id);
            return { editable: !el.disabled && !el.readOnly, submitted: el.hasAttribute('name'), value: el.value };
          };
          return {
            range: document.getElementById('kit-range-preset').value,
            from: read('kit-range-preset-from'),
            to: read('kit-range-preset-to'),
            hint: (() => { const h = document.querySelector('#kit-range-preset-form [data-p202-range-hint]'); return h ? !h.hidden : null; })(),
          };
        });

        let now = await state();
        expect.eq(now.range, 'last7', 'the preset form opens on its preset');
        expect.ok(now.from.editable && !now.from.submitted, 'its dates are editable but withheld from the request');
        expect.eq(now.hint, false, 'and the no-JavaScript hint is hidden');

        await ui.fill({ '#kit-range-preset-from': '2026-09-01' });
        now = await state();
        expect.eq(now.range, 'custom', 'typing a date selects Custom Date');
        expect.ok(now.from.submitted && now.to.submitted, 'and puts both dates in the request');

        await ui.select('#kit-range-preset', 'today');
        now = await state();
        expect.ok(!now.from.submitted && !now.to.submitted, 'choosing a preset withdraws them again');

        expect.ok(await ui.page.$eval('#kit-range-error-from', (el) => el.classList.contains('is-invalid')), 'a refused window marks its fields');
        expect.eq(await ui.text('#kit-range-error-form .invalid-feedback'), 'The start date is after the end date.',
          'and shows the server\'s sentence under them');
      },
    },

    {
      name: 'Apply puts exactly the filters in the URL',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(KIT);
        await ui.clickThrough('#kit-filters button[type="submit"]');
        let url = new URL(ui.page.url());
        expect.eq(url.pathname, KIT, 'the bar submits to the report it belongs to');
        expect.eq(url.searchParams.get('range'), 'last7', 'with the preset');
        expect.eq(url.searchParams.get('from'), null, 'and without dates, which a preset does not need');
        expect.eq(url.searchParams.get('view'), 'report', 'carrying the hidden field');
        expect.eq(url.searchParams.get('user_pref_show'), 'real', 'the common filters in their classic names');
        expect.eq(url.searchParams.get('user_pref_limit'), '50', 'and the Advanced ones too, closed or not');

        await app.goto(KIT);
        await ui.clickThrough('#kit-filtered button[type="submit"]');
        url = new URL(ui.page.url());
        expect.eq(url.searchParams.get('range'), 'custom', 'a custom window');
        expect.eq(url.searchParams.get('from') + '..' + url.searchParams.get('to'), '2026-08-01..2026-08-31', 'travels with its dates');
        expect.eq(url.searchParams.get('aff_campaign_id'), '99',
          'and a value the list no longer has is kept, not widened to "All"');
      },
    },

    {
      name: 'An Advanced filter that is set is never folded away',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(KIT);
        // Remember both disclosures as closed, the way a reader who closed
        // them last time would have left them.
        await ui.page.evaluate(() => {
          localStorage.setItem('p202-disclosure:kit-filters', 'closed');
          localStorage.setItem('p202-disclosure:kit-filtered', 'closed');
        });
        await app.goto(KIT);
        expect.ok(await ui.page.$eval('#kit-filtered details', (d) => d.open),
          'the bar with an Advanced filter set opens it anyway');
        expect.eq(await ui.text('#kit-filtered details .p202-disclosure__hint'), '2 set',
          'and counts what is set: the country and the IP, not the row count left at its default');
        expect.notOk(await ui.page.$eval('#kit-filters details', (d) => d.open), 'the bar with nothing set stays as remembered');

        await app.openDisclosure('#kit-filters details');
        await app.goto(KIT);
        expect.ok(await ui.page.$eval('#kit-filters details', (d) => d.open), 'and remembers being opened');
        await ui.page.evaluate(() => localStorage.removeItem('p202-disclosure:kit-filters'));
      },
    },

    {
      name: 'At phone width and in the dark theme',
      async run(ctx) {
        const { withSession, expect } = ctx;
        await withSession({ viewport: { width: 390, height: 900 }, colorScheme: 'dark' }, async (inner) => {
          const sub = { ...inner, expect };
          await inner.app.goto(KIT + '#reports');
          await checks.baseline(sub);
          await checks.tablesScrollThemselves(sub);
          await checks.darkThemeApplies(sub);
          await checks.flexContainersKeepTheirSpaces(sub);
          const bar = await inner.ui.box('#kit-filters');
          expect.ok(bar !== null && bar.width <= 390, 'the filter bar fits the phone', JSON.stringify(bar));
          const table = await inner.ui.page.$eval('#kit-sortable', (t) => getComputedStyle(t.querySelector('tbody td')).color);
          expect.ok(/rgb\((2[0-9]{2}|1[5-9][0-9]),/.test(table), 'table text is light on the dark ground', table);
        });
      },
    },
  ],
};
