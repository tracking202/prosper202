'use strict';

const { SELECTORS } = require('./app');

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

  // This page's errors, not the session's: a spec that arrives by clicking
  // through another page would otherwise be told about that page.
  const errors = session.errorsHere();
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
 * No flex container silently eats the spaces between its words.
 *
 * A flex container's children each become a flex item, and the whitespace
 * BETWEEN them is discarded — so `<span class="x">Choose <em>Custom Date</em>
 * to set these.</span>` renders as "ChooseCustom Dateto set these." when `.x`
 * happens to be `display: inline-flex`. Nothing in the source looks wrong,
 * the class exists, the test for unstyled classes passes, and the sentence is
 * broken. `.p202-help` is inline-flex because it is an icon component; prose
 * was put inside it twice, one wave apart, before anyone read the rendering.
 *
 * Two shapes, because only one of them is about whitespace:
 *
 *  - text next to an element with no `gap` — the spaces between them are
 *    dropped, which is the "ChooseCustom Dateto set these" case;
 *  - a run of prose as the container's only child — no whitespace is lost,
 *    but a paragraph has been handed to a component built for one icon, and
 *    it inherits that component's font size, colour and cursor. Both of the
 *    instances that actually shipped were this one, and the first version of
 *    this check could not see either of them.
 *
 * A `gap` settles the first; nothing settles the second but reading, so it
 * is reported once a container holds more than a few words.
 */
const PROSE_IN_A_LAYOUT_BOX = 24;

async function flexContainersKeepTheirSpaces(ctx) {
  const { ui, expect } = ctx;
  const offenders = await ui.page.evaluate((proseLength) => {
    const hits = [];
    document.querySelectorAll('*').forEach((el) => {
      // The structural question first: it is pure DOM, while getComputedStyle
      // resolves style for every element it is asked about, and a report page
      // is a couple of thousand of them.
      let text = '';
      let element = false;
      el.childNodes.forEach((node) => {
        if (node.nodeType === 3) { text += node.textContent; }
        if (node.nodeType === 1) { element = true; }
      });
      const words = text.trim();
      const mixed = words !== '' && element;
      const prose = words.length >= proseLength && !element;
      if (!mixed && !prose) { return; }

      const style = getComputedStyle(el);
      if (style.display !== 'flex' && style.display !== 'inline-flex') { return; }
      // 'normal' is the initial value, i.e. no gap was asked for. A gap only
      // settles the whitespace case; prose in a layout box is wrong either way.
      if (mixed && style.columnGap !== 'normal' && parseFloat(style.columnGap) > 0) { return; }

      hits.push(
        (mixed ? 'text beside an element: ' : 'prose in a layout box: ')
        + '<' + el.tagName.toLowerCase() + (el.className ? ' class="' + el.className + '"' : '') + '> '
        + JSON.stringify((el.textContent || '').trim().slice(0, 60))
      );
    });
    return hits.slice(0, 6);
  }, PROSE_IN_A_LAYOUT_BOX);

  expect.ok(offenders.length === 0,
    'no flex container eats its spaces or holds a paragraph',
    offenders.join(' | '));
}

/**
 * The sub-menu entry for the current page is on screen, not off the edge.
 *
 * The strip scrolls sideways when it does not fit and the chrome script
 * scrolls the current entry into view. Whether it fits is a question about
 * the list rather than the window — the Analyze strip overflows a 1280px
 * desktop — so this is asserted at whatever width the caller is at, and it is
 * a measurement because "the script runs" and "the item is visible" are not
 * the same claim.
 */
async function currentSubMenuItemIsVisible(ctx) {
  const { ui, expect } = ctx;
  const placement = await ui.page.evaluate((selector) => {
    const current = document.querySelector(selector);
    if (!current) { return null; }
    const list = current.closest('.p202c-subnav__list, .p202c-strip__list');
    if (!list) { return null; }
    const item = current.getBoundingClientRect();
    const box = list.getBoundingClientRect();
    return {
      label: (current.textContent || '').trim(),
      clippedLeft: Math.round(box.left - item.left),
      clippedRight: Math.round(item.right - box.right),
    };
  }, SELECTORS.subNavCurrent);

  if (placement === null) {
    expect.skip('the current sub-menu entry is fully visible', 'no current entry on this page');
    return;
  }
  // A pixel of anti-aliasing is not a clipped label.
  expect.ok(placement.clippedLeft <= 1 && placement.clippedRight <= 1,
    'the current sub-menu entry is fully visible',
    placement.label + ' clipped by ' + placement.clippedLeft + 'px left, ' + placement.clippedRight + 'px right');
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

/**
 * The chrome, measured, so the two shells can be compared.
 *
 * The header, section tabs, sub-menu and footer are one set of markup styled
 * by 202-css/p202-chrome.css on both shells — but each shell brings its own
 * <body>, and anything the chrome leaves to inherit comes out at two sizes.
 * That is the one way the two can drift apart that no source test can see:
 * the Analyze strip was 37px tall on a classic page and 43px on a v2 one
 * because it inherited Flat UI Pro's line height of 1 on one and Bootstrap
 * 5's 1.5 on the other, and the account toggle was a pixel taller on classic
 * because Bootstrap 5's reboot centres an <svg> that Bootstrap 3 leaves on
 * the baseline.
 *
 * Each part lists every element it matches, in order, with its box and the
 * computed properties that decide how it reads. The account menu is opened
 * for the measurement and closed afterwards, so its items are measured too.
 *
 * `scrolls` marks items inside a list that scrolls sideways: their x depends
 * on which entry is current, which differs between the two pages by design.
 * `afterContent` marks parts below the page content, whose y depends on it.
 */
const CHROME_PARTS = [
  { sel: '.p202c-header' },
  { sel: '.p202c-header__inner' },
  { sel: '.p202c-brand' },
  { sel: '.p202c-nav' },
  { sel: '.p202c-nav__link' },
  { sel: '.p202c-nav__link .p202c-icon' },
  { sel: '.p202c-account' },
  { sel: '.p202c-menu__toggle' },
  { sel: '.p202c-menu__toggle > *' },
  { sel: '.p202c-menu__list a' },
  { sel: '.p202c-tabs' },
  { sel: '.p202c-tabs__list > li > a', scrolls: true },
  { sel: '.p202c-strip' },
  { sel: '.p202c-strip__list' },
  { sel: '.p202c-strip__list > li > a', scrolls: true },
  { sel: '.p202c-subnav' },
  { sel: '.p202c-subnav__link' },
  { sel: '.p202c-footer', afterContent: true },
];

const CHROME_TYPE = ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing',
  'padding', 'margin', 'border-radius', 'border-top-width', 'display'];
const CHROME_PAINT = ['color', 'background-color', 'border-top-color'];

async function chromeGeometry(ui) {
  const page = ui.page;
  // Lato is a web font; a box measured before it lands is the fallback's.
  await page.evaluate(() => (document.fonts ? document.fonts.ready.then(() => true) : true));
  // The pointer stays where the last page left it, so whatever is under it
  // now is painted in its hover state — the account toggle, usually, since
  // that is what the previous measurement clicked. Park it on the page
  // margin and let every transition finish before reading a colour.
  await page.mouse.move(0, 0);
  const still = async (describe) => ui.untilInPage(
    // Only the chrome's own: a page may run an endless one (the kit's
    // skeleton shimmers for as long as it is open).
    () => !document.getAnimations || document.getAnimations().every((animation) => {
      const target = animation.effect && animation.effect.target;
      return !(target && target.closest
        && target.closest('.p202c-header, .p202c-tabs, .p202c-strip, .p202c-subnav, .p202c-footer'));
    }),
    undefined, { describe });
  await still('the chrome to stop transitioning');

  const measure = (only) => page.evaluate(({ parts, type, paint, only }) => {
    const out = {};
    for (const part of parts) {
      if (only && !only.includes(part.sel)) { continue; }
      out[part.sel] = Array.from(document.querySelectorAll(part.sel)).map((el) => {
        const box = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        const read = (props) => {
          const values = {};
          props.forEach((prop) => { values[prop] = style.getPropertyValue(prop); });
          return values;
        };
        // "Current" differs between two different pages by design: the
        // current tab, sub-menu entry or nav link is painted differently.
        const current = el.matches('.is-active, [aria-current="page"], .active > *, .is-active *, .active *')
          || el.classList.contains('active');
        return {
          text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 32),
          // Document coordinates: a page that focuses a field on load
          // scrolls, and the chrome has not moved when it does.
          x: box.x + window.scrollX, y: box.y + window.scrollY, w: box.width, h: box.height,
          type: read(type),
          paint: read(paint),
          current,
        };
      });
    }
    return out;
  }, { parts: CHROME_PARTS, type: CHROME_TYPE, paint: CHROME_PAINT, only });

  // Everything but the menu's items, with the menu shut: an open menu paints
  // its toggle in the hover colours.
  const parts = await measure(null);
  const toggle = await page.$('.p202c-menu__toggle');
  if (toggle) {
    await toggle.click();
    await ui.untilInPage(() => {
      const menu = document.querySelector('details.p202c-menu');
      return menu !== null && menu.open;
    }, undefined, { describe: 'the account menu to open' });
    await page.mouse.move(0, 0);
    await still('the account menu to stop transitioning');
    Object.assign(parts, await measure(['.p202c-menu__list a']));
    await page.keyboard.press('Escape');
    await ui.untilInPage(() => {
      const menu = document.querySelector('details.p202c-menu');
      return menu === null || !menu.open;
    }, undefined, { describe: 'the account menu to close' });
  }
  return parts;
}

/**
 * Two measurements of the chrome render the same.
 *
 * One claim per part, so a failure names the part and lists what differs
 * rather than reporting one opaque "the chrome differs". Positions and sizes
 * match to a pixel (sub-pixel layout rounds differently between two
 * documents); type and spacing match exactly. Colours are compared only
 * when `paint` is set — the classic shell has no dark theme, so in dark mode
 * the two are meant to differ — and never for the current entry, which is a
 * different entry on two different pages.
 *
 * @param {{expect: import('./report').Expect}} ctx
 * @param {object} a  chromeGeometry() of the first page
 * @param {object} b  chromeGeometry() of the second page
 * @param {{label?: string, paint?: boolean}} [options]
 */
function chromeMatches(ctx, a, b, options = {}) {
  const { expect } = ctx;
  const label = options.label ? options.label + ': ' : '';
  const near = (p, q) => Math.abs(p - q) <= 1;
  let compared = 0;
  for (const part of CHROME_PARTS) {
    const left = a[part.sel] || [];
    const right = b[part.sel] || [];
    if (left.length === 0 && right.length === 0) { continue; }
    compared++;
    const diffs = [];
    if (left.length !== right.length) {
      diffs.push('count ' + left.length + ' vs ' + right.length);
    }
    for (let i = 0; i < Math.min(left.length, right.length); i++) {
      const p = left[i];
      const q = right[i];
      const where = '[' + i + ' ' + JSON.stringify(p.text) + '] ';
      const keys = ['w', 'h'];
      if (!part.scrolls) { keys.push('x'); }
      if (!part.afterContent) { keys.push('y'); }
      keys.forEach((key) => {
        if (!near(p[key], q[key])) { diffs.push(where + key + ' ' + Math.round(p[key]) + ' vs ' + Math.round(q[key])); }
      });
      // The current entry is bold and coloured on purpose, and is a
      // different entry on two different pages; only its box is compared.
      const current = p.current || q.current;
      Object.keys(p.type).forEach((prop) => {
        if (current && prop === 'font-weight') { return; }
        if (p.type[prop] !== q.type[prop]) { diffs.push(where + prop + ' ' + p.type[prop] + ' vs ' + q.type[prop]); }
      });
      if (options.paint && !current) {
        Object.keys(p.paint).forEach((prop) => {
          if (p.paint[prop] !== q.paint[prop]) { diffs.push(where + prop + ' ' + p.paint[prop] + ' vs ' + q.paint[prop]); }
        });
      }
    }
    expect.ok(diffs.length === 0, label + part.sel + ' renders the same on both shells', diffs.slice(0, 4).join('; '));
  }
  expect.ok(compared > 0, label + 'there was chrome to compare');
}

/* U4: Setup ---------------------------------------------------------------
 * The Setup pages on the v2 shell, one entry each: the page, the sub-menu
 * entry that marks it current, and the classes it uses only as script hooks
 * (legitimately unstyled). `pageBaseline` runs every check the standard asks
 * of a v2 page against one entry, so a spec walks the list rather than
 * repeating the checks per page.
 */
const LEGACY_SAMPLE = ['col-xs-12', 'col-xs-6', 'col-md-offset-4', 'panel', 'panel-body', 'panel-heading', 'well',
  'form-horizontal', 'form-group', 'control-label', 'input-sm', 'btn-default', 'btn-xs', 'btn-block', 'help-block',
  'glyphicon', 'label', 'pull-right', 'sr-only', 'input-group-addon', 'radio', 'checkbox'];

const SETUP_PAGES = [
  { path: '/tracking202/setup/ppc_accounts.php', menu: 'Traffic Sources' },
  { path: '/tracking202/setup/aff_networks.php', menu: 'Categories' },
  { path: '/tracking202/setup/aff_campaigns.php', menu: 'Campaigns' },
  { path: '/tracking202/setup/landing_pages.php', menu: 'Landing Pages' },
  { path: '/tracking202/setup/text_ads.php', menu: 'Text Ads' },
  { path: '/tracking202/setup/rotator.php', menu: 'Redirector' },
  { path: '/tracking202/setup/get_simple_landing_code.php', menu: 'Get LP Code' },
  { path: '/tracking202/setup/get_adv_landing_code.php', menu: 'Get LP Code' },
  { path: '/tracking202/setup/get_dynamic_smart_component_code.php', menu: null },
  { path: '/tracking202/setup/get_trackers.php', menu: 'Get Links' },
  { path: '/tracking202/setup/get_postback.php', menu: 'Postback/Pixel' },
];

/**
 * Everything the standard asks of a v2 page, for one SETUP_PAGES entry:
 * the baseline, no legacy class in the live DOM, every component class
 * styled, no flex container eating its spaces, and the page's own sub-menu
 * entry current and on screen. The caller has navigated to the page.
 */
async function pageBaseline(ctx, entry) {
  const { app, expect } = ctx;
  await baseline(ctx);
  await noLegacyClasses(ctx, LEGACY_SAMPLE);
  await componentClassesAreStyled(ctx, entry.scriptOnly || []);
  await flexContainersKeepTheirSpaces(ctx);
  if (entry.menu) {
    expect.eq(await app.currentSubMenuItem(), entry.menu, 'the sub-menu marks ' + entry.menu + ' as current');
    await currentSubMenuItemIsVisible(ctx);
  }
}

module.exports = {
  SETUP_PAGES,
  pageBaseline,
  chromeGeometry,
  chromeMatches,
  baseline,
  noLegacyClasses,
  componentClassesAreStyled,
  flexContainersKeepTheirSpaces,
  currentSubMenuItemIsVisible,
  atWidths,
  darkThemeApplies,
  tablesScrollThemselves,
};

/* U3: Analyze */

/**
 * The Analyze report pages on the v2 shell, one entry each: where it is, the
 * sub-menu label that reaches it, and the heading it opens with. A page added
 * to the family is a line here, and every pass that walks the list covers it.
 */
const ANALYZE_REPORT_PAGES = [
  { path: '/tracking202/analyze/keywords.php', menu: 'Keywords', heading: 'Keywords' },
  { path: '/tracking202/analyze/text_ads.php', menu: 'Text Ads', heading: 'Text Ads' },
  { path: '/tracking202/analyze/referers.php', menu: 'Referers', heading: 'Referers' },
  { path: '/tracking202/analyze/ips.php', menu: 'IPs', heading: 'IP Addresses' },
  { path: '/tracking202/analyze/countries.php', menu: 'Countries', heading: 'Countries' },
  { path: '/tracking202/analyze/regions.php', menu: 'Regions', heading: 'Regions' },
  { path: '/tracking202/analyze/cities.php', menu: 'Cities', heading: 'Cities' },
  { path: '/tracking202/analyze/isp.php', menu: 'ISP/Carrier', heading: 'ISPs and Carriers' },
  { path: '/tracking202/analyze/landing_pages.php', menu: 'Landing Pages', heading: 'Landing Pages' },
  { path: '/tracking202/analyze/devices.php', menu: 'Devices', heading: 'Devices' },
  { path: '/tracking202/analyze/browsers.php', menu: 'Browsers', heading: 'Browsers' },
  { path: '/tracking202/analyze/platforms.php', menu: 'Platforms', heading: 'Platforms' },
  { path: '/tracking202/analyze/variables.php', menu: 'Custom Variables', heading: 'Custom Variables' },
];

/** Bootstrap 3 classes a migrated page most often keeps by accident. */
const LIKELY_LEFTOVERS = ['col-xs-12', 'col-xs-6', 'panel', 'panel-body', 'well', 'form-horizontal', 'input-sm', 'label', 'pull-right'];

/**
 * Everything the standard asks of any v2 page, for the page on screen: the
 * shell and no errors, every component class styled, no flex container
 * eating its spaces, the current sub-menu entry in view, no Bootstrap 3
 * class in the live DOM, and wide tables scrolling in their own box. One
 * call per page, at whatever width and theme the caller is at.
 *
 * @param {{scriptOnly?: string[]}} [options] classes this page uses only as
 *   script hooks
 */
async function v2PageBaseline(ctx, options = {}) {
  await baseline(ctx);
  await componentClassesAreStyled(ctx, options.scriptOnly || []);
  await flexContainersKeepTheirSpaces(ctx);
  await currentSubMenuItemIsVisible(ctx);
  await noLegacyClasses(ctx, LIKELY_LEFTOVERS);
  await tablesScrollThemselves(ctx);
}

module.exports.ANALYZE_REPORT_PAGES = ANALYZE_REPORT_PAGES;
module.exports.v2PageBaseline = v2PageBaseline;
/* U2: Overview, Visitors, Spy */

/**
 * The pages of the Overview, Visitors and Spy family on the v2 shell, one
 * baseline entry each: where the page is, what its sub-menu entry is called
 * (null where the section has no sub-menu), and the element that says its
 * report panel has drawn. specs/overview-visitors-spy.spec.js runs
 * overviewPageBaseline() over every entry, light and dark, at 1280px and
 * 390px; adding a page to the family is a line here.
 */
const OVERVIEW_FAMILY_PAGES = [
  { path: '/tracking202/overview/', subMenu: 'Campaign Overview', report: '#overview-report' },
  { path: '/tracking202/overview/breakdown.php', subMenu: 'Breakdown Analysis', report: '#breakdown-report' },
  { path: '/tracking202/overview/day-parting.php', subMenu: 'Day Parting', report: '#day-parting-report' },
  { path: '/tracking202/overview/week-parting.php', subMenu: 'Week Parting', report: '#week-parting-report' },
  { path: '/tracking202/overview/group-overview.php', subMenu: 'Group Overview', report: '#group-overview-report' },
  { path: '/tracking202/overview/rotator-breakdown.php', subMenu: null, report: '#rotator-breakdown-report' },
  { path: '/tracking202/visitors/', subMenu: null, report: '#visitors-report' },
  { path: '/tracking202/spy/', subMenu: null, report: '#spy-report' },
];

/**
 * Wait for a report panel drawn by 202-js/p202-overview.js: its fragment has
 * answered (aria-busy is false) and the skeleton is gone. A panel that
 * failed says so in a flash, which the caller's assertions then see.
 */
async function overviewReportDrawn(ui, selector) {
  await ui.untilInPage((sel) => {
    const panel = document.querySelector(sel);
    return panel !== null && panel.getAttribute('aria-busy') === 'false' && !panel.querySelector('.p202-skeleton');
  }, selector, { describe: 'the report in ' + selector + ' to be drawn' });
}

/**
 * Everything the standard asks of one page of the family, on the page the
 * session is already on: shell, no errors, no legacy class, every class
 * styled, no flex container eating spaces, the current sub-menu entry on
 * screen, wide tables scrolling themselves — and, in dark mode, the page
 * actually dark.
 */
async function overviewPageBaseline(ctx, entry, options = {}) {
  await overviewReportDrawn(ctx.ui, entry.report);
  const failed = await ctx.ui.exists(entry.report + ' > .alert-danger');
  ctx.expect.notOk(failed, 'the report panel drew its fragment', failed ? await ctx.ui.text(entry.report) : '');
  await baseline(ctx);
  await componentClassesAreStyled(ctx);
  await flexContainersKeepTheirSpaces(ctx);
  await noLegacyClasses(ctx, ['col-xs-6', 'col-xs-12', 'panel', 'panel-body', 'label', 'label-info', 'label-primary', 'input-sm', 'btn-xs', 'btn-default', 'form-group', 'pull-right']);
  await tablesScrollThemselves(ctx);
  if (entry.subMenu !== null) {
    await currentSubMenuItemIsVisible(ctx);
  }
  if (options.dark) {
    await darkThemeApplies(ctx);
  }
}

module.exports.OVERVIEW_FAMILY_PAGES = OVERVIEW_FAMILY_PAGES;
module.exports.overviewReportDrawn = overviewReportDrawn;
module.exports.overviewPageBaseline = overviewPageBaseline;
