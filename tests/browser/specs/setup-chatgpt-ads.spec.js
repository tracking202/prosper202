'use strict';

/*
 * Setup › Destinations › ChatGPT Ads, driven the way a person drives it:
 * paste an Advertiser API key, check it was verified and sealed, run setup,
 * and delete the connection.
 *
 * Needs the instance pointed at the stand-in Advertiser API
 * (tests/fixtures/openai-ads/fake_openai_ads.py, started with
 * --api-key sk-ads-live-demo-key-0001 and reset before the run;
 * P202_OPENAI_ADS_ENDPOINT and P202_OPENAI_ADS_CA_FILE on the server).
 * Every claim that something was stored is read back from the database, and
 * the key is looked for in every page this spec sees.
 */

const checks = require('../lib/checks');

const PAGE = '/tracking202/setup/destinations.php';
const KEY = 'sk-ads-live-demo-key-0001';

async function keyIsNowhere(ctx, what) {
  const html = await ctx.ui.html();
  ctx.expect.notOk(html.includes(KEY), 'the Advertiser API key is not in the page (' + what + ')');
  ctx.expect.notOk(/capi-demo-\d+/.test(html), 'nor the Conversions API key (' + what + ')');
}

module.exports = {
  name: 'setup-chatgpt-ads',
  title: 'Setup › Destinations › ChatGPT Ads',

  async reset(db) {
    db.truncate(['202_ad_connections', '202_destinations', '202_destination_deliveries']);
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Reaching the page and its ChatGPT Ads panel',
      async run(ctx) {
        const { app, ui, expect } = ctx;
        await app.goto('/tracking202/setup/');
        await app.openFromSubMenu('Destinations');
        expect.eq(new URL(ui.page.url()).pathname, PAGE, 'the sub-menu leads to the page');
        expect.ok(await ui.visible('#chatgpt-ads'), 'the ChatGPT Ads panel is there');
        expect.ok(await ui.exists('#chatgpt-ads input[type="password"][name="api_key"]'), 'the key is typed into a password field');
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);
      },
    },

    {
      name: 'A key OpenAI refuses is said so and not stored',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await ui.fill({ '#ad_api_key': 'sk-ads-wrong-key-0000' });
        await app.submit('button:has-text("Connect ChatGPT Ads")');
        expect.match(await app.messages(), /OpenAI refused that key/, 'the refusal names OpenAI');
        expect.eq(db.count('202_ad_connections'), 0, 'nothing was stored');
        expect.eq(await ui.value('#ad_api_key'), '', 'the key is not put back into the form');
      },
    },

    {
      name: 'Connecting fills the account from OpenAI',
      async run(ctx) {
        const { app, ui, db, expect, shot, state } = ctx;
        await ui.fill({ '#ad_api_key': KEY });
        await app.submit('button:has-text("Connect ChatGPT Ads")');
        expect.match(await app.messages(), /ChatGPT Ads connected/, 'the page says it connected');
        expect.eq(db.value("SELECT name FROM 202_ad_connections WHERE deleted_at IS NULL"), 'Northwind Outfitters', 'the account name came from OpenAI');
        expect.match(db.value('SELECT credentials FROM 202_ad_connections LIMIT 1'), /^v1\./, 'the key is stored sealed');
        expect.match(await ui.text('#chatgpt-ads'), /Northwind Outfitters/, 'the panel lists it');
        state.connection = db.value('SELECT connection_id FROM 202_ad_connections LIMIT 1');
        await keyIsNowhere(ctx, 'after connecting');
        await shot('connected');
      },
    },

    {
      name: 'Run setup makes the pixel, key, destination and event setting',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        const form = '#ac' + state.connection + ' form:has(input[value="ad_setup"])';
        await app.openDisclosure(form + ' .p202-disclosure');
        expect.ok(await ui.visible(form + ' select[name="event_types[]"]'), 'Advanced offers the event types');
        await app.submit(form + ' button:has-text("Run setup")');
        const text = await ui.text('#ac' + state.connection);
        expect.match(text, /pixel: (created|found)/, 'the pixel step is shown');
        expect.match(text, /conversions key: (created|kept)/, 'the key step is shown');
        expect.match(text, /destination: created/, 'the destination step is shown');
        expect.match(text, /event settings: (created|found)/, 'the event settings step is shown');
        expect.eq(db.value("SELECT type FROM 202_destinations WHERE deleted_at IS NULL"), 'openai_ads', 'a ChatGPT Ads destination exists');
        await keyIsNowhere(ctx, 'after setup');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'Deleting the connection asks first',
      async run(ctx) {
        const { app, db, expect, state } = ctx;
        await app.goto(PAGE);
        const selector = '#ac' + state.connection + ' button:has-text("delete")';
        await app.confirmAnd('dismiss', selector);
        expect.eq(db.count('202_ad_connections', 'deleted_at IS NULL'), 1, 'saying no keeps it');
        await app.confirmAnd('accept', selector);
        expect.eq(db.count('202_ad_connections', 'deleted_at IS NULL'), 0, 'saying yes deletes it');
        expect.eq(db.value("SELECT credentials FROM 202_ad_connections LIMIT 1"), '', 'its keys are erased');
      },
    },
  ],
};
