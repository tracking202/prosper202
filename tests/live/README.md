# Live passes

What a page answers over HTTP, against a running instance and a real
database. These run **locally, on demand** — like `tests/browser` — with one
exception: `upgrade-csrf.sh` also runs in CI, in the Agent Evals job, against
the instance that job installs, so the token guard on the upgrade page is
driven over HTTP on every push — and `upgrade-equals-install.sh`, which
needs no instance, runs in its own workflow.

They sit between the PHP suites and the browser passes:

| | answers | lives in |
|---|---|---|
| PHPUnit | what the code computes from given input | `tests/<Area>/` |
| **live pass** | **what the page returns, and whether it matches the database** | **here** |
| browser pass | layout, theme, and the JavaScript a request never runs | `tests/browser/` |

The middle row is the one a unit test cannot reach: a report's numbers are
only correct relative to the rows that produced them, and the seam between
the page controller, the API controller and MySQL is exactly where a mock
stops proving anything (CLAUDE.md error pattern #9).

## Running

You need a running instance and a **scratch** database — these passes
`TRUNCATE` the app tables, rewrite the account's currency, and one
of them winds `202_version` back and lets the upgrade ladder climb again.
`guard.sh` refuses a database whose name does not read as disposable, so
pointing one at a real install fails with a sentence rather than deleting
data.

It is a port of `tests/browser/lib/db.js`, not a second opinion: the two
harnesses truncate the same tables on the same instance, so a name one
accepts and the other refuses would be a trap. The disposable word has to be
a whole segment — `test`, `scratch`, `tmp`, `temp`, `ci`, `eval`, `fixture`,
`sandbox`, `probe`, `check`, or a `w<digits>` suffix — which is why
`production_live` is refused and `p202_w1` is not. To override it deliberately:
`P202_DB_ALLOW_DESTRUCTIVE=yes-i-mean-it`.

```sh
P202_BASE=http://127.0.0.1:8097 \
P202_DB=p202_test P202_DB_USER=root \
P202_USER=evalci P202_PASS=... \
bash tests/live/analyze-mobile-apps.sh
```

| variable | default | |
|---|---|---|
| `P202_BASE` | `http://127.0.0.1:8097` | where the instance answers |
| `P202_DB` | `p202_test` | scratch database; truncated |
| `P202_DB_USER` / `P202_DB_PASS` | `root` / empty | MySQL credentials |
| `P202_DB_HOST` / `P202_DB_PORT` | empty (the client's default, the local socket) | where MySQL listens; set both for a server reached over TCP, as CI does |
| `P202_USER` / `P202_PASS` | `evalci` / **required by the two mobile-apps passes** | the account to log in as; `upgrade-csrf.sh` needs no login |
| `P202_API_KEY` | **required by `app-core.sh`** | the admin's REST API key (`install-instance.sh` prints it) |

`seed-mobile-apps.sh` writes rows and makes no request, so it takes the three
`P202_DB*` variables only and needs no login. Those three are exported by
`analyze-mobile-apps.sh`, which runs the seeder as a child process: an
unexported one would leave the child on its own defaults, truncating a
different database than the pass then reads. That pass also stops if the
seeder fails, rather than checking whatever the previous run left behind.

`tests/fixtures/agent-eval/ci/install-instance.sh` stands up an instance from
nothing if you do not have one.

## The passes

| script | |
|---|---|
| `guard.sh` | the scratch-database check the other passes source. Not a pass. |
| `seed-mobile-apps.sh` | postbacks spread wide enough that every grouping, signature class and page of the pager has something real to show. Run by the analyze pass; standalone for the browser pass, and it needs no login. |
| `analyze-mobile-apps.sh` | Analyze › Mobile Apps: the three views, every grouping, the filters and the window each preset means, the totals against `SELECT COUNT(*)`, the CSV, the pager, and the permission gate. |
| `setup-mobile-apps.sh` | Setup › Mobile Apps: registering (a Play link read as Android, its name asked when no store answers), editing and removing an app, the SKAN encodings, the currency, and CSRF. |
| `mobile-apps-ui.sh` | The Mobile Apps UI over HTTP, both platforms: registering from a store link with the name and icon from a fake store (`tests/fixtures/app-store/fake_store.py` on `P202_CAPTURE_PORT`, which the instance must be started with `P202_APP_STORE_LOOKUP_ORIGIN` pointing at — a loopback origin only), the nameless, icon-less, duplicate and junk cases; Android settings and Play Integrity's order and refusals; app goals and the funnel; an iOS value naming a goal, with the horizon warning and an ambiguous postback; the link builder for both platforms and a real click carrying the token; installs and events through the intake; `GET /apps/report` (the iOS default, `platform=all`, `android`), the pages and both CLIs against the rows; the outbox per destination; and Traffic Sources' correction URLs. Needs `P202_API_KEY` and `P202_PASS`; `P202_BIN` and `P202_PHP_CLI` add the CLIs. |
| `android-intake.sh` | The Android intake end to end: a real click through dl.php whose store link carries `[[p202_install_token]]`, SDK-shaped installs (attributed with the install conversion, replay, reused id, second device, tampered MAC, bare click id, foreign click, lifted/iOS/unknown/malformed tokens, 413, the probe), events through the goals (install $2.50 + level 3 $4 accumulated, a late purchase superseding and cancelling its queued postback), organic and test installs, a pending click settled by `202-cronjobs/app-installs.php`, which also sends the postbacks (matched in the server log with `P202_SERVER_LOG`), the operator's reads and the CLI's `app install list/token/simulate`, retention, and the per-peer rate limit last. Runs the crons from this checkout, so its `202-config.php` must name the instance's database. |
| `android-sdk.sh` | The Android SDK's own engine (`sdk/android-attribution` `LiveServerTest`: file store, `HttpURLConnection`, worker thread, retry rules) against the instance: a web click carrying a signed `cust`, a phone's click through the store link whose referrer the SDK is handed; the install (attributed, install conversion), three events (level 3 paid on top), `setCustomerId` joining the phone's click to the web click's person, a relaunch that sends nothing, an organic install whose customer rode its body (`no_click`), a forged token whose events are dropped; the SDK's stored body replayed (duplicate) and changed (409), a forged customer signature (`unverified`); then Play Integrity under `require` against PR 6's fake Google (`P202_FAKE_GOOGLE_PORT`, default 8251): the SDK's token bound to its body's fingerprint, the install held `pending_integrity` with its event, the worker's valid verdict attributing and paying it and linking its customer, the relaunched SDK delivering the kept event. Needs a JDK, Gradle (`P202_GRADLE`), python3 and openssl; runs `202-cronjobs/app-installs.php` from this checkout. |
| `app-core.sh` | The app registry over the REST API: a postback waiting unclaimed until its app is registered from a store link (iOS and Android), 409 on a second registration, package-name case, the fixed identity; `GET /apps/schema` by `X-P202-App-Token` (200 per platform, 304, 400, 404, 405, no revenue); encoding ownership; the `apps` scope area and the retired `/attribution` app routes; token rotation; `DELETE /users/{id}` purging a user's apps and keys and releasing their postbacks to the next registration; the registration delete cascade; and `202-cronjobs/app-retention.php` (dry run, a malformed window, the prune). Runs the retention job from this checkout, so the checkout's `202-config.php` must name the instance's database. |
| `ios-sdk.sh` | The iOS SDK (PR 8): every goal vector through the running server's `/goals/validate` and `/goals/evaluate`; SKAN encodings naming full goals (a funnel, a sum, the install) and refusing a click window, directly or through `after`, also on a goal edit; the evaluation-only document and `p202 app schema`; the Swift SDK's own live suite against the instance when `P202_SWIFT` names a `swift` binary (reported as not run otherwise); encoding versions (the kept meanings, and the report before, inside and after the 48-day horizon, and after a delete; a deleted and re-registered app keeps decoding under its own encodings); and Setup › Mobile Apps' warning on a rule edit, with its Swift snippet compiled against the SDK. Needs `P202_API_KEY` and, for the Setup step, `P202_PASS`. |
| `upgrade-equals-install.sh` | The release gate's schema check (plan §7.6): installs the real 1.9.55 release from its own commit (`P202_ORIGIN_REF`, needs full history) with its own installer on PHP 7.4 (`P202_ORIGIN_PHP`; 1.9.55 does not parse on PHP 8), upgrades that database through this checkout's upgrade page, installs this checkout fresh into a second database, and compares every table's `SHOW CREATE TABLE` (`schema-diff.php`, normalising only index order, the table comment, partition boundaries and `AUTO_INCREMENT` — the list in `SchemaReconciler`'s docblock) and every seeded row (`CHECKSUM TABLE`, except the per-install account, key, secret and auto_cron rows). Needs no running instance: it serves both trees itself on `P202_UEI_PORT` and `+1`, and DROPS and recreates `P202_DB_UPGRADE` and `P202_DB_FRESH`, which the guard must accept. Runs in CI (`.github/workflows/upgrade-equals-install.yml`). |
| `upgrade-csrf.sh` | `202-config/upgrade.php`, the one page that takes a POST before there is a login: no token, a wrong token and a session-less replay are each answered 200 and refused with the guard's own sentence, `202_version` untouched, and the form submitted as a browser would — the fields inside it, token included — runs the ladder. Winds `202_version` back to `P202_PRIOR_VERSION` (default `1.9.75`) and leaves it at the code version. Also run by CI's Agent Evals job. |

Each prints `N passed, M failed` and exits non-zero on a failure.
