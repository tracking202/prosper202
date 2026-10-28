'use strict';

/*
 * Account › Settings: the traffic sources another user's traffic source
 * accounts name, which Setup's rename could leave before 1.9.79.
 *
 * tests/Setup/TrafficSourceOwnersSettingsPanelIntegrationTest holds what the
 * page answers, refusals included. What is here is what only a browser sees:
 * the notice where an admin looks, its link opening the panel, the
 * confirmation that names what moves (saying no moves nothing), and the
 * answer said where the page lands, at two sizes where landing on the panel
 * scrolls past the top of the page.
 *
 * Each scenario plants its own takeover the way the old rename made one: an
 * UPDATE of the source's user_id, its accounts left in their user's account.
 * Everything that claims a move reads the database.
 */

const checks = require('../lib/checks');

const OWNER = 'tso_browser_owner';
const TAKER = 'tso_browser_taker';
const SOURCE = 'TSO Browser Source';
const NOTICE = '#traffic-source-owners-notice';
const PANEL = '#traffic-source-owners';

function userIds(db) {
  return db.value("SELECT IFNULL(GROUP_CONCAT(user_id), '') FROM 202_users WHERE user_name IN ('" + OWNER + "', '" + TAKER + "')");
}

function drop(db) {
  const ids = userIds(db);
  db.write("DELETE FROM 202_ppc_accounts WHERE ppc_account_name LIKE 'TSO Browser %'");
  db.write("DELETE FROM 202_ppc_networks WHERE ppc_network_name = '" + SOURCE + "'");
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
async function openConfirmation(ui, state) {
  await ui.click('tr[data-ppc-network-id="' + state.source + '"] button[data-bs-toggle="modal"]');
  await ui.untilInPage((id) => {
    const modal = document.getElementById(id);
    return modal !== null && modal.classList.contains('show') && getComputedStyle(modal).display === 'block';
  }, 'ts-repair-' + state.source, { describe: 'the confirmation to open' });
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
  ],
};
