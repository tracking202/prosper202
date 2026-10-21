# Live passes

What a page answers over HTTP, against a running instance and a real
database. These run **locally, on demand** — like `tests/browser` — with one
exception: `upgrade-csrf.sh` and `cli-licence-gate.sh` also run in CI, in the
Agent Evals job, against the instance that job installs, so the token guard on
the upgrade page and the Go CLI's licence gate are driven over HTTP on every
push — and `upgrade-equals-install.sh`, which needs no instance, runs in its
own workflow.

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
| `android-abuse-limits.sh` | The Android intake's abuse limits (plan §8.3): the registration's six limits (defaults, malformed writes refused by name), click-to-install time recorded and flagged through real clicks and reported (counts, `ctit-flag` grouping and filter, the install list), the per-app install cap (429 with Retry-After, nothing stored, a replay answered, an unreadable cap's 503), the per-install event cap, goals reached too fast paid under `count` and held under `hold`, Setup's Advanced form and Analyze's Fraud signals panel (with `P202_USER`/`P202_PASS`), and the upgrade page's backup warning. Rerunnable within the minute. |
| `android-sdk.sh` | The Android SDK's own engine (`sdk/android-attribution` `LiveServerTest`: file store, `HttpURLConnection`, worker thread, retry rules) against the instance: a web click carrying a signed `cust`, a phone's click through the store link whose referrer the SDK is handed; the install (attributed, install conversion), three events (level 3 paid on top), `setCustomerId` joining the phone's click to the web click's person, a relaunch that sends nothing, an organic install whose customer rode its body (`no_click`), a forged token whose events are dropped; the SDK's stored body replayed (duplicate) and changed (409), a forged customer signature (`unverified`); then Play Integrity under `require` against PR 6's fake Google (`P202_FAKE_GOOGLE_PORT`, default 8251): the SDK's token bound to its body's fingerprint, the install held `pending_integrity` with its event, the worker's valid verdict attributing and paying it and linking its customer, the relaunched SDK delivering the kept event. Needs a JDK, Gradle (`P202_GRADLE`), python3 and openssl; runs `202-cronjobs/app-installs.php` from this checkout. |
| `app-core.sh` | The app registry over the REST API: a postback waiting unclaimed until its app is registered from a store link (iOS and Android), 409 on a second registration, package-name case, the fixed identity; `GET /apps/schema` by `X-P202-App-Token` (200 per platform, 304, 400, 404, 405, no revenue); encoding ownership; the `apps` scope area and the retired `/attribution` app routes; token rotation; `DELETE /users/{id}` purging a user's apps and keys and releasing their postbacks to the next registration; the registration delete cascade; and `202-cronjobs/app-retention.php` (dry run, a malformed window, the prune). Runs the retention job from this checkout, so the checkout's `202-config.php` must name the instance's database. |
| `ios-sdk.sh` | The iOS SDK (PR 8): every goal vector through the running server's `/goals/validate` and `/goals/evaluate`; SKAN encodings naming full goals (a funnel, a sum, the install) and refusing a click window, directly or through `after`, also on a goal edit; the evaluation-only document and `p202 app schema`; the Swift SDK's own live suite against the instance when `P202_SWIFT` names a `swift` binary (reported as not run otherwise); encoding versions (the kept meanings, and the report before, inside and after the 48-day horizon, and after a delete; a deleted and re-registered app keeps decoding under its own encodings); and Setup › Mobile Apps' warning on a rule edit, with its Swift snippet compiled against the SDK. Needs `P202_API_KEY` and, for the Setup step, `P202_PASS`. |
| `upgrade-equals-install.sh` | The release gate's schema check (plan §7.6): installs the real 1.9.55 release from its own commit (`P202_ORIGIN_REF`, needs full history) with its own installer on PHP 7.4 (`P202_ORIGIN_PHP`; 1.9.55 does not parse on PHP 8), upgrades that database through this checkout's upgrade page, installs this checkout fresh into a second database, and compares every table's `SHOW CREATE TABLE` (`schema-diff.php`, normalising only index order, the table comment, partition boundaries and `AUTO_INCREMENT` — the list in `SchemaReconciler`'s docblock) and every seeded row (`CHECKSUM TABLE`, except the per-install account, key, secret and auto_cron rows). Needs no running instance: it serves both trees itself on `P202_UEI_PORT` and `+1`, and DROPS and recreates `P202_DB_UPGRADE` and `P202_DB_FRESH`, which the guard must accept. Runs in CI (`.github/workflows/upgrade-equals-install.yml`). |
| `upgrade-csrf.sh` | `202-config/upgrade.php`, the one page that takes a POST before there is a login: no token, a wrong token and a session-less replay are each answered 200 and refused with the guard's own sentence, `202_version` untouched, and the form submitted as a browser would — the fields inside it, token included — runs the ladder. Winds `202_version` back to `P202_PRIOR_VERSION` (default `1.9.75`) and leaves it at the code version. Also run by CI's Agent Evals job. |
| `chat-pass-reuse.sh` | The chat box's pass through `202-account/ajax/chat-pass.php` itself, against a stand-in portal the script serves on loopback (`CHAT_STUB_PORT`, 8787): the first request asks the portal once; the same request is answered from the session without asking; `refresh=1` asks again and the new pass is the one kept; a portal that gives no pass answers 502 and drops the kept one, so the next request asks again; a `refresh` that is neither 1 nor 0 is refused unasked. The instance must have been started with `CHAT_PASS_URL=http://127.0.0.1:$CHAT_STUB_PORT/api/v3/chat/pass` and hold a customer key and install hash (an `install-instance.sh` instance does). Needs `P202_PASS`; writes only the session. |
| `cli-licence-gate.sh` | The Go CLI's Pro-licence gate over HTTP (`api/v3/index.php`, `CapabilitiesController::cliAccess`): with a verdict cached for the install's ClickServer key, the Go CLI's User-Agent is answered 402 with `category: licence` on "not paid" and 200 on "paid" while the PHP CLI's and curl's are answered 200 either way, `/capabilities` reports the verdict as `cli` and stays open, as does `/system/health`; with only "the last check went unanswered" cached, the Go CLI is allowed, or the stale verdict holds, and nothing is asked; the built CLI (`P202_BIN`) exits 6 with the envelope on stderr and nothing on stdout, then 0; a sub-user created through the API with a key of their own and no ClickServer key is judged by the account owner's key both ways, then deleted; a path that merely starts with `/system` is gated; the shell verdict's own hold-off. Under `P202_LICENCE_STUB=1` (CI, where my.tracking202.com is the Agent Evals stub) every answer the service can give, and three it never does, reaches the gate through a real validation call. Sets `202_users.p202_customer_api_key` for the user `P202_API_KEY` acts as (read from `202_api_keys`; `P202_USER_ID` overrides) and restores it; writes the server's cache through its own classes, so run it as the server's user with its `TMPDIR`. Outside CI no request leaves the machine. |
| `cron-needs-upgrade.sh` | Every job in `202-cronjobs/`, run from another directory as cron runs it, against the instance with `202_version` wound back to `P202_PRIOR_VERSION` (default `1.9.75`): exit status 1, the reason on stderr naming both versions and `202-config/upgrade.php`, nothing on stdout, the attribution outbox untouched and no cron lock left behind; one job over HTTP still answers the 302 to the upgrade page; with the version restored (also on any exit) the attribution worker, the sync worker and the data engine job exit 0. Runs the jobs from this checkout, so its `202-config.php` must name the instance's database. |
| `autocron-switch.sh` | Settings › AutoCron against the cron ping every signed-in page sends: the ping the Settings footer carries, sent as the browser sends it, runs the minute tier and records no cron job run (the tile still says never); turning AutoCron on right after it is not refused as unneeded and the service's answer is said (`P202_AUTOCRON_ANSWER=refused` requires the refusal of a local address, the service's 402, in its own sentence); a scheduler's run over HTTP and on the command line (`P202_CRON_CLI`, default this checkout's `php 202-cronjobs/index.php`) is recorded, after which On is refused as unneeded with the switch unchanged; with AutoCron already on, saving On asks the service again. Deletes `202_cronjob_logs` and the minute tier's `202_cronjobs` rows; puts the account's `auto_cron` back. Needs `P202_PASS`. |
| `scheduled-deletion.sh` | Settings › Click data › "Delete click data from before": a redirector click re-clicked through `rtr.php ?lpr=` and a direct-link click are moved back before a day in 2001 and a third is left at now; the API's dry run counts only the click whose every row is before the day, the page schedules the day (user 1 holds its time and no id), `GET /system/retention` reads it, and the cron job over HTTP deletes exactly that click from every click table, keeps the re-clicked one with both rows and every other click on the install, and deletes nothing on a second run. Refuses an instance that already has clicks from before 2001-02-01 (the deletion is install-wide). Additive apart from that; puts user 1's schedule back. Needs `P202_API_KEY` and `P202_PASS`. |
| `overview-chart.sh` | The Overview chart's writes (`tracking202/ajax/charts.php`): the switch answers the signed-in account's clicks (another account's today change nothing, its own add), today as one day or twenty-four hours ending at 11 pm; a resolution other than hours or days, a figure the chart does not draw and a save with no lines are 422 naming the field, nothing stored; an account the API created, with no chart row, is answered and given one by both writes; a role without campaign data is 403; with `202_dataengine` renamed for one request the switch is a JSON 500 in the Overview's words. Creates two users (soft-deleted after) and rollup rows it removes; renames `202_dataengine` for one request and back. Needs `P202_API_KEY` and `P202_PASS`. |
| `rotator-breakdown.sh` | Overview › Rotator Breakdown against both rotator entry points: real clicks through a redirector tracker (`rtr.php`, a rule with three redirects) and landing-page clicks out through the offer rotator (`record_adv.php`, then `offrtr.php`), told apart by browser, matching a rule, another rule or none; what each entry point recorded (the click row's rotator, rule 0 for a default, the rollup row at once on the URL branches), `GET /clicks`, and the page against `GET /rotators/{id}/stats` rule by rule without running the dataengine job. Additive: it creates its own records and truncates nothing. Needs `P202_API_KEY` and `P202_PASS`. |
| `offer-rotator-routing.sh` | Where the offer rotator (`record_adv.php`, then `offrtr.php`) sends a click that matched a rule, told apart by browser: a redirect weighted 0 beside one weighted 100 takes none of the clicks, an even split reaches both redirects over sixteen clicks, a rule that sends to a landing page sends to that one and not the rotator default's, a rule whose redirects are all weighted 0 sends to its first, and each click records the redirect it was sent to (`202_clicks_rotator.rule_redirect_id`, `202_clicks.rule_id`); the redirector tracker (`rtr.php`) sends the same visitors to the same places. Additive: it creates its own records and truncates nothing. Needs `P202_API_KEY`. |
| `session-token-reseed.sh` | A signed-in session whose anti-CSRF token is set but unusable — an empty string, `false`, an array, each planted in the PHP session file (`P202_SESSION_DIR`, default `/var/lib/php/sessions`) and asserted to have landed — gets a new 32-character token on its next page: the form that posts carries it, the session file holds it, the form submitted as rendered writes its category, and the same form without its token is refused in the page's own sentence. Before `connect.php` seeded such a token again, every form of that session was refused until sign-out. Additive: it writes categories named `RESEED …` and removes them. Needs `P202_PASS`. |
| `signin-not-completed.sh` | A sign-in whose password is right but which a database failure stops: with a trigger refusing an UPDATE of the pass's own two users (asserted to have landed), the right password past the per-user throttle limit is told each time that the sign-in could not be completed, logged as `login_success = 2` and never throttled, as is a legacy MD5 password whose rehash is refused; with the trigger gone it signs in at once; Account › Settings shows those attempts as Not completed, `GET /system/login-log` answers `status: not_completed` (`login_success` false) and `?status=` selects them, as `p202 system login-log --status` does with `P202_BIN`; then ten wrong passwords still lock the user out, the right one included. Creates its own users, key and trigger and removes them, with every log row of theirs, on any exit; touches no other user, so it may run against a shared instance (it does not source `guard.sh`). |

Each prints `N passed, M failed` and exits non-zero on a failure.
