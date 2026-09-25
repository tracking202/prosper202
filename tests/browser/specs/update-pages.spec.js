'use strict';

/*
 * The Update family on the v2 shell (U5), driven the way a person drives it.
 *
 * tests/live/update-pages.sh proves every form over HTTP, refusals included,
 * and tests/live/conversion-ledger.sh the ledger behind three of them. What
 * is here is what only a browser can answer: every page's baseline at a
 * desktop and a phone width and in both themes (lib/checks.js UPDATE_PAGES),
 * the category select narrowing the campaign select, native validation
 * letting through what the server then refuses in its own words, the
 * Advanced disclosure closed by default, the two-step CPC update, every
 * confirmation — saying no must change nothing, saying yes must do what the
 * dialog said — and a revenue report uploaded through the file input.
 *
 * It seeds its own categories, campaigns and clicks (ids 950001-950003) with
 * db.temporarily(), so the shared fixture is left as it was found.
 *
 * Everything that claims a write reads the database.
 */

const checks = require('../lib/checks');

const UPDATE = '/tracking202/update/';
const C1 = 950001;
const C2 = 950002;
const C3 = 950003;
const CLICKS = [C1, C2, C3].join(',');

/** Noon UTC on a day in April 2021: the same calendar day in every US zone. */
function noon(day) {
  return Math.floor(Date.UTC(2021, 3, day, 16, 0, 0) / 1000);
}

function dropSeed(db) {
  db.write('DELETE FROM 202_conversion_logs WHERE click_id IN (' + CLICKS + ')');
  db.write('DELETE FROM 202_dataengine WHERE click_id IN (' + CLICKS + ')');
  for (const table of ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_clicks_site']) {
    db.write('DELETE FROM ' + table + ' WHERE click_id IN (' + CLICKS + ')');
  }
  db.write("DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'U5 Browser %'");
  db.write("DELETE FROM 202_aff_networks WHERE aff_network_name LIKE 'U5 Browser %'");
}

module.exports = {
  name: 'update-pages',
  title: 'Update (U5)',

  async reset(db) {
    dropSeed(db);
  },

  async setup(ctx) {
    const { db, state } = ctx;
    await ctx.app.login();
    state.owner = db.value("SELECT user_id FROM 202_users WHERE user_name='" + ctx.config.user + "'");
    const now = Math.floor(Date.now() / 1000);
    db.temporarily(
      "INSERT INTO 202_aff_networks (user_id, aff_network_name, aff_network_time) VALUES (" + state.owner + ", 'U5 Browser Network', " + now + "), (" + state.owner + ", 'U5 Browser Other', " + now + ')',
      "DELETE FROM 202_aff_networks WHERE aff_network_name LIKE 'U5 Browser %'"
    );
    state.net = db.value("SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='U5 Browser Network'");
    state.net2 = db.value("SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='U5 Browser Other'");
    db.temporarily(
      'INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode) VALUES '
        + '(950101, ' + state.owner + ', ' + state.net + ", 'U5 Browser Campaign A', 'http://example.test/', 2.00, " + now + ", 2.00, 'replace'), "
        + '(950102, ' + state.owner + ', ' + state.net2 + ", 'U5 Browser Campaign B', 'http://example.test/', 2.00, " + now + ", 2.00, 'replace')",
      "DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'U5 Browser %'"
    );
    state.ca = db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='U5 Browser Campaign A'");
    state.cb = db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='U5 Browser Campaign B'");
    const clicks = [[C1, state.ca, noon(14)], [C2, state.cb, noon(14)], [C3, state.ca, noon(16)]];
    for (const [id, campaign, time] of clicks) {
      db.write('INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id) VALUES ('
        + id + ', ' + state.owner + ', ' + campaign + ', 0, 0, 0.10, 2.00, 0, 0, 0, 0, ' + time + ', 0, 0)');
      db.write('INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time) VALUES ('
        + id + ', ' + state.owner + ', ' + campaign + ', 0, 0, 0.10, 2.00, 0, 0, 0, 0, ' + time + ')');
      db.write('INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES (' + id + ', 0, 0, 0, 0)');
      db.write('INSERT INTO 202_clicks_site (click_id, click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id) VALUES (' + id + ', 0, 0, 0, 0, 0)');
    }
    // Put back after the spec, newest first, whatever a scenario did.
    db.temporarily('SELECT 1', 'DELETE FROM 202_conversion_logs WHERE click_id IN (' + CLICKS + ')');
    db.temporarily('SELECT 1', 'DELETE FROM 202_dataengine WHERE click_id IN (' + CLICKS + ')');
    for (const table of ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_clicks_site']) {
      db.temporarily('SELECT 1', 'DELETE FROM ' + table + ' WHERE click_id IN (' + CLICKS + ')');
    }
  },

  scenarios: [
    {
      name: 'Reaching the Update pages the way a user does',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto(UPDATE);
        expect.eq(new URL(ui.page.url()).pathname, UPDATE + 'subids.php', 'the section opens on Update Subids');
        await app.openFromSubMenu('Update CPC');
        expect.eq(new URL(ui.page.url()).pathname, UPDATE + 'cpc.php', 'the sub-menu leads to Update CPC');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'Every Update page at 1280px and 390px, light',
      async run(ctx) {
        const { withSession, shot } = ctx;
        for (const entry of checks.UPDATE_PAGES) {
          await checks.updatePageBaseline(ctx, entry);
          await shot('update-' + entry.path.split('/').pop().replace('.php', '') + '-1280-light');
        }
        await withSession({ viewport: { width: 390, height: 844 } }, async (phone) => {
          for (const entry of checks.UPDATE_PAGES) {
            await checks.updatePageBaseline({ ...ctx, ...phone }, entry);
            await phone.page.screenshot({ path: require('path').join(ctx.config.shots, 'update-' + entry.path.split('/').pop().replace('.php', '') + '-390-light.png'), fullPage: true });
          }
        });
      },
    },

    {
      name: 'Every Update page in the dark theme, at 1280px and 390px',
      async run(ctx) {
        const { withSession, config } = ctx;
        for (const width of [1280, 390]) {
          await withSession({ colorScheme: 'dark', viewport: { width, height: 900 } }, async (dark) => {
            for (const entry of checks.UPDATE_PAGES) {
              await checks.updatePageBaseline({ ...ctx, ...dark }, entry, { dark: true });
            }
            await dark.app.goto(UPDATE + 'cpc.php');
            await dark.page.screenshot({ path: require('path').join(config.shots, 'update-dark-' + width + '-cpc.png'), fullPage: true });
          });
        }
      },
    },

    {
      name: 'Update CPC: the category narrows the campaigns, Advanced waits closed',
      async run(ctx) {
        const { app, ui, expect, state } = ctx;
        await app.goto(UPDATE + 'cpc.php');
        const advanced = 'details[data-p202-remember="update-cpc-advanced"]';
        expect.eq(await app.disclosureOpen(advanced), false, 'Advanced starts closed');
        expect.notOk(await ui.visible('#landing_page_id'), 'its fields are hidden until opened');
        const campaigns = async () => ui.page.$$eval('#aff_campaign_id option', (options) => options.map((o) => o.textContent.trim()));
        expect.eq((await campaigns()).join('|'), 'Every campaign', 'with every category, the only campaign choice is every campaign');
        await ui.select('#aff_network_id', String(state.net));
        const narrowed = await campaigns();
        expect.includes(narrowed, 'U5 Browser Campaign A', 'choosing a category offers its campaign');
        expect.notOk(narrowed.includes('U5 Browser Campaign B'), 'and not the other category\'s');
        expect.ok(await ui.visible('#cpc'), 'the CPC field is in the common case');
      },
    },

    {
      name: 'Update CPC: the browser lets through what the server refuses, and the server says so under the field',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(UPDATE + 'cpc.php');
        await ui.fill({ '#cpc': '' });
        expect.notOk((await ui.validity('#cpc')).valid, 'an empty CPC is stopped by the browser first');
        await ui.fill({ '#cpc': '250' });
        expect.notOk((await ui.validity('#cpc')).valid, 'and a CPC past the column\'s $99.99999');
        await ui.fill({ '#from': '2021-04-15', '#to': '2021-04-14', '#cpc': '0.3' });
        expect.ok((await ui.validity('#to')).valid, 'the browser accepts a window that ends before it starts');
        await app.submit('#cpc_form button[type="submit"]');
        expect.eq(await ui.text('#to ~ .invalid-feedback'), 'The last day is before the first day.', 'the server refuses it under the field');
        expect.eq(await ui.value('#from'), '2021-04-15', 'what was typed is kept');
        expect.notOk(await ui.exists('#cpc-apply'), 'and no update is offered');
        expect.eq(db.value('SELECT GROUP_CONCAT(click_cpc ORDER BY click_id) FROM 202_clicks WHERE click_id IN (' + CLICKS + ')'), '0.10000,0.10000,0.10000', 'nothing was written');
      },
    },

    {
      name: 'Update CPC: check, then confirm, and exactly those clicks change',
      async run(ctx) {
        const { app, ui, db, expect, state, shot } = ctx;
        await app.goto(UPDATE + 'cpc.php');
        await ui.select('#aff_network_id', String(state.net));
        await ui.select('#aff_campaign_id', String(state.ca));
        await ui.fill({ '#from': '2021-04-14', '#to': '2021-04-14', '#cpc': '0.3' });
        await app.submit('#cpc_form button[type="submit"]');
        expect.eq(await ui.text('#cpc-confirm .p202-pill'), '1 click', 'the check counts the one click of campaign A that day');
        expect.eq(await ui.text('#update-cpc-confirm'), 'Update 1 click', 'and offers to update exactly that');
        expect.match(await ui.text('#cpc-summary'), /U5 Browser Network · U5 Browser Campaign A/, 'naming the campaign');
        expect.eq(await ui.value('#aff_campaign_id'), String(state.ca), 'the form still shows what was asked for');
        await shot('update-cpc-confirm');
        expect.eq(db.value('SELECT click_cpc FROM 202_clicks WHERE click_id=' + C1), '0.10000', 'the check wrote nothing');
        await app.submit('#update-cpc-confirm');
        expect.match((await app.flashes()).join(' '), /^1 click updated\./, 'confirming updates it and says so');
        expect.eq(db.value('SELECT GROUP_CONCAT(click_cpc ORDER BY click_id) FROM 202_clicks WHERE click_id IN (' + CLICKS + ')'), '0.30000,0.10000,0.10000',
          'C1 costs $0.30; the other category and the other day are untouched');
      },
    },

    {
      name: 'Reset Campaign Subids: saying no clears nothing, saying yes clears the category',
      async run(ctx) {
        const { app, ui, db, expect, state, config } = ctx;
        await ui.page.request.get(config.base + '/tracking202/static/gpb.php?subid=' + C1 + '&amount=2&txid=U5BR1');
        await ui.page.request.get(config.base + '/tracking202/static/gpb.php?subid=' + C2 + '&amount=2&txid=U5BR2');
        const live = (id) => db.value('SELECT COUNT(*) FROM 202_conversion_logs WHERE deleted=0 AND click_id=' + id);
        expect.eq(live(C1) + '/' + live(C2), '1/1', 'a conversion in each category');
        await app.goto(UPDATE + 'clear-subids.php');
        await ui.select('#aff_network_id', String(state.net));
        const said = await app.confirmAnd('dismiss', '#clear-subids');
        expect.match(said, /Clear every conversion in this selection\?/, 'resetting asks first');
        expect.eq(live(C1), '1', 'and saying no clears nothing');
        await app.confirmAnd('accept', '#clear-subids');
        expect.match((await app.flashes()).join(' '), /You have reset 1 subids\./, 'saying yes resets the category and says how many');
        expect.eq(live(C1) + '/' + live(C2), '0/1', 'C1 is cleared; the other category keeps its conversion');
      },
    },

    {
      name: 'Delete Subids: the confirm guards the delete',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        const live = (id) => db.value('SELECT COUNT(*) FROM 202_conversion_logs WHERE deleted=0 AND click_id=' + id);
        await app.goto(UPDATE + 'delete-subids.php');
        await ui.fill({ '#subids': String(C2) + '\nnot-a-subid' });
        const said = await app.confirmAnd('dismiss', '#delete-subids button[type="submit"]');
        expect.match(said, /Delete every conversion recorded on these subids\?/, 'deleting asks first');
        expect.eq(live(C2), '1', 'and saying no deletes nothing');
        await app.confirmAnd('accept', '#delete-subids button[type="submit"]');
        const flashes = (await app.flashes()).join(' | ');
        expect.match(flashes, /1 subid\(s\) cleared\./, 'saying yes clears it and says so');
        expect.match(flashes, /Not subids, so nothing was cleared for them: not-a-subid\./, 'and names the line that is not a subid');
        expect.eq(live(C2), '0', 'its conversion is gone');
      },
    },

    {
      name: 'Update Subids: one marked, the rest listed',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(UPDATE + 'subids.php');
        await ui.fill({ '#subids': '' });
        expect.notOk((await ui.validity('#subids')).valid, 'an empty list is stopped by the browser first');
        await ui.fill({ '#subids': String(C3) + '\n999999999' });
        await app.submit('#update-subids button[type="submit"]');
        const flashes = (await app.flashes()).join(' | ');
        expect.match(flashes, /1 subid\(s\) marked as converted\./, 'one subid is marked');
        expect.match(flashes, /Not found in your account, so not marked: 999999999\./, 'and the unknown one is listed');
        expect.eq(await ui.value('#subids'), '', 'the box is empty for the next report');
        expect.eq(db.value('SELECT click_lead FROM 202_clicks WHERE click_id=' + C3), '1', 'C3 is a lead');
      },
    },

    {
      name: 'Upload Revenue Reports: a file through the input, the columns guessed, the lines accounted for',
      async run(ctx) {
        const { app, ui, db, expect, shot } = ctx;
        await app.goto(UPDATE + 'upload.php');
        expect.notOk((await ui.validity('#csv')).valid, 'the browser asks for a file before it uploads');
        const csv = 'Date,Sub ID,Commission\n2021-04-14,' + C1 + ',1.50\n2021-04-14,' + C1 + ',0.25\n2021-04-14,888888888,4\n';
        await ui.page.setInputFiles('#csv', { name: 'u5-browser.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
        await app.submit('#upload-report button[type="submit"]');
        expect.match(ui.page.url(), /upload\.php\?case=1&file=[0-9a-f]+\.csv$/, 'the upload goes on to the column picker');
        expect.ok(await ui.page.isChecked('input[name="click_id"][value="1"]'), 'Sub ID is chosen as the subid column');
        expect.ok(await ui.page.isChecked('input[name="click_payout"][value="2"]'), 'Commission as the commission column');
        expect.match(await ui.text('.p202-decided'), /Chosen from the column names/, 'and the page says it chose them');
        await shot('update-upload-columns');
        await app.submit('#upload-columns button[type="submit"]');
        expect.match((await app.flashes()).join(' '), /Your report has been uploaded: 2 line\(s\) recorded, 1 skipped \(listed below\)\./, 'the result counts recorded and skipped lines');
        expect.eq(await ui.text('#upload-totals tbody tr td.num'), '$1.75', 'the subid earned the sum of its lines');
        expect.match(await ui.text('#upload-skipped tbody'), /888888888.*no click with this subid in your account/s, 'the skipped line is listed with its reason');
        expect.eq(db.value('SELECT click_payout FROM 202_clicks WHERE click_id=' + C1), '1.75000', 'and the click is worth it');
        await checks.tablesScrollThemselves(ctx);
      },
    },
  ],
};
