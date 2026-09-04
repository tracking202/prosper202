# CLAUDE.md

## Project
Prosper202 — PHP 8.3 affiliate tracking platform with REST API v3 (mysqli, PSR-4) and Symfony Console CLI.

## Error patterns to avoid

### 1. Unchecked return values after fallible calls
`$stmt->execute()` can return false without throwing. Every execute() must be checked: `if (!$stmt->execute()) { $stmt->close(); throw ...; }`. Same applies to `$db->query()`, `json_encode()`, and any call that signals failure via return value rather than exception. This is especially critical inside transactions — an unchecked failure means partial operations get committed.

The list is not just `execute()`, and writing this rule down did not stop the
same shape shipping three more times. The ones that actually bit:
`$stmt->get_result()` (false reads as an **empty result set**, so a batch loop
exits and the job reports success), `$stmt->store_result()` (false leaves
`num_rows` at 0, which reads as "not found"), `$conn->prepare()` (false skips
whatever the statement guarded, in silence), and Go's `json.Marshal` assigned
to `_` (renders nothing, exits 0). When a false return is
*indistinguishable from a legitimate empty answer*, the failure is silent by
construction — that is the tell, not the function name.

### 2. Dead code referencing nonexistent schema
Never reference DB columns, tables, or config keys without verifying they exist in the actual schema. Code that calls `prepare()` with nonexistent columns fails silently or crashes depending on the error handling path. When adding features that touch the DB, confirm the schema first.

### 3. Ordering dependencies — using values before they're initialized
Config constants, DB connections, and other resources must be initialized before use. Example: checking `defined('SOME_CONSTANT')` before the file that defines it is loaded guarantees false. Trace the initialization order when adding code that depends on global state.

### 4. Silent data loss on malformed input
Never use `json_decode(...) ?? []` or similar fallbacks that silently discard bad input. Malformed JSON, invalid formats, and parse failures must produce explicit errors. The user needs to know their input was rejected, not silently ignored.

### 5. Inconsistent security patterns across similar operations
If create has secure password input, update must too. If one delete command has confirmation, all must. When implementing a security measure, grep for every analogous code path and apply the same pattern. Spot-checking misses these — review exhaustively.

### 6. Empty response rendering for void operations
DELETE/204 responses return empty arrays. Rendering an empty array produces no output. Void operations (delete, remove, revoke) need explicit success messages, not render calls.

### 7. bind_param type string mismatches
When building `bind_param($types, ...)` with many parameters, count types against values one-by-one. Integer timestamps bound as 's' work due to MySQL coercion but indicate sloppy code and can cause subtle issues with strict modes.

### 8. Assumed closure, reference, and capture semantics
A by-reference `use (&$x)` on an outer closure does **not** propagate into
arrow functions (`fn() => $x`) defined inside it — arrow functions capture
by value at definition time. This shipped a staged-write dispatcher that
swapped `$payload` by reference while every arrow-function route handler
kept the value captured at route registration, so applying a staged change
executed the wrong body (and, when the applier sent one, wrote it while the
audit record still showed the reviewed payload). Any claim about capture,
reference, or evaluation-order semantics must be **executed** before it is
relied on — a ten-line `php -r` scratch script settles it in under a minute.
Never assert the mechanism in a comment on the strength of reading a
signature one level up: the comment then reads as verified fact to every
later reviewer, including yourself. This specific shape is now enforced by
`ForbidArrowFnByRefCaptureRule`; when adding a rule for a pattern on this
list, put it in `202-config/PHPStan/Rules/` and register it in
`phpstan.neon.dist` — a rule that is not registered never runs.

### 9. Tests that mock the seam under test
When the new code *is* the wiring — a dispatcher, an adapter, a callback
that re-enters the router — a unit test that injects a fake for that wiring
proves only the surrounding bookkeeping. The staged-changes tests passed a
stub dispatch closure and asserted the controller handed it the right
method, path, and body; it always did. The defect was that the real closure
could not deliver that body. If the thing you wrote is the seam, exercise
the real path at least once: an integration test, or a live request against
a running instance.

### 10. Calling a capability done without an end-to-end pass
Aggregate green suites are not coverage of the path you just wrote — the
PHP, Go, and eval suites were all green while nothing exercised staged
apply. Before reporting a user-facing capability as complete, run it end to
end the way a user will (see the live-instance recipe under Development
environment notes; if an instance is already up in the session, there is no
excuse to skip this), and add a case under
`tests/fixtures/agent-eval/cases/` when the capability is agent-facing.
When reporting status, say which paths were actually exercised rather than
citing suite totals.

### 11. Fail-open defaults on a security value
`if ($scopes === []) { $scopes[] = '*'; }` — one fallback served two cases: a
key with *no* scope (legitimately full access) and a key whose scope column
was **unreadable** (`[]`, `[null]`, JSON truncated by a partial write). The
second silently removed the key's attenuation entirely. A value that cannot
be parsed must never resolve to the most-permissive interpretation; resolve
it to something that satisfies nothing, and name it in the error so the
corrupt row is findable. Whenever a default stands in for a missing value,
ask separately what happens to a *malformed* one.

### 12. A guard is only as good as the layer that delivers the input
The server rejected an explicitly empty API-key scope. It never fired, because
the CLI dropped the field before building the request, so the server saw
"omitted" and applied its documented default of full access. Validation added
at one layer is dead code if the layer above discards or rewrites the input.
When adding a check, trace an actual bad value from the outermost entry point
and confirm it *reaches* the check — this is #5 across layers rather than
across sibling call sites.

### 13. Retry seams must know whether the write landed
An exception says nothing about whether the database changed. `Controller::create()`
inserts, then runs `afterCreate()`, `get()` and `recordChange()` with the row
already committed, so a throw from any of those looked identical to a
validation failure — and both retry seams (idempotency reservations, staged
apply) offered a retry that duplicated the record. Wrapping creates in a
transaction was *not* the fix: `bulkUpsert()` already wraps `create()` in one
and mysqli's `begin_transaction()` inside an active transaction implicitly
commits the outer. The code that knows says so instead: handlers wrap their
post-write steps and rethrow `WriteCommittedException`, and the seams refuse
the retry. Any code that decides "safe to run this again" needs a fact, not an
inference from an exception type.

### 14. Safety flags must survive process and reset boundaries
`--staged` promises a write becomes a proposal. The interactive shell resets
the whole flag tree between commands and re-applied only the flags someone
remembered to list, and `p202 exec` runs each profile in a child process that
inherits nothing — so `p202 shell --staged -c 'campaign delete 42 --force'`
performed the delete. A flag whose whole purpose is to withhold an action must
be re-checked at every boundary that rebuilds state; grep for the reset and
the subprocess spawn, not just the flag definition.

## Go CLI errors must be agent-actionable (`go-cli/`)

The CLI is built for AI agents as much as humans. An agent reads a failure
once and must know what to do next without guessing, so every error path in
`go-cli/cmd/` follows this contract (plumbing lives in `cmd/cli_errors.go`,
`cmd/root.go`, and `internal/api/client.go`):

1. **Categorize input errors.** Bad flags, missing values, unsupported
   names: return `validationError(...)`, never a bare `fmt.Errorf`. The
   category prints as `Error [validation]:` and sets exit code 1. API
   failures already carry `auth`/`network`/`server`/`validation` from
   `api.APIError`/`api.RequestError`.
2. **Wrap with `%w`, never `%v`.** Exit codes and hints are resolved with
   `errors.As` through the wrap chain; `fmt.Errorf("fetching: %v", err)`
   would turn a 401 (exit 2) into a generic exit 1.
3. **Attach a next step whenever the message alone leaves a choice.**
   `validationError(...).WithHint("...")` for new errors, `withHint(err,
   "...")` for wrapped ones. A good hint names the command that produces
   the right value (`p202 tracker list`), the flag to change, or the
   ordering to follow. Lists of valid values belong in the message itself.
   Errors without a specific hint fall back to `Run <command> --help`.
4. **Add class-wide hints in `api.HintFor`,** not per call site, when a
   whole failure class has one remedy (401 key check, 404 use `list`, 409
   update instead of create, 429 back off, 5xx `p202 system health`).
5. **Never print errors yourself.** Return them; `Execute` prints the
   `Error [category]: ... / Hint: ...` lines, or under `--json`/`--ndjson`
   a single `{"error": {category, message, hint, exit_code, command,
   http_status, field_errors}}` envelope on stderr. Stdout stays empty on
   failure so scripts never mistake an error for data.
6. **Test the contract, not just the message.** For a new error path
   assert `exitCodeForError(err)` and, when a hint was added,
   `hintFor(err)` (see `cmd/cli_errors_test.go` and the forecast hint
   tests for the pattern).

When adding a command, run it once with a wrong flag and once against a
dead URL under `--json` and read the envelopes as an agent would: if either
leaves you unsure what to do next, the error needs a hint. The user-facing
contract is documented in `documentation/cli/10-go-cli.md` under "Errors";
keep it in sync.

## Development environment notes (sandboxed/CI sessions)

Findings from working on this repo in network-restricted agent sandboxes.
Check here before burning time on tooling failures.

- **`composer install` can fail with "Could not authenticate against
  github.com".** Proxied sandboxes often serve anonymous *git* reads of
  public GitHub repos but not Composer's authenticated dist downloads
  (codeload/API). Retrying, `--prefer-source`, and global
  `preferred-install` overrides do not help when the phar downloads
  themselves are blocked. Result: `vendor/` ends up partially populated
  (no `vendor/bin/`, empty `vendor/phpunit/...`).
- **Workaround that works:** run `composer dump-autoload --dev` (the PSR-4
  maps in `composer.json` cover `Api\V3\`, `Prosper202\`, `Tests\`, etc.,
  and most runtime deps usually did land in `vendor/`), then run tests with
  the standalone PHPUnit 9 phar: download
  `https://phar.phpunit.de/phpunit-9.phar` (phar.phpunit.de is reachable)
  and run `php phpunit-9.phar --bootstrap vendor/autoload.php tests/...`.
  The phar provides PHPUnit's own classes; the project autoloader provides
  everything else.
- **Expect ~76 environmental test errors on a partial vendor/:** suites
  needing Symfony Console (`tests/Cli/*`), Slim
  (`tests/Attribution/Api/*`), a live DB singleton, or memcache error out
  with "class not found"-style messages. They are not regressions — run the
  suites relevant to your change (e.g. `tests/Api tests/User tests/Crud
  tests/Rotator tests/Conversion tests/Report tests/Upgrade tests/Click
  tests/Install` all pass without those deps) and say explicitly which
  suites could not run.
- **`docs/` matches a gitignore pattern** even though `docs/cli-agent.md`,
  `docs/cli.md`, and `docs/openapi.yaml` are tracked. `git add docs/<file>`
  on the tracked files works but prints an "ignored paths" warning (exit
  1); use `git add -f` or ignore the warning after confirming the files
  staged with `git status`.
- **Go commands must run from `go-cli/`** (`cd go-cli && go vet ./... && go
  test ./...`); the repo root is not a Go module. The forecast package's
  acceptance suites take ~40s; `-short` skips them.
- **Click endpoints 500 on a partial vendor/**: `ua-parser/uap-php` is a
  runtime dependency of the tracking path (`tracking202/redirect/*.php` →
  `PLATFORMS::parseUserAgentInfo`), and the failed composer install leaves
  its directory empty. Fix locally: `git clone --depth 1 --branch v3.10.0
  https://github.com/ua-parser/uap-php <scratch>` then copy into
  `vendor/ua-parser/uap-php/`, and add the `UAParser\` PSR-4 mapping to
  BOTH `vendor/composer/autoload_psr4.php` and `autoload_static.php`
  (`dump-autoload` won't pick it up — the package is missing from
  installed.json, and the static map takes precedence). The tag ships
  `resources/regexes.php`, so full composer installs (CI) need none of
  this.
- **A live local instance is achievable end to end**: install
  `mariadb-server` via apt, start `mariadbd --user=mysql` manually, create
  a DB/user, then run `tests/fixtures/agent-eval/ci/install-instance.sh`
  (headless web installer; prints the REST API key) and seed with
  `tests/fixtures/agent-eval/seed.sh`. Reports stay empty until the
  dataengine cron runs — the seeder triggers `202-cronjobs/dej.php` itself.
- **phpstan is now configured and runs in CI** (`phpstan.neon.dist` +
  `phpstan-baseline.neon`, job `phpstan` in `.github/workflows/php-lint.yml`).
  In a sandbox where `composer install` failed there is no `vendor/bin/`, but
  the official phar works: download
  `https://github.com/phpstan/phpstan/releases/download/<version>/phpstan.phar`
  and run `php phpstan.phar analyse -c phpstan.neon.dist --no-progress`.
  Expect ~6 `class.notFound` errors for `cli/` on a partial vendor — PHPStan
  discovers symbols through composer's package metadata, so a package cloned
  into `vendor/` by hand is invisible to it even after patching the
  autoloader. Add `scanDirectories: [vendor/<pkg>]` in a scratch config that
  `includes:` the dist file to confirm a clean run; do not commit that.
- **`tests/Schema/StaticSqlSchemaTest.php` checks SQL against the schema** by
  preparing every statically-known v3 statement on a real server — MySQL is
  the only thing that knows whether a column exists, so no SQL parser is
  involved. It is `@group integration`, so it is excluded from a default
  `phpunit` run; invoke it with `--group integration` and a database it may
  install a fresh schema into (it drops and recreates tables), e.g.
  `P202_TEST_DB_HOST=127.0.0.1 P202_TEST_DB_NAME=p202_sqlcheck ... --group
  integration tests/Schema/`. Point it at a scratch database, never the one
  a live instance is using.
- **`go test`, `go vet` and golangci-lint run in CI** via
  `.github/workflows/go-cli.yml`; `go-cli/.golangci.yml` scopes the linters to
  dropped errors rather than style. Run `golangci-lint run ./...` from
  `go-cli/` before pushing.

## Verification hygiene

Most of the wrong conclusions in this repo have come not from bad code but
from checks that did not check what they appeared to. Every one of these was
caught only because the *result* was surprising enough to chase.

- **Assert that a planted defect actually landed.** The standard way to prove a
  test is not vacuous is to revert the fix and watch it fail. A `str.replace`
  that silently matched nothing made a good test look worthless — the revert
  never happened and the test passed against the code it was supposed to
  fail. Print a marker (`assert old in s`, then re-grep the file) before
  believing the run.
- **Assert that a probe perturbed the target.** To force a post-commit failure
  I created a directory where a state file goes — in the wrong one of three
  `/tmp/p202-api-v3-state-*` directories, picked with `head -1`. The request
  succeeded and it read as "not reproducible". Confirm the thing you broke is
  the thing under test (check mtime, or that the process reads that path).
- **Concurrent processes must not share a stdout you intend to count.** Eight
  parallel workers printing one line each produced concatenated and blank
  lines, which read as three distinct values and "split brain". Redirect each
  to its own file before counting; interleaving is not data.
- **A narrow race needs its window widened, not more attempts.** Launching
  more workers never hit the adoption-rename race. Inserting a deliberate
  sleep between the guard and the rename reproduced it on the first try, and
  demonstrated the fix. Scratch-only: never commit the sleep.
- **A new lint rule is not done when it fires on the bug you wrote it for.**
  Run it against the whole clean tree first (a rule with false positives is
  worse than no rule — one draft produced 194), then plant a defect in *every*
  shape and call form it claims to cover. Extending BindParamArityRule to the
  `bind()` wrapper needed four: variadic instance, array-taking, static
  namespaced, static global. The first "it works" run exercised one of them.
  Derive shapes from the callee's own signature rather than a hardcoded class
  list — the codebase had eleven `bind()` methods in two shapes.
- **Local green is not CI green when the environment carries ambient state.**
  A `--scope` check placed after `api.NewFromConfig()` passed here only
  because this sandbox has a URL configured; CI has none, so the config error
  won and the flag was never examined. Run CLI tests with an empty `HOME`
  (`HOME=$(mktemp -d) go test ./cmd/...`) before pushing anything that touches
  a command which builds a client. Flag validation belongs *before* the
  client is built anyway.

## Review discipline
- Review every file individually. Batch scanning causes context overload and misses real bugs.
- Read the file first, then think about what each line does, especially error paths.
- After writing code, re-read it as a skeptic looking for the failure mode, not as the author expecting it to work.
- When fixing a pattern (e.g., unchecked execute), grep the entire codebase for every instance — don't fix one and assume the rest are fine.
- Per-file reading cannot catch a defect that lives in the *relationship* between two files: a handler and its dispatcher can each read correctly while the runtime binding between them is wrong. For cross-file mechanisms, execute the path instead of reading it.
- Never report work as complete or merge-ready on the strength of tests that don't exercise the new path. State what was actually run and what could not be.

## Before committing
- Always perform a full deploy-quality code review of the staged changes before committing. Treat every commit as if it ships to production.
- Walk each changed file individually (per the Review discipline above), tracing error paths and the failure modes in the "Error patterns to avoid" list.
- Confirm the code lints/compiles and that any relevant tests pass. If tests or static analysis can't be run in the environment, say so explicitly rather than implying they passed.
- Only commit once the review is clean; if it surfaces issues, fix them and re-review before committing.
