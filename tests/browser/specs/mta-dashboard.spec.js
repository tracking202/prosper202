'use strict';

/*
 * Account › Attribution, the multi-touch dashboard (PR 10), driven the way a
 * person drives it.
 *
 * tests/live/mta-ui.sh proves the numbers and every form over HTTP, the
 * refusals and the webhook delivery included. What is here is what only a
 * browser can answer:
 *
 *  - every view meets the v2 baseline at 1280px and 390px, light and dark
 *    (lib/checks.js MTA_DASHBOARD_VIEWS), with the Attribution entry current
 *    in the account sub-menu;
 *  - the report's filter bar applies through its own Apply, the comparison
 *    under Advanced adds its columns, and the CSV link carries what is shown;
 *  - the journey drill-down, reached from the list, sums every model's column
 *    to the whole conversion on screen;
 *  - the model form: the type decides which weights are shown (and posted),
 *    the browser stops a lookback over a year before the server has to, the
 *    server's sentence for weights over 1 lands on the page, a good save is
 *    stored, and a delete asks first — no keeps it, yes removes it;
 *  - the export form: "Later" reveals its time, a private webhook is refused
 *    under the field with nothing written, an export queues and, once the
 *    runner has run, downloads as CSV.
 *
 * It needs conversions with credits; the agent-eval seed has none, so run
 * the pass after adding a few (POST /api/v3/conversions) — setup() runs the
 * attribution worker itself and skips with a sentence if there are still none.
 * It deletes only what it made (models named "Spec …", this account's export
 * jobs), so it does not replace the shared fixture.
 */

const path = require('path');
const { execFileSync } = require('child_process');
const checks = require('../lib/checks');

const PAGE = '/202-account/attribution.php';
const ROOT = path.resolve(__dirname, '..', '..', '..');

function php(script) {
  return execFileSync(process.env.P202_PHP || 'php', [script], { cwd: ROOT, encoding: 'utf8' });
}

module.exports = {
  name: 'mta-dashboard',
  title: 'Attribution dashboard (PR 10)',
  replacesFixture: false,

  async reset(db) {
    db.write("DELETE cr FROM 202_attribution_credits cr JOIN 202_attribution_models m ON m.model_id = cr.model_id WHERE m.model_name LIKE 'Spec %'");
    db.write("DELETE FROM 202_attribution_models WHERE model_name LIKE 'Spec %'");
  },

  async setup(ctx) {
    const { app, db, state, config } = ctx;
    await app.login();
    state.owner = db.value("SELECT user_id FROM 202_users WHERE user_name='" + config.user + "'");
    db.write('DELETE FROM 202_attribution_exports WHERE user_id=' + state.owner);
    if (db.value('SELECT COUNT(*) FROM 202_attribution_pending') !== '0') {
      php('202-cronjobs/attribution-worker.php');
    }
    state.conv = db.value('SELECT COALESCE(MAX(conv_id), 0) FROM 202_attribution_journey_meta WHERE user_id=' + state.owner
      + ' AND conv_time >= UNIX_TIMESTAMP() - 30 * 86400');
    state.defaultModel = db.value('SELECT model_id FROM 202_attribution_models WHERE is_default = 1 AND user_id=' + state.owner);
  },

  scenarios: [
    {
      name: 'Every view meets the v2 baseline at 1280px, light, with Attribution current in the sub-menu',
      async run(ctx) {
        const { app, expect } = ctx;
        await app.goto('/202-account/');
        await ctx.ui.clickThrough('#AttributionPage');
        expect.eq(new URL(ctx.ui.page.url()).pathname, PAGE, 'the account sub-menu leads to the dashboard');
        for (const view of checks.MTA_DASHBOARD_VIEWS) {
          await checks.mtaDashboardBaseline(ctx, view, ctx.state);
        }
      },
    },

    {
      name: 'Every view at 390px and in the dark theme',
      async run(ctx) {
        const { withSession, config } = ctx;
        for (const [scheme, width] of [['light', 390], ['dark', 1280], ['dark', 390]]) {
          await withSession({ colorScheme: scheme, viewport: { width, height: 900 } }, async (other) => {
            const sub = { ...ctx, ...other };
            for (const view of checks.MTA_DASHBOARD_VIEWS) {
              await checks.mtaDashboardBaseline(sub, view, ctx.state);
              if (scheme === 'dark') {
                await checks.darkThemeApplies(sub);
              }
            }
            await other.app.goto(PAGE + '?view=report');
            await other.page.screenshot({ path: path.join(config.shots, 'mta-dashboard-report-' + scheme + '-' + width + '.png'), fullPage: true });
          });
        }
      },
    },

    {
      name: 'The report: Apply regroups, Advanced adds a comparison, the CSV link carries what is shown',
      async run(ctx) {
        const { app, ui, expect, state, db } = ctx;
        if (state.conv === '0') {
          expect.skip('no attributed conversions in the last 30 days', 'add conversions through POST /api/v3/conversions');
          return;
        }
        await app.goto(PAGE + '?view=report');
        await ui.select('#attribution-filters-group_by', 'day');
        await app.submit('#attribution-filters button[type="submit"]');
        expect.match(ui.page.url(), /group_by=day/, 'Apply puts the grouping in the URL');
        expect.eq(await ui.text('#attribution-breakdown thead th:first-child'), 'Day', 'and the table is grouped by day');

        const advanced = '#attribution-filters details.p202-disclosure';
        expect.eq(await app.disclosureOpen(advanced), false, 'Advanced starts closed');
        await app.openDisclosure(advanced);
        await ui.select('#attribution-filters-compare_model_id', state.defaultModel);
        await app.submit('#attribution-filters button[type="submit"]');
        const name = db.value('SELECT model_name FROM 202_attribution_models WHERE model_id=' + state.defaultModel);
        expect.includes(await ui.texts('#attribution-breakdown thead th'), name + ' revenue', 'the comparison adds its columns');
        expect.eq(await app.disclosureOpen(advanced), true, 'and a set Advanced filter keeps the disclosure open');
        const csv = await ui.attr('#attribution-csv', 'href');
        expect.match(csv, /format=csv/, 'the CSV link is the report as a file');
        expect.match(csv, /compare_model_id=/, 'with the comparison');
        expect.match(csv, /group_by=day/, 'and the grouping');
        const response = await ui.page.request.get(new URL(csv, ui.page.url()).toString());
        expect.eq(response.status(), 200, 'the CSV downloads');
        expect.match(response.headers()['content-type'] || '', /text\/csv/, 'as text/csv');

        const stored = db.value('SELECT COALESCE(SUM(revenue), 0) FROM 202_attribution_credits cr JOIN 202_attribution_models m ON m.model_id = cr.model_id WHERE m.model_id='
          + state.defaultModel + ' AND cr.conv_time >= UNIX_TIMESTAMP(CURDATE() - INTERVAL 30 DAY)');
        await app.goto(PAGE + '?view=report&model_id=' + state.defaultModel);
        expect.eq(await ui.text('[data-p202-total="revenue"]'), '$' + Number(stored).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
          'the revenue tile is the stored credits\' revenue');
      },
    },

    {
      name: 'A journey, reached from the list, sums every model to the whole conversion',
      async run(ctx) {
        const { app, ui, expect, state, db } = ctx;
        if (state.conv === '0') {
          expect.skip('no attributed conversions in the last 30 days', 'add conversions through POST /api/v3/conversions');
          return;
        }
        await app.goto(PAGE + '?view=journeys');
        await ui.clickThrough('#recent-table tbody tr:first-child a');
        expect.match(ui.page.url(), /view=journey&conv_id=\d+/, 'the list opens the journey');
        const conv = new URL(ui.page.url()).searchParams.get('conv_id');
        const models = db.rows('SELECT DISTINCT model_id FROM 202_attribution_credits WHERE conv_id=' + conv).map((r) => r[0]);
        expect.ok(models.length > 0, 'the conversion has credits under ' + models.length + ' model(s)');
        const amount = await ui.text('[data-p202-total="amount"]');
        for (const model of models) {
          expect.eq(await ui.text('[data-p202-credit-sum="' + model + '"]'), '100.00%', 'model ' + model + ': the shares sum to 100%');
          expect.eq(await ui.text('[data-p202-revenue-sum="' + model + '"]'), amount, 'model ' + model + ': and the revenue to the amount');
        }
        await checks.tablesScrollThemselves(ctx);
      },
    },

    {
      name: 'Models: the type shows its weights, the browser and then the server refuse, a good save is stored',
      async run(ctx) {
        const { app, ui, expect, db } = ctx;
        await app.goto(PAGE + '?view=models');
        await app.openDisclosure('#model-form details.p202-disclosure');
        await ui.select('#model_type', 'time_decay');
        expect.ok(await ui.visible('#half_life_hours'), 'time decay shows its half-life');
        expect.notOk(await ui.visible('#first_weight'), 'and not the position weights');
        expect.ok(await ctx.page.$eval('#first_weight', (el) => el.disabled), 'which are disabled, so they are not posted');
        await ui.select('#model_type', 'position_based');
        expect.ok(await ui.visible('#first_weight'), 'position based shows its weights');
        expect.notOk(await ui.visible('#half_life_hours'), 'and hides the half-life');

        await ui.fill({ '#model_name': 'Spec U-shaped', '#lookback_days': '400', '#first_weight': '0.7', '#last_weight': '0.6' });
        expect.notOk((await ui.validity('#lookback_days')).valid, 'the browser stops a lookback over 365 days');
        await ui.fill({ '#lookback_days': '60' });
        await app.submit('#model-form button[type="submit"]');
        expect.match(await app.messages(), /first_weight \+ last_weight must be at most 1/, 'the server refuses weights over 1, in its sentence');
        expect.eq(await ui.value('#model_name'), 'Spec U-shaped', 'what was typed is kept');
        expect.eq(db.value("SELECT COUNT(*) FROM 202_attribution_models WHERE model_name='Spec U-shaped'"), '0', 'and nothing was stored');

        await ui.fill({ '#first_weight': '0.5', '#last_weight': '0.3' });
        await app.submit('#model-form button[type="submit"]');
        expect.match(await app.messages(), /Spec U-shaped is added\./, 'a good save says so');
        expect.eq(db.value("SELECT CONCAT(model_type, '|', weighting_config, '|', lookback_days) FROM 202_attribution_models WHERE model_name='Spec U-shaped'"),
          'position_based|{"first_weight":0.5,"last_weight":0.3}|60', 'and is stored as the API stores it');
      },
    },

    {
      name: 'Models: delete asks first; no keeps the model, yes deletes it',
      async run(ctx) {
        const { app, ui, expect, db } = ctx;
        const id = db.value("SELECT model_id FROM 202_attribution_models WHERE model_name='Spec U-shaped'");
        expect.ok(id !== '', 'the model from the scenario before is there');
        await app.goto(PAGE + '?view=models');
        const button = 'tr[data-model-id="' + id + '"] form[data-p202-confirm] button';
        const said = await app.confirmAnd('dismiss', button);
        expect.match(said, /Delete Spec U-shaped\?/, 'the dialog names the model');
        expect.match(said, /Conversions and clicks are kept/, 'and says what is kept');
        expect.eq(db.value('SELECT COUNT(*) FROM 202_attribution_models WHERE model_id=' + id), '1', 'no keeps it');
        await app.confirmAnd('accept', button);
        expect.match(await app.messages(), /Spec U-shaped is deleted/, 'yes deletes it, and says so');
        expect.eq(db.value('SELECT COUNT(*) FROM 202_attribution_models WHERE model_id=' + id), '0', 'and it is gone');
      },
    },

    {
      name: 'Exports: Later shows its time, a private webhook is refused under the field, an export queues and downloads',
      async run(ctx) {
        const { app, ui, expect, db, state } = ctx;
        await app.goto(PAGE + '?view=exports');
        expect.notOk(await ui.visible('#export_run_at'), 'Run at is hidden while Now is chosen');
        await ui.check('#export_when_later');
        expect.ok(await ui.visible('#export_run_at'), 'choosing Later shows it');
        await ui.check('#export_when_now');

        await app.openDisclosure('#export-form details.p202-disclosure');
        await ui.fill({ '#export_webhook_url': 'https://10.1.2.3/hook' });
        await app.submit('#export-form button[type="submit"]');
        expect.match(await ui.text('#export-form .invalid-feedback'), /10\.1\.2\.3, which is a private address/, 'a private webhook is refused under its field');
        expect.eq(db.value('SELECT COUNT(*) FROM 202_attribution_exports WHERE user_id=' + state.owner), '0', 'and nothing was written');

        await ui.fill({ '#export_webhook_url': '' });
        await app.submit('#export-form button[type="submit"]');
        expect.match(await app.messages(), /is queued/, 'an export queues');
        const id = db.value('SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=' + state.owner);
        expect.eq(await ui.attr('tr[data-export-id="' + id + '"]', 'data-export-status'), 'pending', 'and the list shows it waiting');

        php('202-cronjobs/attribution-exports.php');
        await app.goto(PAGE + '?view=exports');
        expect.eq(await ui.attr('tr[data-export-id="' + id + '"]', 'data-export-status'), 'completed', 'after the runner it is completed');
        const href = await ui.attr('tr[data-export-id="' + id + '"] a[href*="download="]', 'href');
        const response = await ui.page.request.get(new URL(href, ui.page.url()).toString());
        expect.eq(response.status(), 200, 'and its Download returns the file');
        expect.match((await response.text()).split('\n')[0], /^key,name,clicks,cost,attributed_conversions/, 'a CSV with the report\'s columns');
      },
    },
  ],
};
