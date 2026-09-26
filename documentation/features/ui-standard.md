# The Prosper202 UI standard

Prosper202's pages are moving, one page family at a time, from Bootstrap 3 with
the Flat UI Pro theme to Bootstrap 5.3 with a Prosper202 theme. This document is
the standard the new pages follow and the mechanics that let the two coexist.

## What the standard is

**Tokens.** The whole look is Bootstrap's own CSS variables, redefined once in
`202-css/p202-theme.css`: the brand blue (`#2f6fdd`) and its subtle set,
blue-biased neutrals (ink `#1f2328`, muted `#6b7280`, hairline `#e7e8ea`,
surface `#fafbfc`), semantic success, warning and danger, Lato with a real
fallback stack, radii of 6, 8, 10 and 12 pixels, one shadow. Dark mode is the
same variables under `[data-bs-theme="dark"]`. There is no Sass build: theming
is runtime CSS variables, including the per-component variables Bootstrap
compiles from Sass (buttons, form focus, tabs, pagination, list groups,
dropdowns).

**Bootstrap's components** for everything Bootstrap has: grid, cards, badges,
nav-tabs, list groups, forms, input groups, buttons, dropdowns, modals,
tooltips, popovers, toasts, collapse, pagination. A page uses them; it does not
restyle them with its own CSS.

**The component layer**, `202-css/p202-components.css`, for the recurring
pieces Bootstrap does not have. Each is a thin class on Bootstrap primitives:

| Class | Purpose |
|---|---|
| `.p202-page-header` | Title, one-line description, optional icon and action. Every page has one. `--accent` is the blue variant for the Setup family. |
| `.p202-tabs` | The in-page tab strip, a `.nav.nav-tabs` with the accent underline. `--compact` for dense strips. |
| `.p202-panel` | A titled card with a count pill, an aside slot and a body. |
| `.p202-tile` | A KPI tile: uppercase label, tabular number, sub-line; `is-good`, `is-bad`, `is-muted`. |
| `.p202-pill` | A status pill: neutral, `--accent`, `--good`, `--warn`, `--bad`. Status, or — as an `<a>` — a switch between readings of one table, which is how the report's groupings work without JavaScript. |
| `.p202-table` | The report table inside `.p202-table-wrap`: uppercase headers, `.num` columns, `.p202-table__totals` row. A sortable column's header is a `.p202-sort` button; its arrow follows `aria-sort`. |
| `.p202-list` | The side-panel list: `__item`, `__name`, `__actions`, `__children`, `is-active`. |
| `.p202-empty` | An empty state: icon, title, one sentence, one action. |
| `.p202-code` / `.p202-copy` | A read-only code box with a Copy button that says "Copied". |
| `.p202-strip` | Stacked status rows: pill, label, value, aside. |
| `.p202-flash` | An alert with an icon. |
| `.p202-skeleton` | A loading placeholder. |
| `.p202-disclosure` | The "Advanced" section of a form: a `<details>` closed by default; `data-p202-remember="<key>"` keeps the open state, per browser (it is `localStorage`, so it does not follow the user to another browser or machine). |
| `.p202-decided` | A one-line note where the app made a decision, with a `change` link. |

`202-account/ui-kit.php` (admin only) renders every component in every state.
Check it before inventing a class, and add the new state there when you add one.

Reach for a component's parts, not just its container, and read the kit's
markup for the shape — a `<h2>` dropped inside `.p202-empty` instead of
`.p202-empty__title` renders at the browser's default heading size, and prose
put inside `.p202-help` (an inline-flex icon component) loses the spaces around
its inline children, so "Choose <em>Custom Date</em> to set these" renders as
one word. Both shipped. `tests/Api/V3/ComponentClassIsConsumedTest.php` catches
a class no stylesheet names; the two browser checks
`flexContainersKeepTheirSpaces` and `currentSubMenuItemIsVisible` in
`tests/browser/lib/checks.js` catch what only a rendering engine can see.

**The principle: the app decides what it can, and says so.** A page should
need as little thought as possible from the person using it, while an advanced
user can still reach every setting. Concretely:

1. **The common case is the whole form.** A form shows only the fields the
   common case needs. Everything else sits under one `.p202-disclosure`
   labelled "Advanced", closed by default, which remembers whether the user
   opened it (`data-p202-remember`).
2. **Every default is pre-selected and explained in one line.** Fifty rows,
   the last seven days, verified only, grouped by day, newest first. A default
   never reads as an empty field the user must fill.
3. **Never ask what can be answered.** The platform comes from the store link,
   the app name from the store, HTTPS from the install URL, the tracking domain
   from the settings already made. A value the app can find, it finds.
4. **A decision the app makes is shown where its result appears**, with a
   one-click way to change it: `.p202-decided` ("Treated as an App Store id ·
   change"). Nothing is silently assumed.
5. **One primary action per page.** Destructive and rare actions sit away from
   it and confirm in a modal that says what is kept.
6. **Empty states do the first step**, or offer it as one click. Never a
   paragraph of instructions where a button would do.
7. **Nudges over settings.** When the data shows a situation with a clear next
   step (development postbacks arriving for an app that rejects them, an
   unregistered app id in a report, a receiver that stopped answering), the
   page offers the action in place.
8. **Remember choices, but let the URL win.** A report's filters belong in
   the query string, so what someone is looking at is a link they can send,
   and nothing may silently override what that link says. A per-user default
   is for the *first* visit only: the classic reports keep one in
   `202_users_pref` (`user_pref_time_predefined`, and a column per
   dimension), and a page that reads it must still yield to the URL wherever
   the URL speaks. Analyze › Mobile Apps is URL-only so far — it defaults to
   Last 30 Days rather than to anything stored. An open "Advanced" disclosure
   persists per browser, in `localStorage`, because it is a convenience
   rather than a setting. Never put anything in browser storage that another
   account sharing the browser must not see.

And the mechanics that keep pages consistent: every page opens with a page
header and a one-line purpose. Forms put labels above controls, hints below,
and errors under the hint in the API's own sentence (`.is-invalid` +
`.invalid-feedback`). Tables right-align numbers and carry a totals row where
one exists. Loading states are skeletons in place. Focus is visible on
everything, contrast holds in both themes, and dropdowns, tabs and modals work
from the keyboard. Copy is written from the user's side: "Register app", then
"Registered".

## The two shells

`template_top()` takes a `ui` option:

```php
template_top('Mobile Apps - Setup', ['ui' => 'v2']);   // Bootstrap 5.3 + theme + components
template_top('Analyze Your Keywords');                  // classic: today's stack, unchanged
```

A v2 page loads Bootstrap 5.3, Bootstrap Icons, the theme and component files,
jQuery 3.7 (for first-party glue that has not been rewritten yet) and, under
`tracking202/`, the pinned Highcharts. It does not load Bootstrap 3, Flat UI Pro
or the classic first-party layers (`custom.css`, `p202-ui.css`,
`design-system.css`, `202-js/custom.php`): the two Bootstrap versions cannot
share a page. A classic page gets exactly what it got before.

The lists live in `p202_shell_assets()` in `202-config/functions-ui.php`, and
`tests/Api/V3/ShellIsolationTest.php` asserts the two never share a framework
file and that an unknown `ui` value is an error, not a silent fallback.

To move a page to v2: pass the option, rewrite its markup with Bootstrap 5
classes and the component layer, and drop any inline `<style>` that duplicated
the old kits. `tests/Api/V3/NoLegacyBootstrapClassesTest.php` fails the build if
a v2 page still carries a Bootstrap 3 or Flat UI Pro class. Its banned set is
not a hand-written list: it is every class Bootstrap 3.3.4 defines that
Bootstrap 5.3 does not, read from the two stylesheets in the repository (about
six hundred names — every grid offset, glyphicon, panel, well and navbar
variant), plus Flat UI Pro's `fui-*` icons and component classes, plus the
Bootstrap 3 data-API attributes (`data-toggle=` and friends; Bootstrap 5 uses
`data-bs-*`). A class both versions define (`row`, `btn-primary`, `active`) is
allowed. It reads class attributes wherever they are written — plain markup,
inside a PHP string, with escaped quotes — and the ways a script names a class:
`classList.add`, `className =`, jQuery's `addClass`, and selector strings.

## Page scripts on v2: deferred, and DOM work waits for the document

The v2 shell prints its page scripts (`js_page` in `p202_shell_assets()`:
Highcharts, `chart.theme.js`, `tablesort.js`, `p202-ui.js`, `p202-chrome.js`)
in `<head>` with `defer`, so they run after the body is parsed, in the order
listed, before `DOMContentLoaded`. jQuery and Bootstrap (`js_head`) still
block, because inline scripts in a page call them as they are parsed. The
classic shell is unchanged. `p202_shell_defers_page_scripts()` is the policy
and `tests/Api/V3/UiPartialsTest` pins it.

**The porting rule.** A script moved to a v2 page does its DOM work under
`DOMContentLoaded` (or later), never as it is parsed. An inline script in the
page that needs a `js_page` library — `Highcharts`, `Tablesort`,
`window.p202ui` — calls it from a `DOMContentLoaded` handler, because at parse
time the deferred library has not run yet:

```js
document.addEventListener('DOMContentLoaded', function () {
    Highcharts.chart('chart', { /* ... */ });
});
```

A script that forgets throws at load, and the browser pass's
"no JavaScript errors on this page" check (`checks.baseline`) is what catches it.

## One replacement per legacy job

The classic shell carries a plugin for each of these; the v2 shell carries
one answer each, decided in U1 of the migration and not re-decided per page.

| Job | On v2 | Why |
|---|---|---|
| Date pickers (jQuery UI datepicker) | Native `<input type="date">`, through `p202_date_range()` | Every supported browser has one, it is keyboard- and screen-reader-accessible, and it follows the theme. It submits ISO `YYYY-MM-DD` whatever the reader's locale displays; the classic calendar posted `mm/dd/yyyy`, so a page moving to it reads the new shape. |
| Tag inputs and typeahead (tokenfield, typeahead.js, Bloodhound) | `<datalist>` on a plain text input (the filter bar's `suggest` type). No library. | Every tag input in the inventory stores a comma-separated string or one value: the rotator's rule values (`getTokensList(',')`), the device list, the subid tokens, the network-name lookup. Nothing needs chips to work, only suggestions. The rotator's suggestions come from `tracking202/ajax/rotator.php?autocomplete=…`; when Setup moves (U4) that fills the datalist, as a small first-party hook in `p202-ui.js`, not a plugin. A page that turns out to need real chips makes the case then, against this table. |
| Sortable tables (tablesorter and its widgets) | `tablesort.js` (already pinned in `assets.php`, loaded by the v2 shell), through `p202_data_table(..., ['sortable' => true])` | 3 KB, no jQuery, already the classic report fragments' sorter. Client-side sorting is honest only when every row is on the page, so it is opt-in; a paginated or truncated table sorts on the server, by link. |
| Validation (jquery-validate) | Native constraint validation (`required`, `type`, `min`/`max`, `pattern`) plus the server's own sentence under the field (`.is-invalid` + `.invalid-feedback`) | The server validates anyway and its sentence is the one that must be shown (principle: errors in the API's own words). Forms do not set `novalidate` and do not use `.was-validated`, which paints every valid field green. |
| Select boxes (select2) | Native `<select>`, with `<optgroup>`s for a two-level list. No searchable-select library. | The long lists are campaigns (grouped by category, which one `<optgroup>`ed select replaces two dependent ones with), countries (a native select finds by typing) and ISP/region/city, which are unbounded and install-wide — too long for any select, searchable or not. Those become a `suggest` field over the values the account's data has. One pinned library would bring its own dark theme and focus handling to keep in step; nothing in the inventory needs it. |

Any library added later is an entry in `202-config/assets.php` with the SHA-384
of its bytes, vendored under `202-js/vendor/`, never loaded from a CDN.

## Shared partials for report pages

`202-config/functions-ui-partials.php` (loaded with `functions-ui.php`) renders
the pieces every report family repeats. They are pure functions — no globals,
no session, no database — that return markup; a page gathers values and
option lists and calls them. The kit renders each in every state.

- `p202_date_range(array $spec): string` — the preset `<select>` and two date
  inputs, with the behaviour Analyze › Mobile Apps shipped: the dates are
  submitted only for a custom window, and typing one chooses Custom Date.
  Presets default to `p202_report_ranges()`, the classic calendar's keys
  (`today` … `alltime`), so both kinds of report mean the same window by the
  same name; `P202_RANGE_CUSTOM` is `'custom'`.
- `p202_report_filters(array $values, array $lists = [], ?array $include = null): array`
  — the classic reports' filter vocabulary as specs, under the names
  `display_calendar()` posts and `set_user_prefs.php` reads (`ppc_network_id`,
  `aff_campaign_id`, `user_pref_show`, …), so the query string and the stored
  per-user defaults map one to one. Traffic source, campaign and which clicks
  to count are the common case; the rest are Advanced.
- `p202_report_filter_bar(array $bar): string` — one GET form: range, common
  filters, one Apply, optional Reset, and an Advanced disclosure. A filter
  value the list no longer has is kept as its own option rather than shown as
  "All"; an Advanced filter that is set opens the disclosure, is counted in
  its summary, and is not folded away by a remembered "closed".
- `p202_data_table(array $columns, array $rows, array $options = []): string`
  — the `.p202-table` with right-aligned numbers, a totals row that stays
  last, the empty state in place of an empty table, `aria-sort` for the order
  the server delivered, and opt-in client-side sorting whose header buttons
  are keyboard-reachable.

## The chrome is framework-neutral

The header, the Prosper202 CS section tabs, the sub-menu (the Setup button grid
and the Overview/Analyze/Update strip), the content frame and the footer are
shared by both shells. Their markup, in `202-config/template.php`,
`tracking202/_config/top.php` and `tracking202/_config/sub-menu.php`, uses only
`.p202c-*` classes and inline SVG icons from `p202_chrome_icon()`, and is styled
by `202-css/p202-chrome.css`, which depends on neither framework and carries its
own tokens (`--p202c-*`). Never add a Bootstrap class of either version to that
markup; one of the shells would break, and the structural test above scans all
three files. The header's logo is the Prosper202 banner placement, an iframe, as
it has always been; there is no second static logo beside it.

The account menu is a `<details>` element, so it works with no framework
script. `202-js/p202-chrome.js` closes it on outside clicks and Escape, scrolls
the current tab and sub-menu item into view whenever the strip does not fit
(which is a question about the strip, not the window — the Analyze strip
overflows a 1280px desktop), and wires the theme switch on v2 pages. It is
loaded in `<head>` (deferred on v2, blocking on classic), so everything it
does runs from `DOMContentLoaded`.

The chrome states its own font size and line height rather than inheriting
them, because the two shells' `<body>` disagree (Flat UI Pro 18px/1,
Bootstrap 5 15px/1.5). `tests/browser/specs/chrome-shells.spec.js` measures
the chrome on a classic and a v2 page of the same family — at 1280px and
390px, light and dark — and fails on any difference in a box, a font or,
in light, a colour.

A page family can be styled without touching the chrome: `template_top()` puts
the shell, the section and the sub-section on `<body>` as
`p202-shell-<classic|v2> p202-section-<nav1> p202-sub-<nav2>`, which is how
`custom.css` scopes the setup pages' panel styles to `body.p202-sub-setup`.

## Assets are pinned and served from the install

Every third-party file is listed in `202-config/assets.php` with its version
and, for files in this repository, the SHA-384 of the exact bytes — Bootstrap 5
and its icons, jQuery, and on the classic side Bootstrap 3, Flat UI Pro, Font
Awesome, Select2, tokenfield, typeahead, the tablesorter plugins, tablesort,
List.js and the rest. A file whose bytes are not an upstream release build says
so in a `patched` note.

`tests/Api/V3/AssetManifestTest.php` checks each file against the list, so a
re-minified or swapped file, or a version that drifted from its filename, fails
the build. `tests/Api/V3/ShellIsolationTest.php` closes the other half: a shell
may name a bare path only for a first-party file it lists, so a new third-party
file cannot be added without a manifest entry, and it sweeps every PHP, JS and
HTML file in the tree for a `<script src>`, a stylesheet `<link>` or a quoted
`.js`/`.css` URL on an external host. Two hosted-service loaders (ProfitWell,
and the Google ad tags on the standalone and mobile pages) are listed there with
their reason; everything else must be served from the install. Highcharts is the
one library on a CDN, because its licence does not allow redistribution here;
its URL pins the version instead of following the CDN's rolling build.

To add or upgrade an asset:

```sh
curl -sSL -o 202-js/vendor/<name>-<version>.min.js <release url>
openssl dgst -sha384 -binary 202-js/vendor/<name>-<version>.min.js | openssl base64 -A
```

then add or update the entry (path, version, sha384, and the version banner
the file carries) and reference it by id from `p202_shell_assets()` — or, on a
page that builds its own head, with `p202_asset_tag('<id>', $base)`.

## Migration order

0. Tokens, component layer, chrome, v2 shell, asset manifest, UI kit (this).
1. New pages are built on v2 from day one — Setup › Mobile Apps and
   Analyze › Mobile Apps are the first two, and the second is the pattern a
   migrated report follows: a filter row in the query string, tiles, a
   grouped table with a totals row, and a CSV of exactly what is shown.
2. The Attribution dashboard moves under Analyze on v2.
3. Setup pages.
4. Reports: `display_calendar()`, `DisplayData` and the AJAX partials.
5. Account pages and login.
6. The classic shell, Bootstrap 3, Flat UI Pro, jQuery 1.11 and the old CSS
   layers are deleted, and the structural tests apply to the whole tree.
