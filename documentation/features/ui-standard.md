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
| `.p202-pill` | A status pill: neutral, `--accent`, `--good`, `--warn`, `--bad`. Status only. |
| `.p202-table` | The report table inside `.p202-table-wrap`: uppercase headers, `.num` columns, `.p202-table__totals` row. |
| `.p202-list` | The side-panel list: `__item`, `__name`, `__actions`, `__children`, `is-active`. |
| `.p202-empty` | An empty state: icon, title, one sentence, one action. |
| `.p202-code` / `.p202-copy` | A read-only code box with a Copy button that says "Copied". |
| `.p202-strip` | Stacked status rows: pill, label, value, aside. |
| `.p202-flash` | An alert with an icon. |
| `.p202-skeleton` | A loading placeholder. |
| `.p202-disclosure` | The "Advanced" section of a form: a `<details>` closed by default; `data-p202-remember="<key>"` keeps the open state per browser. |
| `.p202-decided` | A one-line note where the app made a decision, with a `change` link. |

`202-account/ui-kit.php` (admin only) renders every component in every state.
Check it before inventing a class, and add the new state there when you add one.

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
8. **Remember choices.** Filters, date presets and open disclosures persist
   per user, as the report preferences already do.

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
a v2 page still carries a Bootstrap 3 class (`col-xs-*`, `panel`, `glyphicon`,
`form-horizontal`, `label label-*`, `data-toggle`, and so on), which is the
list to work through.

## The chrome is framework-neutral

The navbar, the Prosper202 CS section tabs, the sub-menu strip, the content
frame and the footer are shared by both shells. Their markup, in
`202-config/template.php` and `tracking202/_config/top.php`, uses only
`.p202c-*` classes and inline SVG icons, and is styled by
`202-css/p202-chrome.css`, which depends on neither framework and carries its
own tokens (`--p202c-*`). Never add a Bootstrap class of either version to that
markup; one of the shells would break. The account menu is a `<details>`
element, so it works with no framework script; `202-js/p202-chrome.js` only
closes it on outside clicks and Escape, and wires the theme switch on v2 pages.

## Assets are pinned and served from the install

Every third-party file is listed in `202-config/assets.php` with its version
and, for files in this repository, the SHA-384 of the exact bytes.
`tests/Api/V3/AssetManifestTest.php` checks each file against the list, so a
re-minified or swapped file, or a version that drifted from its filename, fails
the build. Nothing loads from a CDN except Highcharts, whose licence does not
allow redistribution here; its URL pins the version instead of following the
CDN's rolling build.

To add or upgrade an asset:

```sh
curl -sSL -o 202-js/vendor/<name>-<version>.min.js <release url>
openssl dgst -sha384 -binary 202-js/vendor/<name>-<version>.min.js | openssl base64 -A
```

then add or update the entry (path, version, sha384, and the version banner
the file carries) and reference it by id from `p202_shell_assets()`.

## Migration order

0. Tokens, component layer, chrome, v2 shell, asset manifest, UI kit (this).
1. New pages are built on v2 from day one.
2. The Attribution dashboard moves under Analyze on v2.
3. Setup pages.
4. Reports: `display_calendar()`, `DisplayData` and the AJAX partials.
5. Account pages and login.
6. The classic shell, Bootstrap 3, Flat UI Pro, jQuery 1.11 and the old CSS
   layers are deleted, and the structural tests apply to the whole tree.
