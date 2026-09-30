'use strict';

/*
 * The landing-page half of identity capture (plan §6.2), in a browser,
 * because all of it is JavaScript a request never runs: landing.php keeps a
 * first-party visitor id in the landing page's own localStorage, sends it
 * with the pageview beacon, adds it to links into the tracker when they are
 * followed, and p202.consent(false) withdraws all of it.
 *
 * The landing page is served by a server this spec starts on loopback and
 * opened as `localhost`, while the tracker is `127.0.0.1`: two sites, so the
 * beacon is the cross-site request it is in production. (A routed page will
 * not do: Chromium puts a fulfilled response in the "unknown" address space
 * and its Private Network Access check then blocks the tracker's script.)
 * Everything a scenario claims about linking is read back from the database.
 */

const http = require('http');

const OFFER = 'http://offer.example/';
const CAMP = 940011;
const TRACKER = 940211;
const LP_PUBLIC = 940311;
// A second campaign with identity capture off, and a landing page on it.
const CAMP_OFF = 940012;
const LP_OFF = 940312;

/**
 * The landing page, by path: `/` the plain snippet; `/preload` a consent
 * tool's refusal set before the snippet; `/late` a refusal from a script
 * after it; `/off` a page on the capture-off campaign.
 */
function landingHtml(base, path) {
  const lpip = path.startsWith('/off') ? LP_OFF : LP_PUBLIC;
  const before = path.startsWith('/preload') ? '<script>window.p202 = {consent: false};</script>' : '';
  const after = path.startsWith('/late') ? '<script>window.p202.consent(false);</script>' : '';
  return '<!doctype html><html><head><title>LP</title>' + before +
    '<script src="' + base + '/tracking202/static/landing.php?lpip=' + lpip + '"></script>' + after +
    '</head><body><h1>Landing</h1>' +
    '<a id="go" href="' + base + '/tracking202/redirect/dl.php?t202id=' + TRACKER + '">Get the offer</a>' +
    '<a id="away" href="https://elsewhere.example/page">Elsewhere</a>' +
    '</body></html>';
}

/** Serve the landing page on loopback; returns its origin as `localhost`. */
async function landingServer(ctx) {
  if (ctx.state.lpOrigin) {
    return ctx.state.lpOrigin;
  }
  const base = ctx.config.base.replace(/\/$/, '');
  const server = http.createServer((req, res) => {
    res.setHeader('content-type', 'text/html');
    res.end(landingHtml(base, req.url || '/'));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  server.unref();
  ctx.state.lpServer = server;
  ctx.state.lpOrigin = 'http://localhost:' + server.address().port;
  // The harness aborts every host but the instance's; this one is ours. A
  // continued request is a real fetch, so the page keeps a real address.
  await ctx.page.route(ctx.state.lpOrigin + '/**', (route) => route.continue());
  // Destinations the page leads to are answered in the browser, never fetched.
  await ctx.page.route(OFFER + '**', (route) => route.fulfill({ contentType: 'text/html', body: '<p>offer</p>' }));
  await ctx.page.route('https://elsewhere.example/**', (route) => route.fulfill({ contentType: 'text/html', body: '<p>away</p>' }));
  return ctx.state.lpOrigin;
}

/** Load the landing page and wait for its beacon; returns the beacon URL. */
async function openLanding(ctx, query = '', path = '') {
  const { page } = ctx;
  const origin = await landingServer(ctx);
  // Both waits are armed before the navigation: the beacon can finish before
  // a wait registered after the request would start listening.
  const beacon = page.waitForRequest((r) => r.url().includes('/tracking202/static/record.php'));
  const answered = page.waitForResponse((r) => r.url().includes('/tracking202/static/record.php'));
  await page.goto(origin + '/' + path + query);
  const req = await beacon;
  await answered;
  return new URL(req.url());
}

function lastClick(db) {
  return Number(db.value('SELECT COALESCE(MAX(click_id), 0) FROM 202_clicks WHERE aff_campaign_id = ' + CAMP));
}

function visitorOf(db, clickId) {
  return db.value(
    'SELECT COALESCE(v.alias_of, cv.visitor_key) FROM 202_clicks_visitor cv ' +
    'JOIN 202_identity_visitors v ON v.visitor_key = cv.visitor_key WHERE cv.click_id = ' + Number(clickId)
  );
}

module.exports = {
  name: 'identity-landing',
  title: 'Identity › landing-page script',

  async reset(db) {
    db.truncate(['202_identity_visitors', '202_identity_signals', '202_identity_observations', '202_identity_merges', '202_clicks_visitor']);
    db.write('DELETE FROM 202_clicks WHERE aff_campaign_id = ' + CAMP);
    db.write('DELETE FROM 202_trackers WHERE tracker_id_public = ' + TRACKER);
    db.write('DELETE FROM 202_landing_pages WHERE landing_page_id_public IN (' + LP_PUBLIC + ', ' + LP_OFF + ')');
    db.write('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN (' + CAMP + ', ' + CAMP_OFF + ')');
    const now = Math.floor(Date.now() / 1000);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_aff_campaigns SET aff_campaign_id=" + CAMP + ', aff_campaign_id_public=' + CAMP +
      ", user_id=1, aff_network_id=1, aff_campaign_name='identity-landing', aff_campaign_url='" + OFFER + "', aff_campaign_payout=1, aff_campaign_time=" + now);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_trackers SET user_id=1, tracker_id_public=" + TRACKER + ', aff_campaign_id=' + CAMP + ', click_cloaking=0, tracker_time=' + now);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_landing_pages SET user_id=1, landing_page_id_public=" + LP_PUBLIC + ', aff_campaign_id=' + CAMP +
      ", landing_page_nickname='identity-landing', landing_page_url='http://localhost/', landing_page_time=" + now + ', landing_page_type=0');
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_aff_campaigns SET aff_campaign_id=" + CAMP_OFF + ', aff_campaign_id_public=' + CAMP_OFF +
      ", user_id=1, aff_network_id=1, aff_campaign_name='identity-landing-off', aff_campaign_url='" + OFFER + "', aff_campaign_payout=1, identity_signals=0, aff_campaign_time=" + now);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_landing_pages SET user_id=1, landing_page_id_public=" + LP_OFF + ', aff_campaign_id=' + CAMP_OFF +
      ", landing_page_nickname='identity-landing-off', landing_page_url='http://localhost/off', landing_page_time=" + now + ', landing_page_type=0');
  },

  scenarios: [
    {
      name: 'A pageview keeps a first-party id and sends it with the beacon',
      async run(ctx) {
        const { page, db, expect, state } = ctx;
        const before = lastClick(db);
        const beacon = await openLanding(ctx);
        const lpid = await page.evaluate(() => window.localStorage.getItem('p202lpid'));
        expect.ok(/^[0-9a-f]{32}$/.test(lpid || ''), 'a 128-bit id is kept in the landing page\'s localStorage');
        expect.eq(await page.evaluate(() => window.p202.lpid()), lpid, 'and p202.lpid() returns it');
        expect.eq(beacon.searchParams.get('p202lpid'), lpid, 'the beacon carries it');
        expect.eq(beacon.searchParams.get('p202_consent'), null, 'and no refusal');

        const click = lastClick(db);
        expect.ok(click > before, 'the beacon recorded a click');
        expect.eq(db.value('SELECT GROUP_CONCAT(signal_type ORDER BY signal_type) FROM 202_identity_observations WHERE click_id = ' + click),
          'lpid', 'linked by the page id alone: a cross-site beacon mints no tracker cookie');
        state.lpid = lpid;
        state.beaconVisitor = visitorOf(db, click);
        expect.ok(state.beaconVisitor, 'the click has a visitor');

        await openLanding(ctx);
        expect.eq(await page.evaluate(() => window.localStorage.getItem('p202lpid')), lpid, 'a second pageview reuses the id');
        expect.eq(visitorOf(db, lastClick(db)), state.beaconVisitor, 'and joins the same visitor');
      },
    },

    {
      name: 'Following a link into the tracker carries the id to the click',
      async run(ctx) {
        const { page, db, expect, state } = ctx;
        await openLanding(ctx);
        const nav = page.waitForRequest((r) => r.url().includes('/tracking202/redirect/dl.php'));
        const redirect = page.waitForResponse((r) => r.url().includes('/tracking202/redirect/dl.php'));
        await page.click('#go');
        const req = new URL((await nav).url());
        expect.eq(req.searchParams.get('p202lpid'), state.lpid, 'the tracker link was decorated when followed');
        const location = String((await (await redirect).allHeaders()).location || '');
        expect.ok(location.startsWith(OFFER), 'the tracker redirects to the offer');
        expect.notOk(location.includes('p202lpid'), 'the id stops at the tracker: the offer URL does not carry it');

        const click = lastClick(db);
        expect.eq(db.value('SELECT GROUP_CONCAT(signal_type ORDER BY signal_type) FROM 202_identity_observations WHERE click_id = ' + click),
          'lpid,vid', 'the redirect adds the tracker\'s own cookie');
        expect.eq(visitorOf(db, click), state.beaconVisitor, 'and the click joins the landing page\'s visitor');

        await openLanding(ctx);
        const away = page.waitForRequest((r) => r.url().startsWith('https://elsewhere.example/'));
        await page.click('#away');
        expect.notOk((await away).url().includes('p202lpid'), 'a link to anywhere else is left alone');
      },
    },

    {
      name: 'p202.consent(false) withdraws the id and tells the tracker',
      async run(ctx) {
        const { page, db, expect } = ctx;
        await openLanding(ctx);
        expect.eq(await page.evaluate(() => window.p202.consent(false)), false, 'consent(false) reports consent withdrawn');
        expect.eq(await page.evaluate(() => window.localStorage.getItem('p202lpid')), null, 'the id is deleted');

        const beacon = await openLanding(ctx);
        expect.eq(beacon.searchParams.get('p202_consent'), '0', 'the next beacon carries the refusal');
        expect.eq(beacon.searchParams.get('p202lpid'), null, 'and no id');
        expect.eq(await page.evaluate(() => window.localStorage.getItem('p202lpid')), null, 'no id is minted again');
        expect.eq(Number(db.value('SELECT COUNT(*) FROM 202_clicks_visitor WHERE click_id = ' + lastClick(db))), 0, 'the beacon\'s click links nothing');

        const nav = page.waitForRequest((r) => r.url().includes('/tracking202/redirect/dl.php'));
        const resp = page.waitForResponse((r) => r.url().includes('/tracking202/redirect/dl.php'));
        await page.click('#go');
        expect.eq(new URL((await nav).url()).searchParams.get('p202_consent'), '0', 'a followed tracker link carries the refusal');
        const headers = await (await resp).allHeaders();
        expect.notOk(String(headers['set-cookie'] || '').includes('p202vid='), 'the tracker sets no visitor cookie');
        expect.ok(String(headers.location || '').startsWith(OFFER), 'and still redirects to the offer');
        expect.eq(Number(db.value('SELECT COUNT(*) FROM 202_clicks_visitor WHERE click_id = ' + lastClick(db))), 0, 'and the click links nothing');

        await openLanding(ctx);
        expect.eq(await page.evaluate(() => window.p202.consent(true)), true, 'consent(true) restores it');
        const again = await openLanding(ctx);
        expect.ok(/^[0-9a-f]{32}$/.test(again.searchParams.get('p202lpid') || ''), 'a new id is minted and sent');
      },
    },

    {
      name: 'A refusal on the landing-page URL is remembered',
      async run(ctx) {
        const { page, expect, state } = ctx;
        const first = await openLanding(ctx, '?p202_consent=0');
        expect.eq(first.searchParams.get('p202_consent'), '0', 'the beacon carries the refusal');
        expect.eq(await page.evaluate(() => window.localStorage.getItem('p202_consent')), '0', 'and the page remembers it');
        const later = await openLanding(ctx);
        expect.eq(later.searchParams.get('p202_consent'), '0', 'on the next pageview without the parameter');
        await page.evaluate(() => window.p202.consent(true));
      },
    },

    {
      name: 'A refusal set before the snippet, or by a script after it, stops the very first beacon',
      async run(ctx) {
        const { page, expect } = ctx;
        await page.evaluate(() => { window.localStorage.clear(); });
        const early = await openLanding(ctx, '', 'preload');
        expect.eq(early.searchParams.get('p202_consent'), '0', 'window.p202 = {consent: false} before the snippet: the first beacon refuses');
        expect.eq(early.searchParams.get('p202lpid'), null, 'and carries no id');
        expect.eq(await page.evaluate(() => window.localStorage.getItem('p202lpid')), null, 'none was minted');
        expect.eq(await page.evaluate(() => typeof window.p202.consent), 'function', 'and the page still gets the p202.consent() API');
        await page.evaluate(() => { window.localStorage.clear(); });

        const late = await openLanding(ctx, '', 'late');
        expect.eq(late.searchParams.get('p202_consent'), '0', 'p202.consent(false) in a script after the snippet: the first beacon already refuses');
        expect.eq(late.searchParams.get('p202lpid'), null, 'and carries no id');
        await page.evaluate(() => { window.localStorage.clear(); });
      },
    },

    {
      name: 'A page on a campaign with capture off reads, mints and sends no id',
      async run(ctx) {
        const { page, db, expect, state } = ctx;
        await page.evaluate(() => { window.localStorage.clear(); });
        const beacon = await openLanding(ctx, '', 'off');
        expect.eq(beacon.searchParams.get('p202lpid'), null, 'the beacon carries no landing-page id');
        expect.eq(beacon.searchParams.get('p202_consent'), null, 'and no refusal either: the visitor did not refuse');
        expect.eq(await page.evaluate(() => window.localStorage.getItem('p202lpid')), null, 'no id is minted in the page\'s storage');
        const click = Number(db.value('SELECT COALESCE(MAX(click_id), 0) FROM 202_clicks WHERE aff_campaign_id = ' + CAMP_OFF));
        expect.ok(click > 0, 'the pageview still records its click');
        expect.eq(Number(db.value('SELECT COUNT(*) FROM 202_clicks_visitor WHERE click_id = ' + click)), 0, 'which links to no one');
        const nav = page.waitForRequest((r) => r.url().includes('/tracking202/redirect/dl.php'));
        await page.click('#go');
        expect.eq(new URL((await nav).url()).searchParams.get('p202lpid'), null, 'a link into the tracker is left alone');
        state.lpServer.close();
      },
    },
  ],
};
