'use strict';

/*
 * The Setup family on the v2 shell (U4), driven the way a person drives it.
 *
 * tests/live/setup-pages.sh proves each form posts what its handler reads,
 * by posting and reading the rows. This proves what only a browser can: the
 * pages render on the right shell in both themes and at phone width, every
 * form submits through its own button and shows the server's sentence under
 * the field it names, and the page scripts — sections that swap with a
 * radio, selects filtered by another select, rows added and renumbered,
 * answers posted in place, confirms that confirm — do what they claim.
 *
 * Anything that claims to have stored something reads the database too.
 */

const path = require('path');
const checks = require('../lib/checks');

const SETUP = '/tracking202/setup/';
const SETUP_TABLES = [
  '202_aff_networks', '202_aff_campaigns', '202_ppc_networks', '202_ppc_accounts', '202_ppc_account_pixels',
  '202_ppc_network_variables', '202_landing_pages', '202_text_ads', '202_rotators', '202_rotator_rules',
  '202_rotator_rules_criteria', '202_rotator_rules_redirects', '202_trackers', '202_notification_correction_urls',
];

/** Click a control guarded by a confirm, accept it, and wait for a condition rather than a navigation. */
async function acceptConfirm(ctx, selector, until, describe) {
  const { session, ui } = ctx;
  session.expectDialog('accept');
  try {
    await ui.page.click(selector);
    await ui.until(until, { describe });
  } finally {
    session.endDialogExpectation();
  }
  return session.lastDialog;
}

module.exports = {
  name: 'setup-pages',
  title: 'Setup (U4): every page on v2',

  // The reset truncates the setup tables (trackers among them) that the
  // shared fixture's readers use, so this runs after them.
  replacesFixture: true,

  async reset(db) {
    db.truncate(SETUP_TABLES);
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Every Setup page meets the v2 baseline, at 1280px and 390px, light',
      async run(ctx) {
        const { app, expect, shot } = ctx;
        for (const entry of checks.SETUP_PAGES) {
          const page = entry.path.split('/').pop().replace('.php', '');
          expect.section(entry.path);
          await app.goto(entry.path);
          await checks.pageBaseline(ctx, entry);
          await shot(page + '-1280-light');
          await checks.atWidths(ctx, [390], async () => {
            await checks.flexContainersKeepTheirSpaces(ctx);
            if (entry.menu) {
              await checks.currentSubMenuItemIsVisible(ctx);
            }
            await shot(page + '-390-light');
          });
        }
      },
    },

    {
      name: 'Every Setup page is dark in dark mode, at 1280px and 390px',
      async run(ctx) {
        const { withSession, expect } = ctx;
        for (const width of [1280, 390]) {
          await withSession({ viewport: { width, height: 900 }, colorScheme: 'dark' }, async (dark) => {
            const darkCtx = Object.assign({}, ctx, dark);
            for (const entry of checks.SETUP_PAGES) {
              expect.section(entry.path + ' dark at ' + width + 'px');
              await dark.app.goto(entry.path);
              await checks.baseline(darkCtx);
              await checks.darkThemeApplies(darkCtx);
              await checks.flexContainersKeepTheirSpaces(darkCtx);
              await dark.page.screenshot({
                path: path.join(ctx.config.shots, 'setup-pages-' + entry.path.split('/').pop().replace('.php', '') + '-' + width + '-dark.png'),
                fullPage: true,
              });
            }
          });
        }
      },
    },

    {
      name: 'Traffic sources: the first step, a refusal under its field, a source and an account with a pixel',
      async run(ctx) {
        const { app, ui, db, expect, shot } = ctx;
        await app.goto(SETUP + 'ppc_accounts.php');
        expect.ok(await ui.visible('text=No traffic sources yet'), 'an empty account shows the empty state');
        expect.notOk(await ui.exists('#account-form'), 'and no account form until there is a source to put it under');

        // Spaces pass the browser's `required` and reach the server, whose
        // sentence has to appear under the field.
        await ui.fill({ '#ppc_network_name': '   ' });
        await app.submit('#source-form button[type="submit"]');
        expect.includes(await app.fieldErrors(), 'Type in the name the traffic source.', 'the server refuses a blank name in its own words');
        expect.ok(await ui.exists('#ppc_network_name.is-invalid'), 'on the field it names');
        expect.eq(db.count('202_ppc_networks'), 0, 'and writes nothing');

        await ui.fill({ '#ppc_network_name': 'EVAL Search Ads' });
        await app.submit('#source-form button[type="submit"]');
        expect.match((await app.flashes()).join(' '), /Traffic source added/, 'adding a source says so');
        expect.eq(db.count('202_ppc_networks', "ppc_network_name='EVAL Search Ads'"), 1, 'and stores it');
        expect.ok(await ui.exists('#account-form'), 'the account form appears once there is a source');
        expect.eq(await ui.value('#ppc_network_id'), String(db.value("SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_name='EVAL Search Ads'")),
          'with the only source already chosen');

        expect.eq(await app.disclosureOpen('#account-form details'), false, 'pixels wait under a closed Advanced');
        await app.openDisclosure('#account-form details');
        await ui.click('button:has-text("Add a pixel")');
        expect.eq(await ui.count('#pixel-rows [data-p202-row]'), 2, 'Add a pixel adds a row');
        const ids = await ui.page.$$eval('#pixel-rows select', (els) => els.map((e) => e.id));
        expect.eq(new Set(ids).size, ids.length, 'with its own ids', ids.join(','));
        await ui.click('#pixel-rows [data-p202-remove-row]');
        expect.eq(await ui.count('#pixel-rows [data-p202-row]'), 1, 'and Remove this pixel takes it away');

        await ui.fill({ '#ppc_account_name': 'eval-account-1', '#pixel-code-0': 'https://pixel.example/p.gif' });
        await ui.select('#pixel-type-0', '1');
        await app.submit('#account-form button[type="submit"]');
        expect.eq(db.count('202_ppc_accounts', "ppc_account_name='eval-account-1'"), 1, 'the account is stored');
        expect.eq(db.value("SELECT pixel_code FROM 202_ppc_account_pixels"), 'https://pixel.example/p.gif', 'with its pixel');
        await shot('traffic-sources');
      },
    },

    {
      name: 'Traffic sources: a postback pixel\'s correction URLs, one per pixel URL',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        const account = db.value("SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name='eval-account-1'");
        await app.goto(SETUP + 'ppc_accounts.php?edit_ppc_account_id=' + account);
        if (await app.disclosureOpen('#account-form details') === false) {
          await app.openDisclosure('#account-form details');
        }
        await ui.select('#pixel-type-0', '4');
        await ui.fill({
          '#pixel-code-0': 'https://pb.example/a?t=[[subid]] https://pb.example/b?t=[[subid]]',
          '#pixel-correction-0': 'https://pb.example/c https://pb.example/d https://pb.example/e',
        });
        await app.submit('#account-form button[type="submit"]');
        expect.match(await app.messages(), /matched by position/, 'three correction URLs for a two-URL pixel are refused, saying why');
        expect.eq(await ui.value('#pixel-correction-0'), 'https://pb.example/c https://pb.example/d https://pb.example/e', 'keeping what was typed');
        expect.eq(db.count('202_notification_correction_urls'), 0, 'and storing none');

        if (await app.disclosureOpen('#account-form details') === false) {
          await app.openDisclosure('#account-form details');
        }
        await ui.fill({ '#pixel-correction-0': 'https://pb.example/c?v=[[p202_goal_value]] https://pb.example/d' });
        await app.submit('#account-form button[type="submit"]');
        expect.eq(db.value('SELECT correction_url FROM 202_notification_correction_urls'), 'https://pb.example/c?v=[[p202_goal_value]] https://pb.example/d',
          'one per pixel URL is stored against the pixel');
        await app.goto(SETUP + 'ppc_accounts.php?edit_ppc_account_id=' + account);
        expect.eq(await ui.value('#pixel-correction-0'), 'https://pb.example/c?v=[[p202_goal_value]] https://pb.example/d', 'and shown when the account is edited again');
      },
    },

    {
      name: 'Traffic sources: the custom-variables dialog saves through the endpoint',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(SETUP + 'ppc_accounts.php');
        await ui.click('button:has-text("variables")');
        await ui.until(() => ui.visible('#variables-modal [data-var="name"]'), { describe: 'the dialog to open with one empty row' });
        await ui.fill({
          '#variables-modal [data-var="name"]': 'Keyword',
          '#variables-modal [data-var="parameter"]': 'kw',
          '#variables-modal [data-var="placeholder"]': '{keyword}',
        });
        await ui.clickThrough('#variables-modal [data-variables-save]');
        expect.match((await app.flashes()).join(' '), /Custom variables saved/, 'saving reloads with a sentence');
        expect.eq(db.value("SELECT CONCAT(name,'|',parameter,'|',placeholder) FROM 202_ppc_network_variables WHERE deleted=0"), 'Keyword|kw|{keyword}',
          'and the variable is stored');

        await ui.click('button:has-text("variables")');
        await ui.until(async () => (await ui.value('#variables-modal [data-var="placeholder"]')) === '{keyword}', { describe: 'the dialog to show the saved variable' });
        expect.ok(true, 'reopening the dialog shows what was saved');
        await ui.click('#variables-modal [data-bs-dismiss="modal"]');
      },
    },

    {
      name: 'Categories: add, rename, and a remove that asks first',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(SETUP + 'aff_networks.php');
        await ui.fill({ '#aff_network_name': 'EVAL Offer Network' });
        await app.submit('#category-form button[type="submit"]');
        expect.eq(db.count('202_aff_networks', "aff_network_name='EVAL Offer Network'"), 1, 'the category is stored');
        await ui.fill({ '#aff_network_name': 'Doomed Network' });
        await app.submit('#category-form button[type="submit"]');

        await ui.clickThrough('#category-list li:has-text("EVAL Offer Network") a:has-text("edit")');
        expect.eq(await ui.value('#aff_network_name'), 'EVAL Offer Network', 'edit fills the form');
        await ui.fill({ '#aff_network_name': 'EVAL Network' });
        await app.submit('#category-form button[type="submit"]');
        expect.eq(db.count('202_aff_networks', "aff_network_name='EVAL Network'"), 1, 'the rename is stored on the same row');
        expect.eq(db.count('202_aff_networks'), 2, 'with no row added');

        const doomed = '#category-list li:has-text("Doomed Network") button:has-text("remove")';
        const said = await app.confirmAnd('dismiss', doomed);
        expect.match(said, /Remove the category "Doomed Network"/, 'remove asks first, naming the category');
        expect.eq(db.count('202_aff_networks', "aff_network_deleted=1"), 0, 'and dismissing keeps it');
        await app.confirmAnd('accept', doomed);
        expect.eq(db.count('202_aff_networks', "aff_network_name='Doomed Network' AND aff_network_deleted=1"), 1, 'accepting removes it');
        expect.match((await app.flashes()).join(' '), /Category removed/, 'and says so');
      },
    },

    {
      name: 'Campaigns: server sentences under their fields, placeholders at the caret, then a saved campaign',
      async run(ctx) {
        const { app, ui, db, expect, shot } = ctx;
        await app.goto(SETUP + 'aff_campaigns.php');
        expect.eq(await app.disclosureOpen('#campaign-form details[data-p202-remember="setup-campaigns-advanced"]'), false, 'Advanced starts closed');
        expect.eq(await ui.value('#aff_network_id'), String(db.value("SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_deleted=0")),
          'the only live category is chosen for you');

        await ui.fill({ '#aff_campaign_name': 'EVAL Campaign A', '#aff_campaign_url': 'offer.example/?sub=', '#aff_campaign_payout': 'abc' });
        await app.submit('#addCampaign');
        const errors = await app.fieldErrors();
        expect.includes(errors, 'Please enter in a numeric number for the payout.', 'a payout that is not a number is refused in the server\'s words');
        expect.ok(errors.some((e) => /must start with http:\/\/ or https:\/\//.test(e)), 'and a URL without a scheme', errors.join(' | '));
        expect.ok(await ui.exists('#aff_campaign_payout.is-invalid'), 'each under its own field');
        expect.eq(await ui.value('#aff_campaign_name'), 'EVAL Campaign A', 'what was typed is kept');
        expect.eq(db.count('202_aff_campaigns'), 0, 'and nothing is stored');
        await shot('campaign-refused');

        await ui.fill({ '#aff_campaign_url': 'https://offer.example/?sub=', '#aff_campaign_payout': '12.50' });
        await ui.page.$eval('#aff_campaign_url', (el) => { el.focus(); el.setSelectionRange(el.value.length, el.value.length); });
        await app.openDisclosure('#campaign-form details[data-p202-remember="setup-campaigns-placeholders"]');
        await ui.click('button[data-p202-insert="[[subid]]"]');
        expect.eq(await ui.value('#aff_campaign_url'), 'https://offer.example/?sub=[[subid]]', 'a placeholder goes in where the cursor was');
        await app.submit('#addCampaign');
        expect.eq(db.value("SELECT CONCAT(aff_campaign_url,'|',aff_campaign_payout) FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'"),
          'https://offer.example/?sub=[[subid]]|12.50', 'the campaign is stored as typed');
        expect.match((await app.flashes()).join(' '), /Campaign added/, 'and the page says so');
      },
    },

    {
      name: 'Landing pages: the type decides which fields post',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(SETUP + 'landing_pages.php');
        expect.ok(await ui.visible('#aff_campaign_id'), 'a simple page asks for its campaign');
        await ui.check('#landing_page_type2');
        await ui.until(async () => !(await ui.visible('#aff_campaign_id')), { describe: 'the campaign field to go' });
        expect.ok(await ui.page.$eval('#aff_campaign_id', (el) => el.disabled), 'an advanced page does not post a campaign');
        await ui.fill({ '#landing_page_nickname': 'EVAL Advanced LP', '#landing_page_url': 'https://lp.example/adv' });
        await app.submit('#addedLp');
        expect.eq(db.value("SELECT CONCAT(landing_page_type,'|',aff_campaign_id) FROM 202_landing_pages WHERE landing_page_nickname='EVAL Advanced LP'"), '1|0',
          'an advanced page is stored with no campaign');

        await ui.check('#landing_page_type1');
        await ui.select('#aff_campaign_id', String(db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'")));
        await ui.fill({ '#landing_page_nickname': 'EVAL LP', '#landing_page_url': 'https://lp.example/quiz' });
        await app.submit('#addedLp');
        expect.eq(db.count('202_landing_pages', "landing_page_nickname='EVAL LP' AND landing_page_type=0 AND aff_campaign_id>0"), 1, 'a simple page is stored under its campaign');
      },
    },

    {
      name: 'Text ads: the preview follows the typing',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(SETUP + 'text_ads.php');
        await ui.fill({ '#text_ad_headline': 'Cruise to Mars' });
        await ui.until(async () => (await ui.text('[data-p202-mirror="#text_ad_headline"]')) === 'Cruise to Mars', { describe: 'the preview headline to follow' });
        expect.ok(true, 'the preview shows the headline as it is typed');
        await ui.fill({ '#text_ad_name': 'EVAL Ad', '#text_ad_description': 'Save big', '#text_ad_display_url': 'example.com' });
        await app.submit('#addedTextAd');
        expect.eq(db.count('202_text_ads', "text_ad_name='EVAL Ad' AND text_ad_type=0 AND aff_campaign_id>0"), 1, 'the ad is stored under the campaign');
      },
    },

    {
      name: 'Redirector: a new one opens in the editor, and its rules save',
      async run(ctx) {
        const { app, ui, db, expect, state, shot } = ctx;
        await app.goto(SETUP + 'rotator.php');
        await ui.fill({ '#rotator_name': 'EVAL Redirector' });
        await app.submit('#addRotator');
        state.rotator = db.value("SELECT id FROM 202_rotators WHERE name='EVAL Redirector'");
        expect.match(ui.page.url(), new RegExp('rotator_id=' + state.rotator), 'adding a redirector opens its rules');
        expect.ok(await ui.exists('[data-rule-id="none"]'), 'with a first, empty rule');

        await ui.select('#default_type_select', 'url');
        expect.ok(await ui.visible('input[name="default_url"]'), 'choosing URL shows the URL field');
        expect.notOk(await ui.visible('select[name="default_campaign"]'), 'and hides the campaign');
        await ui.fill({
          'input[name="default_url"]': 'https://default.example/',
          '[data-rule-field="rule_name"]': 'US visitors',
          '[data-rule-field="value"]': 'US,GB',
        });
        await ui.select('[data-rule] [data-rule-field="redirect_type"]', 'url');
        await ui.fill({ '[data-rule] [data-rule-field="redirect_url"]': 'https://us.example/' });

        // The device type offers its four values from the page itself.
        await ui.click('[data-add-criterion]');
        await ui.select('[data-criteria]:nth-child(2) [data-rule-field="type"]', 'device');
        expect.eq(await ui.attr('[data-criteria]:nth-child(2) [data-rule-field="value"]', 'list'), 'rotator-devices', 'a device criterion suggests the four device types');
        await ui.fill({ '[data-criteria]:nth-child(2) [data-rule-field="value"]': 'mobile' });

        await ui.clickThrough('#post_rules');
        expect.match((await app.flashes()).join(' '), /Rules saved/, 'saving the rules reloads with a sentence');
        expect.eq(db.value('SELECT default_url FROM 202_rotators WHERE id=' + state.rotator), 'https://default.example/', 'the default is stored');
        expect.eq(db.value("SELECT GROUP_CONCAT(CONCAT(type,':',value) ORDER BY id) FROM 202_rotator_rules_criteria WHERE rotator_id=" + state.rotator),
          'country:US,GB,device:mobile', 'both criteria are stored');
        expect.eq(db.value('SELECT redirect_url FROM 202_rotator_rules_redirects'), 'https://us.example/', 'and the destination');

        // A split test: two destinations with weights.
        await ui.check('[data-rule] [data-rule-field="split"]');
        await ui.fill({ '[data-rule] [data-redirect]:nth-child(1) [data-rule-field="weight"]': '70' });
        await ui.click('[data-rule] [data-add-redirect]');
        await ui.select('[data-rule] [data-redirect]:nth-child(2) [data-rule-field="redirect_type"]', 'url');
        await ui.fill({
          '[data-rule] [data-redirect]:nth-child(2) [data-rule-field="redirect_url"]': 'https://b.example/',
          '[data-rule] [data-redirect]:nth-child(2) [data-rule-field="weight"]': '30',
        });
        await ui.clickThrough('#post_rules');
        expect.eq(db.value("SELECT GROUP_CONCAT(CONCAT(redirect_url,'@',weight) ORDER BY id) FROM 202_rotator_rules_redirects"),
          'https://us.example/@70,https://b.example/@30', 'a split test stores each destination with its weight');
        expect.eq(db.value('SELECT splittest FROM 202_rotator_rules'), '1', 'and the rule is a split test');
        await shot('redirector');
      },
    },

    {
      name: 'Redirector: country values are suggested from the upstream list',
      async run(ctx) {
        const { app, ui, expect, state } = ctx;
        await app.goto(SETUP + 'rotator.php?rotator_id=' + state.rotator);
        await ui.fill({ '[data-criteria]:nth-child(1) [data-rule-field="value"]': 'US,uni' });
        const offered = await ui.until(async () => {
          const values = await ui.page.$$eval('[data-criteria]:nth-child(1) [data-rule-field="value"]', (els) => {
            const list = els[0].list;
            return list ? Array.from(list.options).map((o) => o.value) : [];
          });
          return values.length ? values : false;
        }, { describe: 'the suggestion list to fill', timeout: 8000 }).catch(() => null);
        if (offered === null) {
          expect.skip('suggestions keep what was typed before the comma', 'the upstream suggestion service did not answer from this instance');
          return;
        }
        expect.ok(offered.every((v) => v.startsWith('US,')), 'each suggestion keeps the values already typed', offered.slice(0, 3).join(' | '));
      },
    },

    {
      name: 'Get Links: one grouped campaign list, filtered landing pages, the link in place, and remove',
      async run(ctx) {
        const { app, ui, db, expect, shot } = ctx;
        const campaign = String(db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'"));
        const network = String(db.value('SELECT aff_network_id FROM 202_aff_campaigns WHERE aff_campaign_id=' + campaign));
        await app.goto(SETUP + 'get_trackers.php');
        expect.eq(await ui.count('#aff_campaign_id optgroup'), 1, 'campaigns are one select, grouped by category');
        await ui.select('#aff_campaign_id', campaign);
        expect.eq(await ui.value('input[name="aff_network_id"]'), network, 'the category follows the campaign');
        await ui.select('#method_of_promotion', 'landingpage');
        const pages = await ui.texts('#landing_page_id option');
        expect.ok(pages.includes('EVAL LP') && pages.length === 2, 'the landing pages are this campaign\'s', pages.join(' | '));
        await ui.select('#landing_page_id', String(db.value("SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_nickname='EVAL LP'")));
        await ui.select('#text_ad_id', String(db.value("SELECT text_ad_id FROM 202_text_ads WHERE text_ad_name='EVAL Ad'")));
        expect.eq(await ui.text('#ad-preview [data-ad="headline"]'), 'Cruise to Mars', 'the ad preview shows the chosen ad');

        await ui.click('#get-links');
        await ui.until(() => ui.exists('#tracking-links .p202-code'), { describe: 'the link to appear in place' });
        expect.match(await ui.text('#tracking-links .p202-code__value'), /lp\.example\/quiz\?t202id=\d+/, 'the landing-page link appears, ready to copy');
        expect.eq(db.count('202_trackers'), 1, 'and the tracker is stored');

        await app.goto(SETUP + 'get_trackers.php');
        const id = String(db.value('SELECT tracker_id FROM 202_trackers'));
        await acceptConfirm(ctx, '[data-delete-tracker="' + id + '"]', async () => !(await ui.exists('[data-tracker-id="' + id + '"]')), 'the row to go');
        expect.eq(db.count('202_trackers'), 0, 'remove asks, then deletes the tracker');
        await shot('get-links');
      },
    },

    {
      name: 'Get LP Code: the simple code, and advanced offers numbered without gaps',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(SETUP + 'get_simple_landing_code.php');
        await ui.select('#aff_campaign_id', String(db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'")));
        await ui.select('#landing_page_id', String(db.value("SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_nickname='EVAL LP'")));
        await ui.click('#generate-tracking-link-simple');
        await ui.until(() => ui.exists('#tracking-links .p202-code'), { describe: 'the code to appear' });
        expect.match(await ui.bodyText(), /redirect\/go\.php\?lpip=/, 'the simple code includes the outbound link');

        await app.goto(SETUP + 'get_adv_landing_code.php');
        await ui.click('button:has-text("Add another offer")');
        await ui.click('button:has-text("Add another offer")');
        expect.eq(await ui.value('[data-lp-counter]'), '2', 'three offers make the counter 2');
        await ui.click('#lp-offers [data-lp-offer]:nth-child(2) [data-p202-remove-row]');
        const names = await ui.page.$$eval('#lp-offers select', (els) => els.map((e) => e.name));
        expect.eq(names.join(','), 'aff_campaign_id_1,rotator_id_1,aff_campaign_id_2,rotator_id_2', 'removing the middle offer renumbers the rest', names.join(','));
        expect.eq(await ui.value('[data-lp-counter]'), '1', 'and the counter follows');

        await ui.select('#landing_page_id', String(db.value("SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_nickname='EVAL Advanced LP'")));
        await ui.select('#aff_campaign_id_1', String(db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'")));
        await ui.check('#offer_type22');
        await ui.select('#rotator_id_2', String(db.value("SELECT id FROM 202_rotators WHERE name='EVAL Redirector'")));
        await ui.click('#generate-tracking-link-adv');
        await ui.until(() => ui.exists('#tracking-links .p202-code'), { describe: 'the code to appear' });
        const body = await ui.bodyText();
        expect.match(body, /go\.php\?acip=/, 'the campaign offer gets its outbound link');
        expect.match(body, /go\.php\?rpi=/, 'and the redirector offer its own');
      },
    },

    {
      name: 'Postback / Pixel: the snippets follow the choices',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(SETUP + 'get_postback.php');
        expect.match(await ui.text('[data-postback-decided]'), /http:\/\//, 'the protocol is decided from how the page was served, and said');
        await ui.fill({ '#subid_value': '{aff_sub}' });
        await ui.until(async () => /subid=\{aff_sub\}/.test(await ui.text('#unsecure_postback')), { describe: 'the postback URL to follow the sub id' });
        expect.ok(true, 'the postback URL carries the sub id as it is typed');
        expect.eq(await ui.attr('#unsecure_postback + [data-p202-copy]', 'data-p202-copy'), await ui.text('#unsecure_postback'), 'and Copy copies what is shown');

        await ui.check('#pixel_type1');
        const campaign = String(db.value("SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'"));
        await ui.select('#aff_campaign_id', campaign);
        await ui.until(async () => new RegExp('cid=' + campaign + '&').test(await ui.text('#unsecure_pixel_2')), { describe: 'the advanced pixel to carry the campaign' });
        expect.ok(true, 'the advanced pixel carries the chosen campaign');

        await app.openDisclosure('details[data-p202-remember="setup-postback-advanced"]');
        await ui.check('#secure_type1');
        expect.match(await ui.text('#unsecure_pixel_2'), /src="https:\/\//, 'choosing https:// rewrites the snippets');
        expect.match(await ui.text('[data-postback-decided]'), /as you chose under Advanced/, 'and the decided line says who chose');
      },
    },

    {
      name: 'Nothing broke along the way',
      async run(ctx) {
        const { expect, session } = ctx;
        expect.eq(session.unexpectedDialogs.length, 0, 'no confirm appeared that the pass did not ask for', session.unexpectedDialogs.join(' | '));
      },
    },
  ],
};
