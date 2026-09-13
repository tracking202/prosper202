'use strict';

/*
 * Loads specs and runs them.
 *
 * Two properties matter more than anything else here:
 *
 *  - A scenario that throws is one failure, not a dead run. The first version
 *    of this pass aborted on a Playwright timeout half way through, which
 *    meant a planted defect took the rest of the checks with it and the run
 *    reported nothing about them.
 *  - Whatever a spec changed about the instance is put back, even when it
 *    threw, because a spec that removes a permission to test a read-only path
 *    must not leave it removed.
 */

const fs = require('fs');
const path = require('path');

const { Report, Expect } = require('./report');
const { Db, UnsafeDatabaseError } = require('./db');
const { Ui } = require('./ui');
const { App } = require('./app');
const browserLib = require('./browser');

const SPEC_DIR = path.join(__dirname, '..', 'specs');

function discover(dir = SPEC_DIR) {
  if (!fs.existsSync(dir)) { return []; }
  return fs.readdirSync(dir)
    .filter((name) => name.endsWith('.spec.js'))
    .sort()
    .map((name) => {
      const spec = require(path.join(dir, name));
      spec.file = name;
      spec.name = spec.name || name.replace(/\.spec\.js$/, '');
      return spec;
    });
}

/**
 * Everything a scenario is handed.
 *
 * Kept small on purpose: page/ui/app for driving, db for the state behind the
 * page, expect for claims, state for passing values between scenarios, and
 * two escape hatches (shot, withSession) for the things that need them.
 */
async function buildContext(spec, session, config, db, expect, report) {
  const ui = new Ui(session.page, { timeout: config.browser.timeout });
  const app = new App(ui, session, config);

  let shotCount = 0;
  const shot = async (label) => {
    fs.mkdirSync(config.shots, { recursive: true });
    shotCount += 1;
    const file = path.join(
      config.shots,
      spec.name + '-' + String(shotCount).padStart(2, '0') + '-' + label.replace(/[^a-z0-9]+/gi, '-') + '.png'
    );
    await session.page.screenshot({ path: file, fullPage: true });
    return file;
  };

  /**
   * A second browser session — a different theme or viewport — signed in and
   * cleaned up afterwards. Used for the dark-mode and phone passes, which
   * need their own context rather than a resize.
   */
  const withSession = async (options, body) => {
    const other = await browserLib.newSession(session.browser, config, options);
    other.browser = session.browser;
    const otherUi = new Ui(other.page, { timeout: config.browser.timeout });
    const otherApp = new App(otherUi, other, config);
    try {
      await otherApp.login();
      return await body({ page: other.page, ui: otherUi, app: otherApp, session: other, db, expect, state: spec.state });
    } finally {
      await other.close();
    }
  };

  return {
    config,
    page: session.page,
    session,
    ui,
    app,
    db,
    expect,
    report,
    shot,
    withSession,
    /** Shared between the scenarios of one spec. */
    state: spec.state,
  };
}

/**
 * @param {object} options
 * @param {object} options.config
 * @param {Array} options.specs
 * @param {RegExp} [options.grep] only scenarios whose name matches
 */
async function run(options) {
  const { config, specs, grep } = options;
  const report = new Report();
  const expect = new Expect(report);
  const db = new Db(config.db);

  if (!db.reachable()) {
    console.error('Cannot reach database "' + config.db.name + '" as ' + config.db.user
      + '. Is the server running, and is P202_DB right?');
    return 2;
  }

  const browser = await browserLib.launch(config);

  try {
    for (const spec of specs) {
      spec.state = {};
      report.spec(spec.title || spec.name);

      const session = await browserLib.newSession(browser, config, spec.session || {});
      session.browser = browser;

      try {
        const ctx = await buildContext(spec, session, config, db, expect, report);

        if (typeof spec.reset === 'function' && !config.keepData) {
          await spec.reset(db, ctx);
        }
        if (typeof spec.setup === 'function') {
          await spec.setup(ctx);
        }

        for (const scenario of spec.scenarios || []) {
          if (grep && !grep.test(scenario.name)) { continue; }
          report.section(scenario.name);
          try {
            await scenario.run(ctx);
          } catch (error) {
            // One scenario's collapse must not take the others with it.
            report.crashed(scenario.name, error);
            if (config.shots) {
              try { await ctx.shot('crash-' + scenario.name); } catch (ignored) { /* best effort */ }
            }
          }
        }
      } catch (error) {
        // Pointing this at a real install is an operator mistake, not a
        // failing check: stop the whole run and print the whole message,
        // which names the remedy. Reporting it as one red line truncated to
        // its first sentence would hide the way out.
        if (error instanceof UnsafeDatabaseError) {
          throw error;
        }
        report.section(spec.name + ' (setup)');
        report.crashed(spec.name + ' setup', error);
      } finally {
        db.restore();
        await session.close();
      }
    }
  } finally {
    await browser.close();
    db.restore();
  }

  return report.summary() ? 0 : 1;
}

module.exports = { run, discover, SPEC_DIR };
