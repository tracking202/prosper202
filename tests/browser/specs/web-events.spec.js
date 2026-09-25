'use strict';

/*
 * Web events in a browser (PR 4b): the goal editor on Setup › Campaigns, and
 * p202.track() — both JavaScript and markup a request cannot see.
 *
 * The editor is driven the way a person drives it: goals added through the
 * form (a fixed value, then one from the event's amount, the amount field
 * showing and hiding with the choice), a refusal shown under the field it
 * names, the Advanced disclosure closed until it is needed, an edit that
 * fills the form, an archive behind its confirm. Then the goals those forms
 * made are reached by p202.track() on a real landing page served on
 * loopback (as `localhost`, another site than the tracker, as in
 * production): calls made before the pageview's click exists wait for it,
 * a thank-you page loaded with p202_beacon=0 records no click of its own and
 * reports to the landing page's click, and a visitor who refused consent
 * sends nothing. Every claim about what was stored reads the database.
 */

const http = require('http');
const checks = require('../lib/checks');

const NET = 950011;
const CAMP = 950011;
const LP_PUBLIC = 950311;
const PAGE = '/tracking202/setup/aff_campaigns.php?edit_aff_campaign_id=' + CAMP;
const GOAL_TABLES = ['202_goals', '202_goal_versions', '202_campaign_goals', '202_goal_subjects', '202_goal_events', '202_goal_progress', '202_goal_outcomes'];

/**
 * The pages, by path: `/` a landing page that tracks a signup as soon as the
 * snippet loads (before its pageview is recorded); `/thanks` a thank-you page
 * that loads the snippet without a beacon; `/refused` a page whose consent
 * tool refused before the snippet.
 */
function pageHtml(base, path) {
  const snippet = '<script src="' + base + '/tracking202/static/landing.php?lpip=' + LP_PUBLIC
    + (path.startsWith('/thanks') ? '&p202_beacon=0' : '') + '"></script>';
  const before = path.startsWith('/refused') ? '<script>window.p202 = {consent: false};</script>' : '';
  const after = path.startsWith('/thanks') || path.startsWith('/refused')
    ? ''
    : '<script>window.signupSent = window.p202.track("signup");</script>';
  return '<!doctype html><html><head><title>LP</title>' + before + snippet + after + '</head><body><h1>Page</h1></body></html>';
}

async function pageServer(ctx) {
  if (ctx.state.lpOrigin) {
    return ctx.state.lpOrigin;
  }
  const base = ctx.config.base.replace(/\/$/, '');
  const server = http.createServer((req, res) => {
    res.setHeader('content-type', 'text/html');
    res.end(pageHtml(base, req.url || '/'));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  server.unref();
  ctx.state.lpServer = server;
  ctx.state.lpOrigin = 'http://localhost:' + server.address().port;
  await ctx.page.route(ctx.state.lpOrigin + '/**', (route) => route.continue());
  return ctx.state.lpOrigin;
}

function goalId(db, name) {
  return Number(db.value("SELECT goal_id FROM 202_goals WHERE scope='campaign' AND scope_id=" + CAMP + " AND name='" + name + "'"));
}

function lpClick(db) {
  return Number(db.value('SELECT COALESCE(MAX(click_id), 0) FROM 202_clicks WHERE aff_campaign_id = ' + CAMP));
}

module.exports = {
  name: 'web-events',
  title: 'Web events: the goal editor and p202.track()',

  async reset(db) {
    db.truncate(GOAL_TABLES.concat(['202_identity_visitors', '202_identity_signals', '202_identity_observations', '202_identity_merges', '202_clicks_visitor']));
    db.write('DELETE FROM 202_conversion_logs WHERE campaign_id = ' + CAMP);
    db.write('DELETE FROM 202_clicks WHERE aff_campaign_id = ' + CAMP);
    db.write('DELETE FROM 202_landing_pages WHERE landing_page_id_public = ' + LP_PUBLIC);
    db.write('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' + CAMP);
    db.write('DELETE FROM 202_aff_networks WHERE aff_network_id = ' + NET);
    const now = Math.floor(Date.now() / 1000);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_aff_networks SET aff_network_id=" + NET + ", user_id=1, aff_network_name='EVAL Web Events', aff_network_deleted=0, aff_network_time=" + now);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_aff_campaigns SET aff_campaign_id=" + CAMP + ', aff_campaign_id_public=' + CAMP
      + ', user_id=1, aff_network_id=' + NET + ", aff_campaign_name='EVAL Funnel', aff_campaign_url='http://offer.example/', aff_campaign_payout=0, payout_mode='replace', aff_campaign_time=" + now);
    db.write("SET SESSION sql_mode=''; INSERT INTO 202_landing_pages SET user_id=1, landing_page_id_public=" + LP_PUBLIC + ', aff_campaign_id=' + CAMP
      + ", landing_page_nickname='EVAL Funnel LP', landing_page_url='http://localhost/', landing_page_time=" + now + ', landing_page_type=0');
  },

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'The Goals panel meets the v2 baseline, and its empty state says what goals are for',
      async run(ctx) {
        const { app, ui, expect, shot } = ctx;
        await app.goto(PAGE);
        await checks.pageBaseline(ctx, { path: PAGE, menu: 'Campaigns' });
        expect.ok(await ui.visible('#campaign-goals'), 'an edited campaign has a Goals panel');
        expect.eq(await ui.text('#campaign-goals .p202-empty__title'), 'No goals yet', 'with the kit\'s empty state, title part included');
        expect.eq(await app.disclosureOpen('#goal-form details'), false, 'the advanced goal settings start closed');
        await shot('goals-empty');
        await checks.atWidths(ctx, [390], async () => {
          await checks.flexContainersKeepTheirSpaces(ctx);
          await shot('goals-empty-390');
        });
      },
    },

    {
      name: 'Adding goals: the amount follows the value choice, a refusal sits under its field',
      async run(ctx) {
        const { app, ui, db, expect, shot } = ctx;
        await app.goto(PAGE);
        expect.ok(await ui.visible('#goal_amount'), 'a fixed value asks for its amount');
        await ui.check('#goal_value_property');
        await ui.until(async () => !(await ui.visible('#goal_amount')), { describe: 'the amount field to go' });
        expect.ok(await ui.page.$eval('#goal_amount', (el) => el.disabled), 'and a hidden amount does not post');
        await ui.check('#goal_value_fixed');

        await ui.fill({ '#goal_name': 'Signup', '#goal_event': 'signup', '#goal_amount': '1.00' });
        await app.openDisclosure('#goal-form details');
        await ui.fill({ '#goal_count': '2.5' });
        await app.submit('#saveGoal');
        expect.includes(await app.fieldErrors(), 'Reached on the Nth event: a whole number from 1 to 10000.', 'a count of 2.5 is refused in the page\'s words');
        expect.ok(await ui.exists('#goal_count.is-invalid'), 'under the field it names');
        expect.eq(await app.disclosureOpen('#goal-form details'), true, 'with Advanced open, so the refusal is on screen');
        expect.eq(await ui.value('#goal_name'), 'Signup', 'what was typed is kept');
        expect.eq(db.count('202_goals'), 0, 'and nothing is stored');
        await shot('goal-refused');

        await ui.fill({ '#goal_count': '1' });
        await app.submit('#saveGoal');
        expect.match((await app.flashes()).join(' '), /Goal saved/, 'the goal is saved and the page says so');
        expect.eq(db.value("SELECT CONCAT(g.name, '|', cg.payout IS NULL, '|', cg.notify_traffic_source) FROM 202_goals g JOIN 202_campaign_goals cg ON cg.goal_id = g.goal_id WHERE g.scope_id = " + CAMP),
          'Signup|1|1', 'paid on the campaign at its own value, telling the traffic source');

        await ui.fill({ '#goal_name': 'Purchase', '#goal_event': 'purchase' });
        await ui.check('#goal_value_property');
        await app.submit('#saveGoal');
        expect.eq(db.count('202_goals', 'scope_id=' + CAMP), 2, 'a second goal, valued from the event\'s amount');
        expect.eq(await ui.count('#goal-list .p202-list__item'), 2, 'both are listed');
        expect.ok(await ui.visible('#campaign-goals .p202-flash'), 'a campaign that keeps the latest conversion is warned that two paid goals do not add up');
        await checks.flexContainersKeepTheirSpaces(ctx);
        await shot('goals-listed');
      },
    },

    {
      name: 'Editing fills the form; archiving asks first',
      async run(ctx) {
        const { app, ui, db, expect } = ctx;
        await app.goto(PAGE);
        const signup = goalId(db, 'Signup');
        await app.submit('#goal-list [data-goal-id="' + signup + '"] a.p202-list__action');
        expect.eq(await ui.value('#goal_name'), 'Signup', 'edit fills the form with the goal');
        expect.eq(await ui.value('#goal_amount'), '1.00', 'and its amount');
        expect.ok(await ui.exists('#goal-list [data-goal-id="' + signup + '"].is-active'), 'and marks it in the list');
        await ui.fill({ '#goal_amount': '1.50' });
        await app.submit('#saveGoal');
        expect.eq(Number(db.value('SELECT current_version FROM 202_goals WHERE goal_id=' + signup)), 2, 'saving an edit adds a version');

        db.write("SET SESSION sql_mode=''; INSERT INTO 202_goals SET user_id=1, scope='campaign', scope_id=" + CAMP + ", name='Doomed', current_version=1, created_at=1, updated_at=1");
        const doomed = goalId(db, 'Doomed');
        db.write("INSERT INTO 202_goal_versions SET goal_id=" + doomed + ", version=1, definition='{\"name\":\"Doomed\",\"trigger\":{\"event\":\"doom\",\"where\":[]},\"threshold\":{\"count\":1},\"after\":[],\"within\":null,\"repeat\":{\"mode\":\"once\"},\"value\":{\"type\":\"none\"}}', effective_at=1, created_at=1");
        await app.goto(PAGE);
        const button = '#goal-list [data-goal-id="' + doomed + '"] button.p202-list__action--danger';
        const dismissed = await app.confirmAnd('dismiss', button);
        expect.match(dismissed, /Archive the goal "Doomed"/, 'archive asks, naming the goal');
        expect.eq(db.value('SELECT archived_at IS NULL FROM 202_goals WHERE goal_id=' + doomed), '1', 'dismissing keeps it');
        await app.confirmAnd('accept', button);
        expect.eq(db.value('SELECT archived_at IS NOT NULL FROM 202_goals WHERE goal_id=' + doomed), '1', 'accepting archives it');
        expect.match((await app.flashes()).join(' '), /Goal archived/, 'and says so');
        expect.eq(await ui.text('#goal-list [data-goal-id="' + doomed + '"] .p202-pill'), 'archived', 'it stays listed, as archived');
      },
    },

    {
      name: 'p202.track() on the landing page waits for its pageview and reaches the goal',
      async run(ctx) {
        const { page, db, expect, state } = ctx;
        db.write("UPDATE 202_aff_campaigns SET payout_mode='accumulate' WHERE aff_campaign_id=" + CAMP);
        const origin = await pageServer(ctx);
        const beacon = page.waitForResponse((r) => r.url().includes('/tracking202/static/record.php'));
        const sent = page.waitForResponse((r) => r.url().includes('/tracking202/static/event.php') && r.request().method() === 'POST');
        const order = [];
        page.on('request', (r) => {
          if (r.url().includes('/tracking202/static/record.php') || r.url().includes('/tracking202/static/event.php')) {
            order.push(r.url().includes('record.php') ? 'pageview' : 'event');
          }
        });
        await page.goto(origin + '/');
        await beacon;
        const answer = await sent;
        expect.eq(answer.status(), 202, 'the event is accepted');
        expect.eq(order.slice(0, 2).join(','), 'pageview,event', 'a call made as the page loads waits for its pageview to be recorded');
        const id = await page.evaluate(() => window.signupSent);
        expect.match(id, /^js-[0-9a-f]{32}$/, 'p202.track() resolves to the event\'s id');

        const click = lpClick(db);
        state.lpClick = click;
        state.lpid = await page.evaluate(() => window.localStorage.getItem('p202lpid'));
        expect.eq(db.value("SELECT CONCAT(subject_id, '|', name, '|', revenue_trusted) FROM 202_goal_events WHERE event_id='" + id + "'"),
          click + '|signup|0', 'stored on the pageview\'s click');
        expect.eq(db.value('SELECT CONCAT(click_lead, "|", click_payout) FROM 202_clicks WHERE click_id=' + click), '1|1.50000',
          'which reaches Signup at its edited value');
      },
    },

    {
      name: 'A thank-you page with p202_beacon=0 records no click and reports to the landing page\'s',
      async run(ctx) {
        const { page, db, expect, state } = ctx;
        const origin = await pageServer(ctx);
        let pageviews = 0;
        const count = (r) => { if (r.url().includes('/tracking202/static/record.php')) { pageviews++; } };
        page.on('request', count);
        await page.goto(origin + '/thanks');
        const id = await page.evaluate(() => window.p202.track('purchase', {plan: 'pro'}, {revenue: 30, id: 'thanks-1'}));
        page.off('request', count);
        expect.eq(id, 'thanks-1', 'the page\'s own id is used');
        expect.eq(pageviews, 0, 'the thank-you page sends no pageview');
        expect.eq(lpClick(db), state.lpClick, 'so no click of its own is recorded');
        expect.eq(db.value("SELECT CONCAT(subject_id, '|', properties) FROM 202_goal_events WHERE event_id='thanks-1'"),
          state.lpClick + '|{"plan":"pro"}', 'the purchase goes to the landing page\'s click, with its properties');
        expect.eq(db.value('SELECT CONCAT(payable, "|", value, "|", value_note) FROM 202_goal_outcomes WHERE event_id="thanks-1"'),
          '0|30.00000|untrusted_value', 'a revenue from a page is recorded and not paid');
        expect.eq(db.value('SELECT click_payout FROM 202_clicks WHERE click_id=' + state.lpClick), '1.50000', 'so the click is still worth the signup alone');
        expect.eq(await page.evaluate(() => window.p202.track('purchase', {plan: 'pro'}, {revenue: 30, id: 'thanks-1'})), 'thanks-1', 'sending it again');
        expect.eq(db.count('202_goal_events', "event_id='thanks-1'"), 1, 'records it once');
      },
    },

    {
      name: 'A visitor who refused consent is not tracked',
      async run(ctx) {
        const { page, db, expect, state } = ctx;
        const origin = await pageServer(ctx);
        let posted = 0;
        const count = (r) => { if (r.url().includes('/tracking202/static/event.php')) { posted++; } };
        page.on('request', count);
        await page.goto(origin + '/refused');
        const before = db.count('202_goal_events');
        expect.eq(await page.evaluate(() => window.p202.track('signup')), '', 'p202.track() sends nothing and says so');
        page.off('request', count);
        expect.eq(posted, 0, 'no request is made');
        expect.eq(db.count('202_goal_events'), before, 'and nothing is stored');
        await page.evaluate(() => { window.localStorage.clear(); });
        state.lpServer.close();
      },
    },
  ],
};
