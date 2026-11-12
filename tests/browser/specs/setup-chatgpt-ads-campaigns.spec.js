'use strict';

/*
 * Setup › Destinations › ChatGPT Ads › campaigns: a connection's campaigns
 * as OpenAI has them, paused and activated (each behind a confirm), the
 * tracking template pushed, ad groups and ads with their review status, and
 * yesterday's spend. Needs the stand-in Advertiser API as
 * setup-chatgpt-ads.spec.js does, reset before the run.
 */

const checks = require('../lib/checks');

const PAGE = '/tracking202/setup/destinations.php';
const KEY = 'sk-ads-live-demo-key-0001';

module.exports = {
  name: 'setup-chatgpt-ads-campaigns',
  title: 'Setup › Destinations › ChatGPT Ads campaigns',

  async reset(db) {
    db.truncate(['202_ad_connections']);
  },

  async setup(ctx) {
    const { app, ui, db, state } = ctx;
    await app.login();
    await app.goto(PAGE);
    await ui.fill({ '#ad_api_key': KEY });
    await app.submit('button:has-text("Connect ChatGPT Ads")');
    state.connection = db.value('SELECT connection_id FROM 202_ad_connections WHERE deleted_at IS NULL LIMIT 1');
  },

  scenarios: [
    {
      name: 'The campaigns view lists, pauses and pushes the template',
      async run(ctx) {
        const { app, ui, expect, shot, state } = ctx;
        await app.goto(PAGE);
        await ui.clickThrough('#ac' + state.connection + ' a:has-text("campaigns")');
        expect.ok(await ui.visible('#ad-campaigns'), 'the campaigns view opens');
        expect.match(await ui.text('#ad-campaigns'), /Spring sale[\s\S]*Trial sign-ups/, 'both campaigns are listed');
        expect.match(await ui.text('#ad-campaigns'), /Yesterday .* spent USD 33\.28/, 'yesterday\'s spend is shown');
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.tablesScrollThemselves(ctx);

        const pause = '#c-cmpn_101 button:has-text("pause")';
        await app.confirmAnd('dismiss', pause);
        expect.match(await ui.text('#c-cmpn_101'), /active/, 'saying no leaves it active');
        await app.confirmAnd('accept', pause);
        expect.match(await app.messages(), /cmpn_101 is paused at OpenAI/, 'the page says it paused');
        expect.match(await ui.text('#c-cmpn_101'), /paused/, 'the list reads it back paused');
        await app.confirmAnd('accept', '#c-cmpn_101 button:has-text("activate")');
        expect.match(await ui.text('#c-cmpn_101'), /active/, 'and active again');

        const push = '#c-cmpn_102 button:has-text("push template")';
        if (await ui.exists(push)) {
          await app.confirmAnd('accept', push);
          expect.match(await app.messages(), /tracking template is now the Get Links one/, 'the template is pushed');
        }
        expect.match(await ui.text('#c-cmpn_102'), /the Get Links one/, 'the list reads the template back');
        await ui.clickThrough('#c-cmpn_101 a:has-text("Spring sale")');
        expect.match(await ui.text('#ad-campaigns'), /Running shoes[\s\S]*Road runner[\s\S]*rejected landing page unavailable/, 'ad groups and ads with their review');
        await shot('campaigns');
      },
    },

    {
      name: 'The campaigns view at a phone width and in dark mode',
      async run(ctx) {
        const { app, state, withSession } = ctx;
        await app.goto(PAGE + '?ad_campaigns=' + state.connection);
        await checks.atWidths(ctx, [390], async () => {});
        await withSession({ colorScheme: 'dark' }, async (dark) => {
          await dark.app.goto(PAGE + '?ad_campaigns=' + state.connection);
          await checks.darkThemeApplies({ ...ctx, ui: dark.ui, page: dark.page });
        });
      },
    },

  ],
};
