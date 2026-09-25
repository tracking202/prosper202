'use strict';

/*
 * The chrome on both shells, measured against itself.
 *
 * The header, the section tabs, the sub-menu and the footer are one set of
 * markup, styled by 202-css/p202-chrome.css on both page shells, and the
 * whole-app migration moves pages between those shells one family at a
 * time. For as long as both exist, a person clicking from a classic page to
 * a v2 one must not see the navigation move. NoLegacyBootstrapClassesTest
 * keeps framework classes out of the chrome's markup; what it cannot see is
 * what each shell's <body> hands the chrome to inherit, which is where the
 * two actually drifted (see chromeGeometry() in lib/checks.js).
 *
 * One pair per chrome shape that still has a page on each shell — the Setup
 * button grid, and an account page with neither (the Analyze strip's pair
 * retired when that family finished moving) — each at a desktop and a phone
 * width, in the light and the dark colour scheme. The comparison is the
 * assertion; the screenshots are for a reader, and land in the shots
 * directory as <pair>-<width>-<scheme>-<shell>.png.
 *
 * Dark mode is asymmetric by design: the v2 shell follows the system theme
 * and the classic one has no dark theme at all. So in dark the chrome is
 * compared on geometry and type only, and each shell is asserted to paint
 * what it is meant to — v2 dark, classic light — rather than the pair being
 * asserted equal.
 */

const fs = require('fs');
const path = require('path');
const checks = require('../lib/checks');

const PAIRS = [
  // The Analyze pair went with U3, and the Setup and Account pairs with the
  // MTA rewrite: their classic pages were the old attribution model editor
  // (now a redirect to the dashboard) and the attribution dashboard (rebuilt
  // on v2 in PR 10), so neither family has a classic page left. The report
  // strip is what is left to hold across shells: Update is still classic and
  // Overview is v2, and both draw the one strip (tracking202/_config/
  // sub-menu.php) under the same section tabs.
  // The two strips name different pages, so their entries' widths follow
  // their words; everything else about them is held equal.
  { name: 'strip', classic: '/tracking202/update/subids.php', v2: '/tracking202/overview/', labelsDiffer: ['.p202c-strip__list > li > a'] },
];

const WIDTHS = [1280, 390];

/** The top of the page, where the chrome is: taller at phone width, where the header wraps. */
async function chromeShot(page, config, name) {
  fs.mkdirSync(config.shots, { recursive: true });
  const viewport = page.viewportSize();
  const file = path.join(config.shots, 'chrome-' + name + '.png');
  await page.screenshot({ path: file, clip: { x: 0, y: 0, width: viewport.width, height: viewport.width > 600 ? 300 : 480 } });
  return file;
}

function scenariosFor(scheme) {
  return WIDTHS.map((width) => ({
    name: 'The chrome matches across shells at ' + width + 'px, ' + scheme,
    async run(ctx) {
      const { withSession, config, expect } = ctx;
      await withSession({ viewport: { width, height: 900 }, colorScheme: scheme }, async ({ app, ui, page, session }) => {
        for (const pair of PAIRS) {
          expect.section(pair.name + ' at ' + width + 'px, ' + scheme);
          const measured = {};
          for (const shell of ['classic', 'v2']) {
            await app.goto(pair[shell]);
            await app.dismissSurvey();
            expect.eq(await app.shell(), shell, pair[shell] + ' renders on the ' + shell + ' shell');
            measured[shell] = await checks.chromeGeometry(ui);
            await chromeShot(page, config, pair.name + '-' + width + '-' + scheme + '-' + shell);

            const header = await ui.luminance('.p202c-header', 'background-color');
            if (shell === 'v2' && scheme === 'dark') {
              expect.ok(header !== null && header < 90, 'the v2 header follows the dark theme', String(header));
            } else {
              expect.ok(header !== null && header > 200, 'the ' + shell + ' header is light', String(header));
            }
            const errors = session.errorsHere();
            expect.ok(errors.length === 0, 'no JavaScript errors on ' + pair[shell], errors.slice(0, 3).join(' | '));
            await checks.currentSubMenuItemIsVisible({ ui, expect });
          }
          checks.chromeMatches({ expect }, measured.classic, measured.v2, {
            label: pair.name,
            paint: scheme === 'light',
            labelsDiffer: pair.labelsDiffer || [],
          });
        }
      });
    },
  }));
}

module.exports = {
  name: 'chrome-shells',
  title: 'The chrome on both shells',

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: scenariosFor('light').concat(scenariosFor('dark')),
};
