# CLAUDE.md

## Project
Prosper202 — PHP 8.3 affiliate tracking platform with REST API v3 (mysqli, PSR-4) and Symfony Console CLI.

## Error patterns to avoid

### 1. Unchecked return values after fallible calls
`$stmt->execute()` can return false without throwing. Every execute() must be checked: `if (!$stmt->execute()) { $stmt->close(); throw ...; }`. Same applies to `$db->query()`, `json_encode()`, and any call that signals failure via return value rather than exception. This is especially critical inside transactions — an unchecked failure means partial operations get committed.

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
- **phpstan needs `vendor/bin/` and a `phpstan.neon`** (gitignored), so it
  generally cannot run in these sandboxes — say so rather than implying it
  passed.

## Review discipline
- Review every file individually. Batch scanning causes context overload and misses real bugs.
- Read the file first, then think about what each line does, especially error paths.
- After writing code, re-read it as a skeptic looking for the failure mode, not as the author expecting it to work.
- When fixing a pattern (e.g., unchecked execute), grep the entire codebase for every instance — don't fix one and assume the rest are fine.

## Before committing
- Always perform a full deploy-quality code review of the staged changes before committing. Treat every commit as if it ships to production.
- Walk each changed file individually (per the Review discipline above), tracing error paths and the failure modes in the "Error patterns to avoid" list.
- Confirm the code lints/compiles and that any relevant tests pass. If tests or static analysis can't be run in the environment, say so explicitly rather than implying they passed.
- Only commit once the review is clean; if it surfaces issues, fix them and re-review before committing.
