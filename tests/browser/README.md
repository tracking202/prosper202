# Browser passes

Checks that need a rendering engine: layout, theme, and the JavaScript a
request cannot reach. These run **locally, on demand** — they are not wired
into CI, and nothing in the pipeline depends on them.

They complement rather than replace the PHP suites. Anything expressible as a
question about the source belongs in `tests/Api/V3/` (see
`ComponentClassIsConsumedTest`, `NoLegacyBootstrapClassesTest`); anything
about stored state belongs in a PHPUnit test. What is left — and what lives
here — is the part where a browser is the only thing that knows the answer:

- **Layout.** "The token box is 94px of a 300px row" is not derivable from the
  CSS. A wrong belief about which flex property was doing the work survived a
  code review, a commit and a push, and was settled in one measurement.
- **Event handlers.** Reveal, Copy, confirm dialogs, a radio that swaps
  fields, a disclosure that remembers itself in `localStorage`.
- **Theme.** Whether the page is actually dark, rather than whether it says it
  is.

## Running

You need a live instance and a **scratch** database. The pass truncates
tables; `lib/db.js` refuses any database whose name does not read as
disposable, so pointing it at a real install fails with a sentence rather
than deleting data.

```sh
P202_BASE=http://127.0.0.1:8097 \
P202_USER=evalci P202_PASS=... \
P202_DB=p202_w1 \
node tests/browser/run.js
```

```
--spec <name>     run specs whose name contains this
--grep <pattern>  run scenarios matching this regular expression
--list            list specs and scenarios, run nothing
--headed          watch it happen
--slow <ms>       slow each action down
--keep-data       skip the reset (specs that assume a clean slate will fail)
```

Exit codes: `0` everything passed, `1` a check failed, `2` it could not run
(no instance, no database, no browser).

Screenshots land in `tests/browser/shots/`, including one per crashed
scenario, which is usually the fastest way to see what went wrong.

### Prerequisites

- **A live instance.** `tests/fixtures/agent-eval/ci/install-instance.sh`
  builds one; see the sandbox notes in `CLAUDE.md`.
- **Playwright.** Resolved from `P202_PLAYWRIGHT`, a local `node_modules`, or
  a few well-known paths. `npm install playwright-core --prefix tests/browser`
  works if you have none.
- **Chromium.** Found via `P202_CHROMIUM`, `PLAYWRIGHT_BROWSERS_PATH`, or the
  usual system locations. If Playwright downloaded its own, leave it unset.
- **`mysql` on PATH**, pointed at the instance's database.
- **`--cdn <dir>` / `P202_CDN_MIRROR`**, a directory of files named
  `<host>__<path with / as __>`, which the harness serves in place of the
  external hosts it blocks. Without it every `tracking202` page throws
  `Highcharts is not defined` — the shell loads Highcharts from its vendor CDN
  on that whole section — and the baseline check reports a page defect that is
  really a missing mirror. Each error now names the URL it came from, so the
  message says which page is throwing.

## Writing a spec

A spec is a plain object. Scenarios run in order and share a `state`, so one
can register an app and the next can use it. A scenario that throws is **one
failure**, not a dead run — the others still execute.

```js
module.exports = {
  name: 'analyze-mobile-apps',
  title: 'Analyze › Mobile Apps',

  // Optional. Only runs when --keep-data is absent, and only writes through
  // the guard in lib/db.js.
  async reset(db) {
    db.truncate(['202_app_postbacks']);
  },

  // Optional. Set it when reset() replaces the shared fixture (the
  // agent-eval seed other specs assert against) with data of its own: the
  // runner then runs this spec after every spec that does not.
  replacesFixture: false,

  // Optional. Signs in, seeds, whatever the whole spec needs once.
  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'The report totals only verified postbacks',
      async run({ app, ui, db, expect, state }) {
        await app.goto('/tracking202/analyze/mobile_apps.php');
        expect.eq(await ui.text('.p202-stat__value'), '3', 'it counts the verified ones');
      },
    },
  ],
};
```

### What a scenario is handed

| | |
|---|---|
| `app` | Prosper202 itself: `login`, `goto`, `openFromSubMenu`, `flashes`, `fieldErrors`, `messages`, `submit`, `confirmAnd`, `reveal`, `copy`, `openDisclosure` |
| `ui` | the page: `until`, `untilInPage`, `goto`, `clickThrough`, `text`, `texts`, `value`, `fill`, `select`, `validity`, `box`, `horizontalOverflow`, `luminance`, `clipboard` |
| `db` | `value`, `rows`, `count` (always safe) and `write`, `truncate`, `temporarily` (guarded) |
| `expect` | `ok`, `notOk`, `eq`, `ne`, `match`, `notMatch`, `between`, `includes`, `fail`, `skip`, `section`, `note` |
| `state` | shared between this spec's scenarios |
| `shot(label)` | a full-page screenshot |
| `withSession(opts, fn)` | a second signed-in session — another theme or viewport |
| `page`, `session`, `config` | the raw Playwright page and the run's settings |

### Reusable checks

`lib/checks.js` holds what the UI standard asks of *any* v2 page, so a new
wave gets them for one line each:

- `baseline(ctx)` — right shell, no JavaScript errors, no stray confirms, no
  sideways scroll
- `componentClassesAreStyled(ctx, scriptOnly)` — the runtime half of
  `ComponentClassIsConsumedTest`, which sees classes built in PHP or added by
  a script
- `noLegacyClasses(ctx, banned)` — Bootstrap 3 in the live DOM
- `atWidths(ctx, [400, 1280], body)` — run a body at several widths
- `darkThemeApplies(ctx)` — the painted colours, not the declared ones
- `tablesScrollThemselves(ctx)`

## Two rules for anything added here

**Wait for conditions, never for the clock.** `ui.until` and `ui.untilInPage`
poll a stated condition and report what it still was when they gave up. A
`waitForTimeout` is a guess that holds on one machine, and when it fails it
looks exactly like the bug it was meant to catch. The one honest use of a
delay is `ui.settle`, for asserting that something does *not* happen.

**Prove a new check can fail.** Break the thing on purpose and watch that
check — and ideally only that check — go red, then put it back. This is not
ceremony: while writing the first pass, three checks here passed no matter
what the page did (one read the first of three legitimately-current nav
items; one looked for a form on a page that never has one; one matched
`a:has-text("remove")` when the control is a `<button>`), and a fourth stayed
green against a planted defect because the planting was wrong, not the page.
A browser suite that cannot fail is worse than no suite, because green stops
meaning anything.
