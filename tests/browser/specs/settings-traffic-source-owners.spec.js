'use strict';

/*
 * Account › Settings: the traffic sources another user's traffic source
 * accounts name, which Setup's rename could leave before 1.9.79.
 *
 * tests/Setup/TrafficSourceOwnersSettingsPanelIntegrationTest holds what the
 * page answers, refusals included. What is here is what only a browser sees:
 * the notice where an admin looks, its link opening the panel, the
 * confirmation that names what moves (saying no moves nothing), the answer
 * said where the page lands, at two sizes where landing on the panel scrolls
 * past the top of the page, and the split of a source both users' accounts
 * name, which no move clears.
 *
 * Each scenario plants its own takeover the way the old rename made one: an
 * UPDATE of the source's user_id, its accounts left in their user's account.
 * Everything that claims a move reads the database.
 */

const path = require('path');
const checks = require('../lib/checks');

const OWNER = 'tso_browser_owner';
const TAKER = 'tso_browser_taker';
const SOURCE = 'TSO Browser Source';
// 50 characters, the column's width, with one long word: it must wrap or scroll in its own box.
const LONG = 'TSO Browser Extraordinarilylongsourcenamenospaces1';
const NOTICE = '#traffic-source-owners-notice';
const SPLITS = '#traffic-source-splits-table';
const PANEL = '#traffic-source-owners';

function userIds(db) {
  return db.value("SELECT IFNULL(GROUP_CONCAT(user_id), '') FROM 202_users WHERE user_name IN ('" + OWNER + "', '" + TAKER + "')");
}

function drop(db) {
  const ids = userIds(db);
  db.write("DELETE FROM 202_change_log WHERE entity IN ('traffic_source_split', 'unrecorded_variables_said')");
  db.write("DELETE FROM 202_ppc_accounts WHERE ppc_account_name LIKE 'TSO Browser %'");
  db.write('DELETE v FROM 202_ppc_network_variables AS v INNER JOIN 202_ppc_networks AS n'
    + " ON n.ppc_network_id = v.ppc_network_id WHERE n.ppc_network_name LIKE 'TSO Browser %'");
  db.write("DELETE FROM 202_ppc_networks WHERE ppc_network_name LIKE 'TSO Browser %'");
  if (ids) {
    for (const table of ['202_dirty_hours', '202_user_role', '202_users_pref', '202_users']) {
      db.write('DELETE FROM ' + table + ' WHERE user_id IN (' + ids + ')');
    }
  }
}

function user(db, name) {
  db.write('INSERT INTO 202_users (user_name, user_pass, user_email, user_dash_email, user_time_register, install_hash, user_hash)'
    + " VALUES ('" + name + "', 'x', '" + name + "@example.test', '', UNIX_TIMESTAMP(), '', '')");
  const id = db.value("SELECT user_id FROM 202_users WHERE user_name = '" + name + "'");
  db.write('INSERT INTO 202_users_pref (user_id) VALUES (' + id + ')');
  db.write('INSERT INTO 202_user_role (user_id, role_id) VALUES (' + id + ', 3)');
  return id;
}

/** The source back in its owner's account, then taken into the taker's, as the old rename did. */
function plant(db, state) {
  db.write('UPDATE 202_ppc_networks SET user_id = ' + state.taker + ', ppc_network_time = UNIX_TIMESTAMP() + 60'
    + ' WHERE ppc_network_id = ' + state.source);
}

function holder(db, state) {
  return db.value('SELECT user_id FROM 202_ppc_networks WHERE ppc_network_id = ' + state.source);
}

/**
 * Two sources listed and three splits said: the first source taken as plant()
 * leaves it, with a custom variable (it waits for a decision, and its
 * variables are not recorded); a second with a 50-character name that an
 * account of the owner's was put on after it was saved (the cron job's to
 * split); and three splits of the last 30 days, two automatic, one of the
 * long-named source with two copies.
 */
function plantMany(db, state) {
  db.write("DELETE FROM 202_change_log WHERE entity = 'traffic_source_split'");
  db.write("DELETE FROM 202_ppc_accounts WHERE ppc_account_name IN ('TSO Browser Taker', 'TSO Browser Put On')");
  db.write('DELETE v FROM 202_ppc_network_variables AS v INNER JOIN 202_ppc_networks AS n'
    + " ON n.ppc_network_id = v.ppc_network_id WHERE n.ppc_network_name LIKE 'TSO Browser %'");
  db.write("DELETE FROM 202_ppc_networks WHERE ppc_network_name LIKE 'TSO Browser %' AND ppc_network_id <> " + state.source);
  db.write('UPDATE 202_ppc_accounts SET ppc_network_id = ' + state.source + ' WHERE user_id = ' + state.owner
    + " AND ppc_account_name LIKE 'TSO Browser %'");
  plant(db, state);
  db.write('INSERT INTO 202_ppc_network_variables (ppc_network_id, name, parameter, placeholder) VALUES ('
    + state.source + ", 'Placement', 'placement', '{placement}')");
  db.write('INSERT INTO 202_ppc_networks (user_id, ppc_network_name, ppc_network_time) VALUES ('
    + state.taker + ", '" + LONG + "', UNIX_TIMESTAMP() - 7200)");
  state.putOn = db.value("SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_name = '" + LONG + "'");
  db.write('INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ('
    + state.owner + ', ' + state.putOn + ", 'TSO Browser Put On', UNIX_TIMESTAMP() - 60)");
  const split = (source, name, actor, ago, copies) => db.write(
    'INSERT INTO 202_change_log (entity, entity_id, operation, natural_key_digest, actor_user_id, changed_at, payload)'
    + " VALUES ('traffic_source_split', '" + source + "', 'create', SHA1('tso-browser-" + source + '-' + ago + "'), "
    + actor + ', UNIX_TIMESTAMP() - ' + ago + ", '" + JSON.stringify({ ppc_network_name: name, removed: false,
      owner_user_id: Number(state.taker), copies }) + "')"
  );
  const copy = (user, id, accounts) => ({ user_id: Number(user), ppc_network_id: id, ppc_account_ids: accounts,
    custom_variables: 2 });
  split(state.putOn, LONG, 0, 600, [copy(state.owner, 900001, [900011, 900012, 900013]), copy(1, 900002, [900014])]);
  split(state.source, SOURCE, 1, 86400, [copy(state.owner, 900003, [900015])]);
  split(900099, 'TSO Browser Gone Since', 0, 172800, [copy(state.owner, 900004, [900016])]);
}

/** Sources the report lists, of anyone's: the notice counts them all. */
function listed(db) {
  return Number(db.value('SELECT COUNT(DISTINCT pa.ppc_network_id) FROM 202_ppc_accounts AS pa'
    + ' INNER JOIN 202_ppc_networks AS pn ON pn.ppc_network_id = pa.ppc_network_id WHERE pa.user_id <> pn.user_id'));
}

/**
 * Settings, then the notice's link, as an admin reaches the panel. Never a
 * goto of the panel's own URL: from the panel, that is a jump to its fragment
 * and draws nothing again.
 */
async function openPanel(app, ui) {
  await app.goto('/202-account/administration.php');
  await ui.clickThrough(NOTICE + ' a');
}

/** Open this source's confirmation from the panel, and wait for it to show. */
async function openConfirmation(ui, state, kind = 'repair') {
  const id = 'ts-' + kind + '-' + state.source;
  await ui.click('tr[data-ppc-network-id="' + state.source + '"] button[data-bs-target="#' + id + '"]');
  await ui.untilInPage((modalId) => {
    const modal = document.getElementById(modalId);
    return modal !== null && modal.classList.contains('show') && getComputedStyle(modal).display === 'block';
  }, id, { describe: 'the confirmation to open' });
}

/**
 * The source back as setup made it, then taken into the taker's account with
 * an account of the taker's own on it: both users' accounts name it, so it is
 * listed whichever account it is in.
 */
function plantShared(db, state) {
  db.write("DELETE FROM 202_ppc_networks WHERE ppc_network_name = '" + SOURCE + "' AND ppc_network_id <> " + state.source);
  db.write('UPDATE 202_ppc_accounts SET ppc_network_id = ' + state.source + ' WHERE user_id = ' + state.owner
    + " AND ppc_account_name LIKE 'TSO Browser %'");
  db.write("DELETE FROM 202_ppc_accounts WHERE ppc_account_name = 'TSO Browser Taker'");
  plant(db, state);
  db.write('INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ('
    + state.taker + ', ' + state.source + ", 'TSO Browser Taker', UNIX_TIMESTAMP() + 120)");
}

module.exports = {
  name: 'settings-traffic-source-owners',
  title: 'Settings › traffic sources in another user\'s account',

  async reset(db) {
    drop(db);
  },

  async setup(ctx) {
    const { db, state } = ctx;
    drop(db);
    state.owner = user(db, OWNER);
    state.taker = user(db, TAKER);
    db.write("INSERT INTO 202_ppc_networks (user_id, ppc_network_name, ppc_network_time) VALUES ("
      + state.owner + ", '" + SOURCE + "', UNIX_TIMESTAMP() - 3600)");
    state.source = db.value("SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_name = '" + SOURCE + "'");
    for (const name of ['TSO Browser Search', 'TSO Browser Display']) {
      db.write('INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ('
        + state.owner + ', ' + state.source + ", '" + name + "', UNIX_TIMESTAMP() - 3600)");
    }
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'An admin is told on Settings, and the notice opens the panel',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        plant(db, state);
        await app.goto('/202-account/administration.php');
        expect.ok(await ui.visible(NOTICE), 'the notice is shown');
        expect.match(await ui.text(NOTICE), /traffic sources? (is|are) in one user's account while another user's traffic source accounts name/, 'it says what it found');
        expect.notOk(await ui.exists(PANEL), 'the panel is not drawn until asked');
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);

        await ui.clickThrough(NOTICE + ' a');
        const url = new URL(ui.page.url());
        expect.eq(url.search + url.hash, '?traffic_sources=review#traffic-source-owners', 'its link opens the panel');
        const row = 'tr[data-ppc-network-id="' + state.source + '"]';
        expect.ok(await ui.visible(row), 'the panel lists the source');
        const text = (await ui.text(row)).replace(/\s+/g, ' ');
        expect.ok(text.includes(SOURCE) && text.includes(TAKER) && text.includes(OWNER), 'naming the source, who has it and whose accounts name it', text);
        expect.match(text, /2 traffic source accounts \(\d+, \d+\)/, 'and the accounts naming it', text);
        let landed = true;
        await ui.untilInPage((id) => {
          const y = document.getElementById(id).getBoundingClientRect().y;
          return y >= 0 && y < window.innerHeight / 2;
        }, 'traffic-source-owners', { describe: 'the page to land on the panel', timeout: 5000 }).catch(() => { landed = false; });
        const scrolled = await ui.page.evaluate(() => Math.round(window.scrollY));
        expect.ok(landed && scrolled > 0, 'the page scrolls to the panel', 'scrollY ' + scrolled);
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);
        await checks.tablesScrollThemselves(ctx);
      },
    },

    {
      name: 'The confirmation names what moves, and saying no moves nothing',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        plant(db, state);
        await openPanel(app, ui);
        await openConfirmation(ui, state);
        const modal = '#ts-repair-' + state.source;
        const said = (await ui.text(modal + ' .modal-body')).replace(/\s+/g, ' ');
        expect.ok(said.includes('“' + SOURCE + '” moves from ' + TAKER) && said.includes('to ' + OWNER),
          'it names the source, the user it leaves and the user it goes to', said);
        expect.match(said, /whose 2 traffic source accounts name it/, 'and the accounts that name it', said);
        expect.eq(await ui.text(modal + ' .modal-title'), 'Move “' + SOURCE + '” back?', 'its title asks');

        await ui.click(modal + ' button:has-text("Keep it where it is")');
        await ui.untilInPage((id) => !document.getElementById(id).classList.contains('show'), 'ts-repair-' + state.source,
          { describe: 'the confirmation to close' });
        expect.eq(holder(db, state), String(state.taker), 'saying no moves nothing');
        await checks.baseline(ctx);
      },
    },

    {
      name: 'A refusal and a move are said where the page lands, at 1280x800 and 390x844, and the notice goes',
      async run(ctx) {
        const { db, expect, state, withSession } = ctx;
        // Viewports where landing on #traffic-source-owners scrolls past the
        // top of the page, where the messages render before they are moved.
        for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
          const at = viewport.width + 'x' + viewport.height + ': ';
          await withSession({ viewport }, async ({ app, ui, session }) => {
            const inView = async (what) => {
              let landed = true;
              await ui.untilInPage(() => {
                const y = document.getElementById('traffic-source-owners').getBoundingClientRect().y;
                return y >= 0 && y < 120;
              }, undefined, { describe: 'the page to land on the panel', timeout: 5000 }).catch(() => { landed = false; });
              const seen = await ui.page.evaluate(() => {
                const flashes = document.querySelector('[data-p202-flashes]');
                const r = flashes ? flashes.getBoundingClientRect() : null;
                return {
                  scrollY: Math.round(window.scrollY),
                  panel: Math.round(document.getElementById('traffic-source-owners').getBoundingClientRect().y),
                  flash: r ? { y: Math.round(r.y), height: Math.round(r.height) } : null,
                  inPanel: flashes !== null && flashes.closest('#traffic-source-owners') !== null,
                  text: flashes ? flashes.textContent.trim() : '',
                };
              });
              expect.ok(landed && seen.scrollY > 0, at + 'the page lands on the panel, below the top', JSON.stringify(seen));
              expect.ok(seen.flash !== null && seen.flash.y >= 0 && seen.flash.y + seen.flash.height <= viewport.height,
                at + what + ' is in view', JSON.stringify(seen));
              expect.ok(seen.inPanel, at + what + ' is in the panel', JSON.stringify(seen));
              return seen.text;
            };

            plant(db, state);
            await openPanel(app, ui);
            await openConfirmation(ui, state);
            // Meanwhile the source moves to another account (user 1's).
            db.write('UPDATE 202_ppc_networks SET user_id = 1 WHERE ppc_network_id = ' + state.source);
            await app.submit('#ts-repair-' + state.source + ' button[type="submit"]');
            const refusal = await inView('the refusal');
            expect.match(refusal, /is now in user 1's account, not in user \d+'s as the report you read said\. Nothing was moved\./,
              at + 'the refusal says what changed', refusal);
            expect.eq(holder(db, state), '1', at + 'and nothing moved');

            plant(db, state);
            await openPanel(app, ui);
            await openConfirmation(ui, state);
            await app.submit('#ts-repair-' + state.source + ' button[type="submit"]');
            const moved = await inView('what moved');
            expect.match(moved, new RegExp('Traffic source "' + SOURCE + '" \\(' + state.source + '\\) moved from user '
              + state.taker + "'s account to user " + state.owner + "'s"), at + 'the move is said', moved);
            expect.eq(holder(db, state), String(state.owner), at + 'the source is its owner\'s again');
            expect.notOk(await ui.exists('tr[data-ppc-network-id="' + state.source + '"]'), at + 'and has left the panel');
            await checks.baseline({ app, ui, session, expect });

            if (listed(db) === 0) {
              expect.ok(await ui.visible(PANEL + ' .p202-empty__title'), at + 'the panel says nothing is left');
              await app.goto('/202-account/administration.php');
              expect.notOk(await ui.exists(NOTICE), at + 'and Settings no longer shows the notice');
            } else {
              expect.skip(at + 'the notice goes', 'another source on this instance is still listed');
            }
          });
        }
      },
    },

    {
      name: 'A source both users\' accounts name is split from the panel, saying no copies nothing, and the split stays said',
      async run(ctx) {
        const { app, ui, db, expect, state } = ctx;
        plantShared(db, state);
        await openPanel(app, ui);
        const row = 'tr[data-ppc-network-id="' + state.source + '"]';
        expect.ok(await ui.visible(row + ' button[data-bs-target="#ts-split-' + state.source + '"]'),
          'the row offers the split');
        await checks.baseline(ctx);
        await checks.componentClassesAreStyled(ctx);
        await checks.flexContainersKeepTheirSpaces(ctx);

        await openConfirmation(ui, state, 'split');
        const modal = '#ts-split-' + state.source;
        const said = (await ui.text(modal + ' .modal-body')).replace(/\s+/g, ' ');
        expect.ok(said.includes('“' + SOURCE + '” stays in the account of ' + TAKER), 'it names the user it stays with', said);
        expect.match(said, new RegExp(OWNER + ' \\(user \\d+\\): 2 traffic source accounts \\(\\d+, \\d+\\)'),
          'and the user who gets a copy, with the accounts that move onto it', said);
        expect.eq(await ui.text(modal + ' .modal-title'), 'Give each user their own “' + SOURCE + '”?', 'its title asks');
        await ui.click(modal + ' button:has-text("Keep it as it is")');
        await ui.untilInPage((id) => !document.getElementById(id).classList.contains('show'), 'ts-split-' + state.source,
          { describe: 'the confirmation to close' });
        expect.eq(db.value("SELECT COUNT(*) FROM 202_ppc_networks WHERE ppc_network_name = '" + SOURCE + "'"), '1',
          'saying no copies nothing');

        await openConfirmation(ui, state, 'split');
        await app.submit(modal + ' button[type="submit"]');
        const flashes = await ui.text('[data-p202-flashes]');
        expect.match(flashes, new RegExp('Traffic source "' + SOURCE + '" \\(' + state.source + '\\) stays in user '
          + state.taker + "'s account\\. User " + state.owner + "'s 2 traffic source account\\(s\\) now name a copy of their own"),
          'the split is said', flashes);
        expect.ok(await ui.exists('#traffic-source-owners [data-p202-flashes]'), 'in the panel the page lands on');
        expect.eq(db.value('SELECT COUNT(*) FROM 202_ppc_accounts AS pa INNER JOIN 202_ppc_networks AS pn'
          + ' ON pn.ppc_network_id = pa.ppc_network_id AND pn.user_id = pa.user_id'
          + " WHERE pa.ppc_account_name LIKE 'TSO Browser %' AND pn.ppc_network_name = '" + SOURCE + "'"), '3',
          'every account names a source of its own user\'s');
        expect.eq(holder(db, state), String(state.taker), 'the source stays where it was');
        expect.notOk(await ui.exists(row), 'and has left the panel');
        await checks.baseline(ctx);

        if (listed(db) === 0) {
          await app.goto('/202-account/administration.php');
          // Nothing waits any more; the split stays said for 30 days.
          expect.ok(await ui.visible(NOTICE + '.alert-info'), 'Settings says the split, as information');
          const told = (await ui.text(NOTICE)).replace(/\s+/g, ' ');
          expect.ok(told.includes('1 traffic source was split in the last 30 days.'), 'it counts what was split', told);
          expect.ok(told.includes('Nothing was taken from the other user'), 'and says what a split did', told);
          await ui.clickThrough(NOTICE + ' a');
          expect.ok(await ui.visible(PANEL + ' .p202-empty__title'), 'the panel says nothing is left to review');
          expect.eq(await ui.count(SPLITS + ' tbody tr'), 1, 'and lists the split');
          const listedSplit = (await ui.text(SPLITS + ' tbody tr')).replace(/\s+/g, ' ');
          expect.ok(listedSplit.includes(SOURCE) && listedSplit.includes(TAKER) && listedSplit.includes(OWNER)
            && listedSplit.includes('2 traffic source accounts') && listedSplit.includes('by user 1'),
          'with who kept it, who got a copy for which accounts, and who split it', listedSplit);
          await checks.baseline(ctx);
          await checks.componentClassesAreStyled(ctx);
          await checks.flexContainersKeepTheirSpaces(ctx);
          await checks.tablesScrollThemselves(ctx);
        } else {
          expect.skip('the notice says only the split', 'another source on this instance is still listed');
        }
      },
    },

    {
      name: 'What the cron job splits, what was split and what waits: many, one and none, at 1280x800 and 390x844, light and dark',
      async run(ctx) {
        const { db, expect, state, withSession, config } = ctx;
        const combos = [
          { viewport: { width: 1280, height: 800 }, colorScheme: 'light' },
          { viewport: { width: 1280, height: 800 }, colorScheme: 'dark' },
          { viewport: { width: 390, height: 844 }, colorScheme: 'light' },
          { viewport: { width: 390, height: 844 }, colorScheme: 'dark' },
        ];
        for (const options of combos) {
          const at = options.viewport.width + 'x' + options.viewport.height + ' ' + options.colorScheme + ': ';
          plantMany(db, state);
          await withSession(options, async ({ app, ui, session }) => {
            const inner = { app, ui, session, expect, db, state };
            const look = async (label) => {
              await session.page.screenshot({
                path: path.join(config.shots, 'settings-traffic-source-owners-' + options.viewport.width + '-'
                  + options.colorScheme + '-' + label + '.png'),
                fullPage: true,
              });
              await checks.baseline(inner);
              await checks.componentClassesAreStyled(inner);
              await checks.flexContainersKeepTheirSpaces(inner);
              await checks.tablesScrollThemselves(inner);
              if (options.colorScheme === 'dark') { await checks.darkThemeApplies(inner); }
            };
            const noticeFits = async (what) => {
              const box = await ui.page.evaluate((selector) => {
                const r = document.querySelector(selector).getBoundingClientRect();
                return { left: Math.round(r.left), right: Math.round(r.right), width: window.innerWidth };
              }, NOTICE);
              expect.ok(box.left >= 0 && box.right <= box.width, at + what + ' fits the screen', JSON.stringify(box));
            };

            // Many: two sources listed, three splits said.
            await app.goto('/202-account/administration.php');
            expect.ok(await ui.visible(NOTICE + '.alert-warning'), at + 'the notice warns while a source waits');
            const many = (await ui.text(NOTICE)).replace(/\s+/g, ' ');
            expect.ok(many.includes('1 can be moved back as it is.'), at + 'it counts only what waits for a decision', many);
            expect.ok(many.includes('1 of them is split by the cron job within the hour, with nothing for you to do'),
              at + 'says what the cron job does by itself', many);
            expect.ok(many.includes('3 traffic sources were split in the last 30 days, 2 of them automatically.'),
              at + 'what was split', many);
            expect.ok(many.includes('Custom variable values are not being recorded'), at + 'and what is being lost', many);
            await noticeFits('the notice');
            await look('many-notice');

            await ui.clickThrough(NOTICE + ' a');
            const waits = 'tr[data-ppc-network-id="' + state.source + '"]';
            const auto = 'tr[data-ppc-network-id="' + state.putOn + '"]';
            expect.ok(await ui.visible(waits + ' .p202-pill--warn:has-text("custom variables not recorded")'),
              at + 'the source that waits is marked as losing its variables');
            expect.ok((await ui.text(waits)).includes('Not split automatically'), at + 'and says why it is left to you');
            expect.ok(await ui.visible(auto + ' .p202-pill--good:has-text("split automatically within the hour")'),
              at + 'the source the cron job splits says so');
            expect.notOk((await ui.text(auto)).includes('you choose'), at + 'and does not ask you to choose');
            expect.eq(await ui.count(SPLITS + ' tbody tr'), 3, at + 'three splits are listed');
            const heads = await ui.page.evaluate(() => {
              const first = document.getElementById('traffic-source-owners');
              const second = document.getElementById('traffic-source-splits');
              const size = (el) => getComputedStyle(el.querySelector('.p202-panel__title')).fontSize;
              return { owners: size(first), splits: size(second),
                gap: Math.round(second.getBoundingClientRect().top - first.getBoundingClientRect().bottom) };
            });
            expect.ok(heads.owners === heads.splits && heads.gap >= 8,
              at + 'the split list is a panel of its own, titled as the panel above it and apart from it', JSON.stringify(heads));
            expect.eq(await ui.count(SPLITS + ' .p202-pill--good:has-text("automatically")'), 2, at + 'two as automatic');
            const longCell = await ui.page.evaluate((selector) => {
              const wrap = document.querySelector(selector).closest('.p202-table-wrap');
              const panel = document.getElementById('traffic-source-owners').getBoundingClientRect();
              const r = wrap.getBoundingClientRect();
              return { wrapRight: Math.round(r.right), panelRight: Math.round(panel.right), page: document.documentElement.scrollWidth,
                screen: window.innerWidth };
            }, SPLITS);
            expect.ok(longCell.wrapRight <= longCell.panelRight && longCell.page <= longCell.screen,
              at + 'a 50-character name stays inside the panel', JSON.stringify(longCell));
            await look('many-panel');

            // One: nothing waits, one split is said.
            db.write("UPDATE 202_ppc_networks SET user_id = " + state.owner + " WHERE ppc_network_name LIKE 'TSO Browser %'");
            db.write("DELETE FROM 202_change_log WHERE entity = 'traffic_source_split' AND entity_id <> '" + state.putOn + "'");
            await app.goto('/202-account/administration.php');
            expect.ok(await ui.visible(NOTICE + '.alert-info'), at + 'with nothing waiting the notice is information');
            const one = (await ui.text(NOTICE)).replace(/\s+/g, ' ');
            expect.ok(one.includes('1 traffic source was split in the last 30 days, automatically.'), at + 'one split', one);
            await noticeFits('the one-split notice');
            await ui.clickThrough(NOTICE + ' a');
            expect.ok(await ui.visible(PANEL + ' .p202-empty__title'), at + 'the panel says nothing is left to review');
            expect.eq(await ui.count(SPLITS + ' tbody tr'), 1, at + 'and lists the one split');
            await look('one-split');

            // None: nothing waits and nothing was split.
            db.write("DELETE FROM 202_change_log WHERE entity = 'traffic_source_split'");
            await app.goto('/202-account/administration.php');
            if (listed(db) === 0) {
              expect.notOk(await ui.exists(NOTICE), at + 'with nothing to say there is no notice');
            } else {
              expect.skip(at + 'no notice', 'another source on this instance is still listed');
            }
          });
        }
      },
    },
  ],
};
