# Live passes

What a page answers over HTTP, against a running instance and a real
database. These run **locally, on demand** — like `tests/browser` — with one
exception: `upgrade-csrf.sh` also runs in CI, in the Agent Evals job, against
the instance that job installs, so the token guard on the upgrade page is
driven over HTTP on every push.

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
| `setup-mobile-apps.sh` | Setup › Mobile Apps: registering, editing and removing an app, the SKAN encodings, the currency, and CSRF. |
| `android-intake.sh` | The Android intake end to end: a real click through dl.php whose store link carries `[[p202_install_token]]`, SDK-shaped installs (attributed with the install conversion, replay, reused id, second device, tampered MAC, bare click id, foreign click, lifted/iOS/unknown/malformed tokens, 413, the probe), events through the goals (install $2.50 + level 3 $4 accumulated, a late purchase superseding and cancelling its queued postback), organic and test installs, a pending click settled by `202-cronjobs/app-installs.php`, which also sends the postbacks (matched in the server log with `P202_SERVER_LOG`), the operator's reads and the CLI's `app install list/token/simulate`, retention, and the per-peer rate limit last. Runs the crons from this checkout, so its `202-config.php` must name the instance's database. |
| `app-core.sh` | The app registry over the REST API: a postback waiting unclaimed until its app is registered from a store link (iOS and Android), 409 on a second registration, package-name case, the fixed identity; `GET /apps/schema` by `X-P202-App-Token` (200 per platform, 304, 400, 404, 405, no revenue); encoding ownership; the `apps` scope area and the retired `/attribution` app routes; token rotation; `DELETE /users/{id}` purging a user's apps and keys and releasing their postbacks to the next registration; the registration delete cascade; and `202-cronjobs/app-retention.php` (dry run, a malformed window, the prune). Runs the retention job from this checkout, so the checkout's `202-config.php` must name the instance's database. |
| `ios-sdk.sh` | The iOS SDK (PR 8): every goal vector through the running server's `/goals/validate` and `/goals/evaluate`; SKAN encodings naming full goals (a funnel, a sum, the install) and refusing a click window, directly or through `after`, also on a goal edit; the evaluation-only document and `p202 app schema`; the Swift SDK's own live suite against the instance when `P202_SWIFT` names a `swift` binary (reported as not run otherwise); encoding versions (the kept meanings, and the report before, inside and after the 48-day horizon, and after a delete; a deleted and re-registered app keeps decoding under its own encodings); and Setup › Mobile Apps' warning on a rule edit, with its Swift snippet compiled against the SDK. Needs `P202_API_KEY` and, for the Setup step, `P202_PASS`. |
| `upgrade-csrf.sh` | `202-config/upgrade.php`, the one page that takes a POST before there is a login: no token, a wrong token and a session-less replay are each answered 200 and refused with the guard's own sentence, `202_version` untouched, and the form submitted as a browser would — the fields inside it, token included — runs the ladder. Winds `202_version` back to `P202_PRIOR_VERSION` (default `1.9.75`) and leaves it at the code version. Also run by CI's Agent Evals job. |

Each prints `N passed, M failed` and exits non-zero on a failure.
