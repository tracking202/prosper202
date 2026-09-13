'use strict';

/*
 * Checks every page on the v2 shell should pass, written once.
 *
 * A spec asserts what its own page does; these assert what the standard
 * requires of any page, so migrating the next family costs one call rather
 * than a re-derivation. They are deliberately about the shell and the
 * component layer, never about a particular page's content.
 */

/**
 * The baseline every v2 page owes: the right shell, nothing thrown, no
 * surprise confirms, and no sideways scroll.
 *
 * @param {{app: import('./app').App, ui: import('./ui').Ui, session: object,
 *          expect: import('./report').Expect}} ctx
 */
async function baseline(ctx, options = {}) {
  const { app, ui, session, expect } = ctx;
  const shell = options.shell || 'v2';

  expect.eq(await app.shell(), shell, 'the page renders on the ' + shell + ' shell');
  expect.eq(await ui.horizontalOverflow(), 0, 'the page does not scroll sideways');

  const errors = session.errors;
  expect.ok(errors.length === 0, 'no JavaScript errors on this page', errors.slice(0, 3).join(' | '));

  const dialogs = session.unexpectedDialogs;
  expect.ok(dialogs.length === 0, 'no confirm appeared where none was expected', dialogs.join(' | '));
}

/**
 * Bootstrap 3 and Flat UI classes in the LIVE dom.
 *
 * tests/Api/V3/NoLegacyBootstrapClassesTest reads the source and says outright
 * that it cannot see a class a script adds at runtime. This looks at what is
 * actually on the page, so those are in scope here.
 *
 * @param {string[]} banned class names the v2 shell does not style
 */
async function noLegacyClasses(ctx, banned, options = {}) {
  const { ui, expect } = ctx;
  const found = await ui.page.evaluate((list) => {
    const set = new Set(list);
    const hits = [];
    document.querySelectorAll('[class]').forEach((el) => {
      el.classList.forEach((name) => {
        if (set.has(name) || /^fui-/.test(name)) {
          hits.push(name + ' on <' + el.tagName.toLowerCase() + '>');
        }
      });
    });
    return hits.slice(0, 10);
  }, banned);
  expect.ok(found.length === 0, options.name || 'no Bootstrap 3 or Flat UI class is on the page', found.join(', '));
}

/**
 * Classes that are hooks rather than styling, and are meant to be unstyled.
 *
 * The shell stamps `p202-shell-<ui> p202-section-<nav1> p202-sub-<nav2>` on
 * the body so a page family CAN be scoped later; until one needs it there is
 * no rule naming them, and that is the design rather than a mistake. They are
 * built by concatenation, so no literal token appears in any source file —
 * which is why the static test never saw them and this one does.
 */
const SCOPING_HOOKS = /^p202-(shell|section|sub)-/;

/**
 * Every first-party class on the page is named by a stylesheet rule.
 *
 * The runtime half of tests/Api/V3/ComponentClassIsConsumedTest: this reads
 * the CSSOM, so a class assembled in PHP or added by a script — the cases the
 * static test documents as invisible to it — are checked here.
 *
 * @param {string[]} [scriptOnly] classes this page uses as script hooks and
 *   that are legitimately unstyled.
 */
async function componentClassesAreStyled(ctx, scriptOnly = []) {
  const { ui, expect } = ctx;
  const orphans = await ui.page.evaluate((allowed) => {
    const defined = new Set();
    const collect = (rules) => {
      for (const rule of rules) {
        if (rule.selectorText) {
          (rule.selectorText.match(/\.p202c?-[A-Za-z0-9_-]+/g) || [])
            .forEach((selector) => defined.add(selector.slice(1)));
        }
        if (rule.cssRules) { collect(rule.cssRules); }
      }
    };
    for (const sheet of document.styleSheets) {
      try {
        collect(sheet.cssRules);
      } catch (error) {
        // A cross-origin sheet cannot be read; the shell serves its own.
      }
    }
    const allow = new Set(allowed.names);
    const hooks = new RegExp(allowed.hooks);
    const used = new Set();
    document.querySelectorAll('[class]').forEach((el) => {
      el.classList.forEach((name) => {
        if (!/^p202c?-/.test(name)) { return; }
        if (defined.has(name) || allow.has(name) || hooks.test(name)) { return; }
        used.add(name);
      });
    });
    return Array.from(used);
  }, { names: scriptOnly, hooks: SCOPING_HOOKS.source });

  expect.ok(orphans.length === 0,
    'every p202 class on the page is styled by a rule',
    orphans.join(', '));
}

/**
 * Run a body of checks at several widths, restoring the viewport afterwards.
 *
 * @param {(width: number) => Promise<void>} body
 */
async function atWidths(ctx, widths, body) {
  const { ui, expect } = ctx;
  const original = ui.page.viewportSize();
  try {
    for (const width of widths) {
      await ui.setViewport(width, original ? original.height : 900);
      await ui.ready();
      expect.section('At ' + width + 'px');
      expect.eq(await ui.horizontalOverflow(), 0, 'the page does not scroll sideways');
      await body(width);
    }
  } finally {
    if (original) { await ui.setViewport(original.width, original.height); }
  }
}

/**
 * The page is actually dark in dark mode.
 *
 * Asserts the painted colours rather than the presence of a class, because a
 * theme that is declared and not applied looks identical in the markup.
 */
async function darkThemeApplies(ctx) {
  const { ui, expect } = ctx;
  const background = await ui.luminance('body', 'background-color');
  const foreground = await ui.luminance('body', 'color');
  expect.ok(background !== null && background < 90, 'the page paints a dark background', String(background));
  expect.ok(foreground !== null && foreground > 140, 'with light text on it', String(foreground));

  if (await ui.exists('.p202-panel')) {
    const panel = await ui.luminance('.p202-panel', 'background-color');
    expect.ok(panel !== null && panel < 110, 'and panels follow the theme', String(panel));
  }
}

/**
 * A table too wide for the screen scrolls inside its own box rather than
 * taking the page with it.
 */
async function tablesScrollThemselves(ctx) {
  const { ui, expect } = ctx;
  if (!(await ui.exists('.p202-table-wrap'))) {
    expect.skip('wide tables scroll inside their own box', 'no table on this page');
    return;
  }
  const contained = await ui.page.evaluate(() => {
    const wraps = Array.from(document.querySelectorAll('.p202-table-wrap'));
    return wraps.every((w) => {
      const style = getComputedStyle(w);
      return style.overflowX === 'auto' || style.overflowX === 'scroll' || w.scrollWidth <= w.clientWidth;
    });
  });
  ctx.expect.ok(contained, 'wide tables scroll inside their own box');
}

module.exports = {
  baseline,
  noLegacyClasses,
  componentClassesAreStyled,
  atWidths,
  darkThemeApplies,
  tablesScrollThemselves,
};
