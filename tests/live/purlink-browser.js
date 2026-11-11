#!/usr/bin/env node
'use strict';

/*
 * PurLinks end to end in a real Chromium, against a disposable instance:
 * the landing page is served from another site (localhost vs 127.0.0.1), as
 * in production, and every claim is read back from the database.
 *
 *   node tests/live/purlink-browser.js --base http://127.0.0.1:18970 \
 *     --db p202_purlink_e2e_test [--mysql-port 13376] [--chromium PATH]
 *
 * It seeds and deletes its own rows (ids 9503xx); the database name must look
 * disposable. Playwright: P202_PLAYWRIGHT, tests/browser/node_modules or a
 * global install. Exit 0 on success, 1 on a failed check, 2 when it cannot run.
 */

const http = require('http');
const path = require('path');
const { execFileSync } = require('child_process');

function arg(name, fallback) {
  const at = process.argv.indexOf('--' + name);
  return at > 0 ? process.argv[at + 1] : fallback;
}

const BASE = (arg('base') || '').replace(/\/$/, '');
const DB = arg('db') || '';
const MYSQL = ['-h', arg('mysql-host', '127.0.0.1'), '-P', arg('mysql-port', '3306'), '-u', arg('mysql-user', 'root'), '--batch', '--skip-column-names'];
if (!BASE || !/(^|_)(test|scratch|tmp|e2e)(_|$)/.test(DB)) {
  console.error('usage: purlink-browser.js --base URL --db <disposable database>');
  process.exit(2);
}

let playwright;
for (const candidate of [process.env.P202_PLAYWRIGHT, path.join(__dirname, '../browser/node_modules/playwright-core'), 'playwright-core', 'playwright']) {
  if (!candidate) { continue; }
  try { playwright = require(candidate); break; } catch (e) { /* next */ }
}
if (!playwright) {
  console.error('playwright-core not found: npm install playwright-core --prefix tests/browser');
  process.exit(2);
}

const sql = (statement) => execFileSync('mysql', [...MYSQL, DB, '-e', statement], { encoding: 'utf8' }).trim();
const value = (statement) => sql(statement).split('\n')[0] || '';

const USER = 1;
const NETWORK = 950301;
const SIMPLE = { id: 950311, pub: 950311, url: 'http://offer.example/a?sub=[[subid]]&kw=[[t202kw]]&c1=[[c1]]' };
const OFFER_B = { id: 950312, pub: 950312, url: 'http://offer.example/b?s=[[subid]]' };
const OFFER_C = { id: 950313, pub: 950313, url: 'http://offer.example/c?s=[[subid]]' };
const LP_SIMPLE = 950321;
const LP_ADV = 950322;
const SOURCE = 950331;
const TRACKER = 950341;

function seed() {
  const now = Math.floor(Date.now() / 1000);
  const campaigns = [SIMPLE, OFFER_B, OFFER_C].map((c) => c.id).join(',');
  sql('DELETE FROM 202_conversion_logs WHERE campaign_id IN (' + campaigns + ')');
  sql('DELETE p FROM 202_purlink_exit_order p JOIN 202_clicks c USING (click_id) WHERE c.landing_page_id IN (SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_id_public IN (' + LP_SIMPLE + ',' + LP_ADV + '))');
  sql('DELETE FROM 202_purlink_pixel_fires WHERE user_id = ' + USER);
  sql('DELETE FROM 202_landing_pages WHERE landing_page_id_public IN (' + LP_SIMPLE + ',' + LP_ADV + ')');
  sql('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN (' + campaigns + ')');
  sql('DELETE FROM 202_trackers WHERE tracker_id_public = ' + TRACKER);
  sql('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = ' + SOURCE);
  sql('DELETE FROM 202_ppc_accounts WHERE ppc_account_id = ' + SOURCE);
  sql('DELETE FROM 202_aff_networks WHERE aff_network_id = ' + NETWORK);
  // The pass sends its postbacks as a network without the account's token would.
  sql("INSERT INTO 202_postback_auth SET user_id=" + USER + ", auth_mode='off', updated_at=" + now + " ON DUPLICATE KEY UPDATE auth_mode='off'");
  sql("SET SESSION sql_mode=''; INSERT INTO 202_aff_networks SET aff_network_id=" + NETWORK + ', user_id=' + USER + ", aff_network_name='purlink-e2e', aff_network_time=" + now);
  for (const c of [SIMPLE, OFFER_B, OFFER_C]) {
    sql("SET SESSION sql_mode=''; INSERT INTO 202_aff_campaigns SET aff_campaign_id=" + c.id + ', aff_campaign_id_public=' + c.pub +
      ', user_id=' + USER + ', aff_network_id=' + NETWORK + ", aff_campaign_name='e2e " + c.id + "', aff_campaign_url='" + c.url +
      "', aff_campaign_payout=5, aff_campaign_time=" + now);
  }
  sql("SET SESSION sql_mode=''; INSERT INTO 202_landing_pages SET user_id=" + USER + ', landing_page_id_public=' + LP_SIMPLE + ', aff_campaign_id=' + SIMPLE.id +
    ", landing_page_nickname='e2e simple', landing_page_url='http://localhost/', landing_page_type=0, landing_page_time=" + now);
  sql("SET SESSION sql_mode=''; INSERT INTO 202_landing_pages SET user_id=" + USER + ', landing_page_id_public=' + LP_ADV + ', aff_campaign_id=0' +
    ", landing_page_nickname='e2e advanced', landing_page_url='http://localhost/adv', landing_page_type=1, landing_page_time=" + now);
  sql("SET SESSION sql_mode=''; INSERT INTO 202_ppc_accounts SET ppc_account_id=" + SOURCE + ', user_id=' + USER + ", ppc_network_id=1, ppc_account_name='e2e source', ppc_account_time=" + now);
  sql('INSERT INTO 202_ppc_account_pixels SET ppc_account_id=' + SOURCE + ", pixel_type_id=1, pixel_code='http://source.example/px?p=[[payout]]&t=[[transactionid]]'");
  sql("SET SESSION sql_mode=''; INSERT INTO 202_trackers SET user_id=" + USER + ', tracker_id_public=' + TRACKER + ', aff_campaign_id=' + SIMPLE.id +
    ', ppc_account_id=' + SOURCE + ', click_cloaking=0, tracker_time=' + now);
}

function loader(lpip, extra) {
  return '<script>(function(d, s) { var upxf = d.getElementsByTagName(s)[0], load = function(url, id) {' +
    ' if (d.getElementById(id)) {return;} var if202 = d.createElement("script"); if202.src = url; if202.async = true; if202.id = id;' +
    ' upxf.parentNode.insertBefore(if202, upxf); };' +
    ' var t = new URLSearchParams(window.location.search).get("t202id") || "";' +
    ' load("' + BASE + '/tracking202/static/landing.php?lpip=' + lpip + (extra || '') + '&t202id=" + encodeURIComponent(t), "upxif");' +
    '}(document, "script"));</script>';
}

const DEEP_B = OFFER_B.url + '&acip=' + OFFER_B.pub + '&u=deep1';
const DEEP_SIMPLE = SIMPLE.url + '&u=deep2';
const LEGACY_SRC = BASE + '/tracking202/static/landing.js?lpip=' + LP_SIMPLE + '&defpixel=1';

function pageHtml(route) {
  let head = route === '/refuse' ? '<script>window.p202 = {consent: false};</script>' : '';
  if (route.startsWith('/orig')) { head = '<script>window.t202Settings = {t202UseSubidOrig: true, t202ExpireSubidOrig: 30};</script>'; }
  head += '<script>window.__shows = []; window.addEventListener("pageshow", function (e) { window.__shows.push(e.persisted); });</script>';
  let body;
  if (route === '/deep') {
    body = loader(LP_ADV) + '<a id="d1" href="' + DEEP_B + '">Deep</a> <a id="b" href="' + OFFER_B.url + '&acip=' + OFFER_B.pub + '">B</a>';
  } else if (route === '/deepsimple') {
    body = loader(LP_SIMPLE) + '<a id="d2" href="' + DEEP_SIMPLE + '">Deep</a> <a id="offer" href="' + SIMPLE.url + '">Offer</a>';
  } else if (route === '/orig-simple') {
    body = loader(LP_SIMPLE) + '<a id="offer" href="' + SIMPLE.url + '">Offer</a>';
  } else if (route.startsWith('/adv') || route === '/orig-adv') {
    body = loader(LP_ADV) +
      '<a id="b" href="' + OFFER_B.url + '&acip=' + OFFER_B.pub + '">B</a> <a id="c" href="' + OFFER_C.url + '&acip=' + OFFER_C.pub + '">C</a>';
  } else if (route === '/legacy') {
    body = '<script src="' + BASE + '/tracking202/static/landing.js?lpip=' + LP_SIMPLE + '&defpixel=1" id="t202js" async></script>' +
      '<a id="offer" href="' + SIMPLE.url + '">Offer</a>';
  } else {
    const extra = route === '/defpixel' ? '&p202_defpixel=1' : route === '/optout' ? '&p202_purlink=0' : '';
    body = loader(LP_SIMPLE, extra) +
      '<a id="offer" href="' + SIMPLE.url + '">Offer</a>' +
      '<a id="tokens" href="http://partner.example/?c1=[[c1]]">Partner</a>' +
      '<a id="plain" href="http://elsewhere.example/">Plain</a>' +
      '<form id="form" method="get" action="http://offer.example/a?sub=[[subid]]"><input name="q" value="shoes"><button id="send">Go</button></form>' +
      '<script>setTimeout(function () { var a = document.createElement("a"); a.id = "late"; a.href = "' + SIMPLE.url +
      '"; a.textContent = "Late"; document.body.appendChild(a); }, 300);</script>';
  }
  return '<!doctype html><html><head><title>LP</title>' + head + '</head><body>' + body + '</body></html>';
}

let failures = 0;
function check(ok, what) {
  console.log((ok ? '  ok   ' : '  FAIL ') + what);
  if (!ok) { failures++; }
}

const SUBID = /^([1-9][0-9]*)-([1-9][0-9]*)-[0-9a-f]{12}$/;

async function directHref(page, selector) {
  await page.waitForFunction((sel) => {
    const el = document.querySelector(sel);
    const href = el && el.getAttribute(sel === '#form' ? 'action' : 'href');
    // The redirect that stands in carries the link's URL encoded: not that.
    return !!href && href.indexOf('http://offer.example/') === 0 && href.indexOf('[[') < 0;
  }, selector, { timeout: 15000 });
  return page.$eval(selector, (el, sel) => el.getAttribute(sel === '#form' ? 'action' : 'href'), selector);
}

function subidOf(url) {
  const parsed = new URL(url);
  return parsed.searchParams.get('sub') || parsed.searchParams.get('s') || '';
}

async function waitFor(fn, what, ms = 8000) {
  const end = Date.now() + ms;
  for (;;) {
    const got = fn();
    if (got) { return got; }
    if (Date.now() > end) { return got; }
    await new Promise((r) => setTimeout(r, 250));
  }
}

function postback(subid, extra) {
  const q = new URLSearchParams({ subid, ...extra });
  return fetch(BASE + '/tracking202/static/gpb.php?' + q).then((r) => r.status);
}

// gpb.php answers 202 when the traffic source has pixel markup for it.
const accepted = (status) => status === 200 || status === 202;

(async () => {
  seed();
  const lp = http.createServer((req, res) => {
    res.setHeader('content-type', 'text/html');
    res.end(pageHtml(new URL(req.url, 'http://x').pathname));
  });
  await new Promise((r) => lp.listen(0, '127.0.0.1', r));
  const origin = 'http://localhost:' + lp.address().port;

  const browser = await playwright.chromium.launch({ executablePath: arg('chromium') || process.env.P202_CHROMIUM || undefined });
  const context = await browser.newContext();
  const offers = [];
  const pixels = [];
  const trackerHits = [];
  await context.route('http://offer.example/**', (route) => { offers.push(route.request().url()); route.fulfill({ contentType: 'text/html', body: '<p>offer</p>' }); });
  await context.route('http://partner.example/**', (route) => { offers.push(route.request().url()); route.fulfill({ contentType: 'text/html', body: '<p>partner</p>' }); });
  await context.route('http://elsewhere.example/**', (route) => route.fulfill({ contentType: 'text/html', body: '<p>away</p>' }));
  await context.route('http://source.example/**', (route) => { pixels.push(route.request().url()); route.fulfill({ contentType: 'image/gif', body: '' }); });
  context.on('request', (r) => { if (r.url().startsWith(BASE + '/tracking202/')) { trackerHits.push(r.url()); } });

  try {
    console.log('Simple page: direct links, tokens, forms, inserted links');
    let page = await context.newPage();
    await page.goto(origin + '/simple?t202id=' + TRACKER + '&t202kw=red%20shoes&c1=fb');
    const href = await directHref(page, '#offer');
    const sub = subidOf(href);
    const m = SUBID.exec(sub);
    check(!!m && m[2] === String(SIMPLE.pub), 'the link carries a signed click-offer SubID: ' + sub);
    check(href === 'http://offer.example/a?sub=' + sub + '&kw=red%20shoes&c1=fb', 'tokens are filled in, encoded: ' + href);
    const click = m ? m[1] : '0';
    check(value('SELECT COUNT(*) FROM 202_clicks WHERE click_id=' + click + ' AND user_id=' + USER) === '1', 'it is the click this pageview recorded');
    check((await page.getAttribute('#tokens', 'href')) === 'http://partner.example/?c1=fb', 'a link with tokens and no [[subid]] is filled in');
    check((await page.getAttribute('#plain', 'href')) === 'http://elsewhere.example/', 'an ordinary link is left alone');
    check((await directHref(page, '#late')) === href, 'a link inserted later becomes a PurLink too');
    check((await directHref(page, '#form')) === 'http://offer.example/a', 'a GET form posts to the bare offer URL');
    check(value('SELECT click_out FROM 202_clicks_record WHERE click_id=' + click) === '0', 'no click-out yet');

    const before = trackerHits.length;
    await Promise.all([page.waitForURL('http://offer.example/**'), page.click('#offer')]);
    check(page.url() === href, 'clicking goes straight to the offer');
    check(!trackerHits.slice(before).some((u) => u.includes('/redirect/')), 'with no tracker redirect');
    check(await waitFor(() => value('SELECT click_out FROM 202_clicks_record WHERE click_id=' + click) === '1'), 'the click-out is recorded in the background');
    check(value('SELECT campaign_id FROM 202_purlink_exit_order WHERE click_id=' + click) === String(SIMPLE.id), 'for the page\'s campaign');

    page = await context.newPage();
    await page.goto(origin + '/simple?t202id=' + TRACKER);
    await directHref(page, '#form');
    await Promise.all([page.waitForURL('http://offer.example/**'), page.click('#send')]);
    const formUrl = new URL(page.url());
    check(SUBID.test(formUrl.searchParams.get('sub') || '') && formUrl.searchParams.get('q') === 'shoes', 'the form submits its fields and the SubID: ' + page.url());

    console.log('Postbacks with the SubID');
    check(accepted(await postback(sub, { txid: 'e2e-1', amount: '12.5' })), 'gpb.php accepts the SubID');
    check(value('SELECT CONCAT(campaign_id, "/", click_payout) FROM 202_conversion_logs WHERE click_id=' + click + " AND transaction_id='e2e-1'") === SIMPLE.id + '/12.50000', 'the conversion is credited to the click and offer');
    check(await postback(sub.slice(0, -1) + (sub.endsWith('0') ? '1' : '0'), { txid: 'e2e-forged' }) === 404, 'a forged signature is refused');
    check(accepted(await postback(click + '-' + SIMPLE.pub, { txid: 'e2e-legacy' })), 'a 1.9.56 click-offer SubID still converts');
    check(value('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=' + click + " AND transaction_id='e2e-legacy'") === '1', 'and is recorded');

    console.log('Advanced page: one SubID per offer, the last choice wins');
    page = await context.newPage();
    await page.goto(origin + '/adv');
    const hrefB = await directHref(page, '#b');
    const hrefC = await directHref(page, '#c');
    const subB = SUBID.exec(subidOf(hrefB));
    const subC = SUBID.exec(subidOf(hrefC));
    check(!!subB && !!subC && subB[1] === subC[1] && subB[2] === String(OFFER_B.pub) && subC[2] === String(OFFER_C.pub), 'each offer has its own SubID for the same click');
    const advClick = subB ? subB[1] : '0';
    const tab = context.waitForEvent('page');
    await page.click('#b', { button: 'middle' });
    await (await tab).close();
    check(await waitFor(() => value('SELECT aff_campaign_id FROM 202_clicks WHERE click_id=' + advClick) === String(OFFER_B.id)), 'a middle-click records offer B');
    await Promise.all([page.waitForURL('http://offer.example/c**'), page.click('#c')]);
    check(await waitFor(() => value('SELECT aff_campaign_id FROM 202_clicks WHERE click_id=' + advClick) === String(OFFER_C.id)), 'then a click on C moves the click to C');
    check(accepted(await postback(subidOf(hrefB), { txid: 'e2e-b' })), 'a conversion for B still arrives with B\'s SubID');
    const pb = (acip, subid, txid) => fetch(BASE + '/tracking202/static/pb.php?' + new URLSearchParams({ acip, subid, txid })).then((r) => r.status);
    check(accepted(await pb(String(OFFER_C.pub), subidOf(hrefC), 'e2e-pb-c')), 'pb.php takes C\'s SubID on C\'s campaign postback');
    check(value('SELECT campaign_id FROM 202_conversion_logs WHERE click_id=' + advClick + " AND transaction_id='e2e-pb-c'") === String(OFFER_C.id), 'and credits C');
    check((await pb(String(OFFER_C.pub), subidOf(hrefB), 'e2e-pb-wrong')) === 404, 'B\'s SubID on C\'s postback is refused');
    check(value('SELECT campaign_id FROM 202_conversion_logs WHERE click_id=' + advClick + " AND transaction_id='e2e-b'") === String(OFFER_B.id), 'and is credited to B');

    console.log('Deferred pixel');
    // Under the default privacy setting ('eu') an unplaced visitor such as
    // 127.0.0.1 is held back: PurLinks work, third-party pixels do not fire.
    const privacy = value('SELECT user_pref_privacy FROM 202_users_pref WHERE user_id=' + USER);
    page = await context.newPage();
    await page.goto(origin + '/defpixel?t202id=' + TRACKER);
    const heldBack = await directHref(page, '#offer');
    check(SUBID.test(subidOf(heldBack)), 'a visitor privacy holds back still gets PurLinks');
    await page.close();
    sql("UPDATE 202_users_pref SET user_pref_privacy='disabled' WHERE user_id=" + USER);
    page = await context.newPage();
    await page.goto(origin + '/defpixel?t202id=' + TRACKER);
    const defHref = await directHref(page, '#offer');
    check((await page.getAttribute('#offer', 'target')) === '_blank', 'the offer opens in a new tab');
    const offerTab = context.waitForEvent('page');
    await page.click('#offer');
    await (await offerTab).close();
    check(accepted(await postback(subidOf(defHref), { txid: 'e2e-def', amount: '7' })), 'the network reports the conversion');
    check(await waitFor(() => pixels.some((u) => u === 'http://source.example/px?p=7.00000&t=e2e-def'), 'pixel', 25000),
      'the landing page fires the traffic source pixel with the payout and transaction id: ' + pixels.join(' '));
    const defClick = SUBID.exec(subidOf(defHref))[1];
    check(value('SELECT COUNT(*) FROM 202_purlink_pixel_fires WHERE click_id=' + defClick) === '1', 'and records that conversion as fired');
    sql("UPDATE 202_users_pref SET user_pref_privacy='" + privacy.replace(/'/g, '') + "' WHERE user_id=" + USER);

    console.log('Consent, opt-out and the 1.9.56 loader');
    page = await context.newPage();
    const refusedPrepare = [];
    page.on('request', (r) => { if (r.url().includes('purlink-prepare')) { refusedPrepare.push(r.url()); } });
    await page.goto(origin + '/refuse');
    await page.waitForFunction(() => (document.querySelector('#offer').getAttribute('href') || '').indexOf('go.php') >= 0, null, { timeout: 15000 });
    check((await page.getAttribute('#offer', 'href')) === BASE + '/tracking202/redirect/go.php?lpip=' + LP_SIMPLE + '&p202_to=' + encodeURIComponent(SIMPLE.url),
      'a refusal leaves the tracker redirect, which keeps the link\'s own URL');
    check(refusedPrepare.length === 0, 'and asks for no SubID');
    // The refusal is remembered for this site; give consent back for the rest.
    check((await page.evaluate(() => window.p202.consent(true))) === true, 'p202.consent(true) restores consent');

    page = await context.newPage();
    const optoutRecord = page.waitForResponse((r) => r.url().includes('/tracking202/static/record'), { timeout: 15000 });
    await page.goto(origin + '/optout');
    check(!(await optoutRecord).url().includes('p202_purlink'), 'an opted-out page asks for no proof');
    await page.waitForTimeout(1500);
    check((await page.getAttribute('#offer', 'href')) === SIMPLE.url, 'p202_purlink=0 leaves the links as written');

    page = await context.newPage();
    const legacyLanding = page.waitForRequest((r) => r.url().includes('/tracking202/static/landing.php'));
    await page.goto(origin + '/legacy');
    check((await legacyLanding).url().includes('p202_defpixel=1'), 'the 1.9.56 landing.js loader carries defpixel=1 over');
    check(SUBID.test(subidOf(await directHref(page, '#offer'))), 'and its page gets PurLinks');

    // ---- P7LK: deep links, back/forward, the original subid ----
    const location = (url, cookie) => fetch(url, { redirect: 'manual', headers: cookie ? { cookie } : {} })
      .then((r) => r.status + ' ' + (r.headers.get('location') || ''));
    const go = BASE + '/tracking202/redirect/go.php';
    const to = (url) => '&p202_to=' + encodeURIComponent(url);
    const settle = (ms) => new Promise((r) => setTimeout(r, ms));

    console.log('Deep-link fallback: the redirect itself');
    const ownB = OFFER_B.url + '&acip=' + OFFER_B.pub;
    const plainB = 'http://offer.example/b?s=';
    check((await location(go + '?acip=' + OFFER_B.pub + to(DEEP_B))) === '302 http://offer.example/b?s=&acip=' + OFFER_B.pub + '&u=deep1',
      'go.php?acip= with the link\'s own URL goes to its deep link: ' + await location(go + '?acip=' + OFFER_B.pub + to(DEEP_B)));
    check((await location(go + '?acip=' + OFFER_B.pub)) === '302 ' + plainB, 'without one, to the campaign URL');
    check((await location(go + '?acip=' + OFFER_B.pub + to(ownB))) === '302 ' + plainB, 'the campaign\'s own PurLink goes to the campaign URL');
    check((await location(go + '?acip=' + OFFER_B.pub + to('http://evil.example/b?s=[[subid]]&u=x'))) === '302 ' + plainB, 'another host goes to the campaign URL');
    check((await location(go + '?acip=' + OFFER_B.pub + to('http://offer.example/other?s=[[subid]]&u=x'))) === '302 ' + plainB, 'another path goes to the campaign URL');
    check((await location(go + '?acip=' + OFFER_B.pub + to('http://offer.example@evil.example/b?s=[[subid]]'))) === '302 ' + plainB, 'user info naming the host goes to the campaign URL');
    check((await location(go + '?acip=' + OFFER_B.pub + to('http://offer.example/b?s=[[subid]]&u=x&k=[[t202kw]]'))) === '302 ' + plainB + '&u=x&k=', 'a token the campaign URL lacks is left empty and the link kept');
    const deepSimpleTo = 'http://offer.example/a?sub=&kw=&c1=&u=deep2';
    check((await location(go + '?lpip=' + LP_SIMPLE + to(DEEP_SIMPLE))) === '302 ' + deepSimpleTo,
      'go.php?lpip= keeps a simple page link\'s deep link: ' + await location(go + '?lpip=' + LP_SIMPLE + to(DEEP_SIMPLE)));
    const outbound = 'tracking202outbound=' + encodeURIComponent('http://offer.example/a?sub=77');
    check((await location(go + '?lpip=' + LP_SIMPLE, outbound)) === '302 http://offer.example/a?sub=77', 'the outbound cookie still answers a plain simple-page redirect');
    check((await location(go + '?lpip=' + LP_SIMPLE + to(DEEP_SIMPLE), outbound)) === '302 ' + deepSimpleTo, 'and does not stand in for a deep link');
    check((await location(go + '?lpip=' + LP_SIMPLE + to('http://evil.example/a?u=1'))) === '302 http://offer.example/a?sub=&kw=&c1=', 'a simple page: another host goes to the campaign URL');

    console.log('Deep-link fallback and Back, in the page');
    page = await context.newPage();
    let prepares = 0;
    let failPrepare = true;
    const records = [];
    await page.route('**/purlink-prepare.php', (route) => { prepares++; return failPrepare ? route.abort() : route.continue(); });
    page.on('request', (r) => { if (/\/tracking202\/static\/(record|landing\.php)/.test(r.url())) { records.push(r.url()); } });
    await page.goto(origin + '/deep');
    await page.waitForFunction(() => (document.querySelector('#d1').getAttribute('href') || '').indexOf('go.php') >= 0, null, { timeout: 15000 });
    check((await page.getAttribute('#d1', 'href')) === go + '?acip=' + OFFER_B.pub + to(DEEP_B), 'while no SubID can be prepared the link is the redirect with its own URL');
    await waitFor(() => prepares >= 3, 'three attempts', 10000);
    await settle(3500);
    check(prepares === 3, 'preparation is tried three times, then left: ' + prepares);
    check((await page.getAttribute('#d1', 'href')).indexOf('go.php') >= 0, 'and the link stays on the redirect');
    const deepClick = value('SELECT MAX(click_id) FROM 202_clicks');
    const deepPci = value('SELECT click_id_public FROM 202_clicks_record WHERE click_id=' + deepClick);
    check((await location(go + '?acip=' + OFFER_B.pub + '&pci=' + deepPci + to(DEEP_B))) === '302 http://offer.example/b?s=' + deepClick + '&acip=' + OFFER_B.pub + '&u=deep1',
      'followed for this click, the redirect fills the click\'s SubID and keeps the deep link');
    check(value('SELECT aff_campaign_id FROM 202_clicks WHERE click_id=' + deepClick) === String(OFFER_B.id), 'and records the click leaving for offer B');
    const recordsBefore = records.length;
    await page.evaluate(() => window.updatePurLink());
    await settle(500);
    check(prepares === 3, 'updatePurLink() right after the failures asks nothing');
    failPrepare = false;
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    const restored = await directHref(page, '#d1');
    check(SUBID.test(subidOf(restored)) && new URL(restored).searchParams.get('u') === 'deep1', 'a restored page (pageshow, persisted) asks again and the link is the offer, deep link kept: ' + restored);
    check(prepares === 4, 'with one more request: ' + prepares);
    check(records.length === recordsBefore && records.filter((u) => u.includes('/record')).length === 1, 'and no second pageview: ' + records.length);
    // A real Back: does this Chromium restore the page from its back/forward cache?
    const offerBefore = offers.length;
    await Promise.all([page.waitForURL('http://offer.example/**'), page.click('#d1')]);
    check(new URL(page.url()).searchParams.get('u') === 'deep1', 'clicking the restored link lands on the deep link: ' + page.url());
    await page.goBack();
    await settle(1500);
    const shows = await page.evaluate(() => window.__shows);
    const bfcache = shows.some((v) => v === true);
    console.log('  note Back ' + (bfcache ? 'restored the page from the back/forward cache' : 'reloaded the page (no back/forward cache in this Chromium)') + ': ' + JSON.stringify(shows));
    check(SUBID.test(subidOf(await directHref(page, '#d1'))), 'after Back the link is a PurLink, not a literal [[subid]]');
    check(offers.length > offerBefore, 'the offer was reached');
    await page.close();

    // The visitor's own click on the stand-in redirect, before any SubID arrives.
    page = await context.newPage();
    await page.route('**/purlink-prepare.php', (route) => route.abort());
    await page.goto(origin + '/deep');
    await page.waitForFunction(() => (document.querySelector('#d1').getAttribute('href') || '').indexOf('go.php') >= 0, null, { timeout: 15000 });
    const hitsAt = trackerHits.length;
    // A redirect's target is not routed by Playwright, so the request for
    // the offer is read rather than the page it would load.
    const sent = page.waitForRequest((r) => r.url().indexOf('http://offer.example/') === 0, { timeout: 15000 });
    await page.click('#d1').catch(() => {});
    const landed = new URL((await sent).url());
    check(landed.pathname === '/b' && landed.searchParams.get('u') === 'deep1' && landed.searchParams.get('acip') === String(OFFER_B.pub) && !landed.searchParams.has('p202_to'),
      'a visitor clicking before a SubID arrives is sent to the deep link by the redirect: ' + landed.href);
    check(trackerHits.slice(hitsAt).some((u) => u.includes('/redirect/go.php')), 'by way of go.php');
    console.log('  note the redirect filled s=' + JSON.stringify(landed.searchParams.get('s')) + ' for that visitor');
    await page.close();

    console.log('The 1.9.56 loader, loaded again');
    page = await context.newPage();
    const legacyHits = [];
    page.on('request', (r) => { if (/\/tracking202\/static\/(record|landing\.)/.test(r.url())) { legacyHits.push(r.url()); } });
    await page.goto(origin + '/legacy');
    await directHref(page, '#offer');
    await settle(500);
    const hitsBefore = legacyHits.filter((u) => !u.includes('landing.js')).length;
    await page.evaluate(() => { const real = window.updatePurLink; window.__updates = 0; window.updatePurLink = function () { window.__updates++; return real.apply(this, arguments); }; });
    const again = page.waitForResponse((r) => r.url().includes('landing.js'));
    await page.evaluate((src) => { const s = document.createElement('script'); s.src = src; s.async = true; document.body.appendChild(s); }, LEGACY_SRC);
    await again;
    await settle(800);
    check((await page.evaluate(() => window.__updates)) === 1, 'loading landing.js again calls updatePurLink()');
    check(legacyHits.filter((u) => !u.includes('landing.js')).length === hitsBefore, 'and asks for no second landing.php or pageview');
    await page.close();

    console.log('The original subid (t202Settings.t202UseSubidOrig)');
    const freshContext = async () => {
      const c = await browser.newContext();
      await c.route('http://offer.example/**', (route) => route.fulfill({ contentType: 'text/html', body: '<p>offer</p>' }));
      return c;
    };
    const origCookies = async (c) => (await c.cookies(origin)).filter((k) => k.name.indexOf('_t202SubidOrig') === 0);
    const visit = async (c, url) => {
      const p = await c.newPage();
      const asked = [];
      p.on('request', (r) => { if (r.url().includes('purlink-prepare')) { asked.push(r.url()); } });
      await p.goto(url);
      return { p, asked };
    };
    // Held back by the privacy setting (the default 'eu', this visitor unplaced): nothing is kept.
    let ctx = await freshContext();
    let v = await visit(ctx, origin + '/orig-adv');
    check(SUBID.test(subidOf(await directHref(v.p, '#b'))), 'a held-back visitor still gets PurLinks');
    await settle(300);
    check((await origCookies(ctx)).length === 0, 'and no original subid is kept for them');
    await ctx.close();

    sql("UPDATE 202_users_pref SET user_pref_privacy='disabled' WHERE user_id=" + USER);
    // Without t202Settings nothing is kept.
    ctx = await freshContext();
    v = await visit(ctx, origin + '/adv');
    await directHref(v.p, '#b');
    await settle(300);
    check((await origCookies(ctx)).length === 0, 'a page without t202Settings keeps no original subid');
    await ctx.close();

    ctx = await freshContext();
    v = await visit(ctx, origin + '/orig-adv');
    const firstB = subidOf(await directHref(v.p, '#b'));
    const firstClick = SUBID.exec(firstB)[1];
    await settle(300);
    let kept = await origCookies(ctx);
    const days = kept.length ? (kept[0].expires - Date.now() / 1000) / 86400 : 0;
    const keptB = kept.find((k) => k.name === '_t202SubidOrig_' + OFFER_B.pub);
    check(kept.length === 2 && !!keptB && keptB.value === firstB && kept.some((k) => k.name === '_t202SubidOrig_' + OFFER_C.pub),
      'the first visit keeps each offer\'s subid: ' + JSON.stringify(kept.map((k) => k.name + '=' + k.value)));
    check(days > 29.9 && days <= 30, 'for the 30 days the page asked: ' + days.toFixed(3));
    check(v.asked.length === 2, 'having asked once per offer: ' + v.asked.length);
    v = await visit(ctx, origin + '/orig-adv');
    const secondB = subidOf(await directHref(v.p, '#b'));
    const secondClick = await waitFor(() => { const m = value('SELECT MAX(click_id) FROM 202_clicks'); return Number(m) > Number(firstClick) ? m : ''; });
    check(!!secondClick, 'a second pageview records a new click: ' + firstClick + ' then ' + secondClick);
    check(secondB === firstB, 'and its link carries the first click\'s subid: ' + secondB);
    await settle(500);
    check(v.asked.length === 0, 'with no request for a new one');
    check(accepted(await postback(firstB, { txid: 'e2e-orig', amount: '3' })), 'a conversion with that subid is accepted');
    check(value("SELECT click_id FROM 202_conversion_logs WHERE transaction_id='e2e-orig'") === firstClick, 'and credited to the first click');
    // Simple page: its own cookie, by page.
    v = await visit(ctx, origin + '/orig-simple?t202id=' + TRACKER);
    const firstSimple = subidOf(await directHref(v.p, '#offer'));
    await settle(300);
    kept = await origCookies(ctx);
    check(kept.some((k) => k.name === '_t202SubidOrig_p' + LP_SIMPLE && k.value === firstSimple) && kept.length === 3, 'a simple page keeps its own, by page: ' + JSON.stringify(kept.map((k) => k.name)));
    v = await visit(ctx, origin + '/orig-simple?t202id=' + TRACKER);
    check(subidOf(await directHref(v.p, '#offer')) === firstSimple, 'and a later pageview carries it');
    await settle(500);
    check(v.asked.length === 0, 'with no request for a new one');
    check((await v.p.evaluate(() => window.p202.consent(false))) === false, 'p202.consent(false) refuses');
    check((await origCookies(ctx)).length === 0, 'and removes every kept subid');
    await ctx.close();
    sql("UPDATE 202_users_pref SET user_pref_privacy='" + privacy.replace(/'/g, '') + "' WHERE user_id=" + USER);
  } catch (error) {
    console.error(error);
    failures++;
  } finally {
    await browser.close();
    lp.close();
  }
  console.log(failures ? failures + ' check(s) failed' : 'PurLink browser pass: every check passed');
  process.exit(failures ? 1 : 0);
})();
