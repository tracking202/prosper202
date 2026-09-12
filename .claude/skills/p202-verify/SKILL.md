---
name: p202-verify
description: >-
  The verification ladder for Prosper202: which checks exist, which ones your
  change actually needs, how to run them when `composer install` has failed,
  and how to report what ran versus what could not. Use this before reporting
  any change as done, complete, working, merge-ready, or ready to commit, and
  whenever you are about to say "tests pass". Also use when a suite errors out
  and you need to know whether that is a regression or an environment gap.
  Consult it even for a one-line change — CLAUDE.md error pattern #10 exists
  because aggregate green suites were cited for a path nothing exercised.
allowed-tools: Bash, Read, Grep, Glob
---

# Prosper202 verification ladder

`CLAUDE.md` states the rule this skill operationalises: "Aggregate green suites
are not coverage of the path you just wrote." Three of the fifteen recorded
error patterns (#9, #10, #12) are variants of a check that appeared to run and
did not. This skill exists so that the environment's shape is established
before any claim is made about it, and so the claim names its own scope.

## Step 1: establish the environment before running anything

`composer install` fails in network-restricted sandboxes, leaving a partial
`vendor/` with no `vendor/bin/`. Suites needing Symfony Console, Slim, a live
DB singleton, or memcache then error out with class-not-found messages. Those
are not regressions, and reporting them as failures wastes a review cycle.

Run the bundled probe first. It classifies the environment and prints the
tiers it can and cannot execute:

```bash
.claude/skills/p202-verify/scripts/verify.sh --probe
```

If it reports a partial vendor, read `references/sandbox-recovery.md` before
concluding a tier is unavailable. Most of them are recoverable in a few
minutes, and the PHP CLI and the click path in particular are worth
recovering because they are the only way to exercise `cli/Commands/*` and
`tracking202/redirect/*` end to end.

## Step 2: pick tiers from the paths you changed

Running everything is slower and no more honest than running the right
subset, because the tier that matters is the one that touches your path.

| Changed path | Tiers that must run |
|---|---|
| any `*.php` | syntax, phpstan, phpcs, unit |
| `api/**` | + integration against a live instance, + agent eval if the surface is agent-facing |
| `cli/Commands/**` | + `bin/p202` exercised end to end against a live instance |
| `go-cli/**` | + `go vet`, `go test`, `golangci-lint`, and the empty-`HOME` run |
| `tracking202/redirect/**` | + a real click through a seeded instance, not a unit test |
| `202-config/PHPStan/Rules/**` | + rule registered in `phpstan.neon.dist`, + clean against the whole tree, + a planted defect in every call shape it claims to cover |
| `202-config/Database/**`, `202-config/migrations/**`, `tests/Schema/**`, `api/v3/**`, any `*.sql` | + `--group integration tests/Schema/` against a scratch database. The table definitions live in `202-config/Database/Tables/*.php` and have no "schema" in their path; the first version of the selector missed them. |
| `sdk/ios-attribution/**` | swift (`swift build && swift test`, mirroring the Swift SDK job); SKIP with the toolchain hint when `swift` is absent |
| `.github/workflows/**` | actionlint, which is CI's workflow gate; a workflow-only change previously selected nothing that could see an invalid action input |
| anything auth, scope, idempotency, or staged-write shaped | all of the above, plus a live end-to-end pass |

An unregistered PHPStan rule never runs. A rule that fires on correct code
gets disabled, which leaves the pattern unguarded and silent. Both are
recorded failures, so treat the rules row as a hard gate rather than a
suggestion.

## Step 3: run the ladder

```bash
# everything the environment supports, tier by tier, continuing past skips
.claude/skills/p202-verify/scripts/verify.sh

# only the tiers implied by your working-tree diff
.claude/skills/p202-verify/scripts/verify.sh --changed

# see which tiers --changed would pick, without running any
.claude/skills/p202-verify/scripts/verify.sh --plan

# after committing, --changed has nothing left to look at and every tier it
# selects from the diff skips. Compare against a ref instead, which covers the
# commits AND anything still uncommitted:
.claude/skills/p202-verify/scripts/verify.sh --since origin/master --changed

# one tier
.claude/skills/p202-verify/scripts/verify.sh --tier phpstan
```

`--changed` reads the working tree, so on a clean tree it can only report
skips — which is a scope report that looks like a run and verified nothing.
Reach for `--since` whenever the change you want checked is already committed.

The script exits non-zero only for real failures. A tier that could not run is
reported as `SKIP` with its reason and does not affect the exit code, because
conflating "did not run" with "passed" is the failure this skill prevents.

A tier can reach that verdict two ways. Most gaps are visible up front and are
caught by `--probe`. Others only appear once the tool starts, so a tier may
also report `SKIP` mid-run: a Go toolchain that cannot link, a `golangci-lint`
built against a different Go, or a `patterns` run with no modified PHP to
examine. A skip always carries its reason, and a vacuous pass is never printed
as `PASS`.

Tiers, in order, with the command each wraps:

1. `syntax` — `php -l` over the tree, `bash -n` over `*.sh`
2. `phpstan` — `vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=512M`. On a partial `vendor/` the documented errors (`cli/` classes extending Symfony classes composer never delivered, in files the change did not touch; the same text in a changed file is the change's, since a misspelled superclass prints it too) are separated from everything else: if they are the only errors the tier is SKIP naming the count, otherwise FAIL as `(N environmental, M other)`, so a tier that would be FAIL on every run in that environment still says what is worth reading.
3. `phpcs` — PSR12 over the PHP files the change touches, as a **ratchet**: a file may not have more PSR12 errors, nor more warnings, than it had at `HEAD`, and a new file must have none of either. `AGENTS.md` asks for `phpcs --standard=PSR12 .`; CI does not run it and the tree is far from clean (`api/v3` alone carries a few hundred findings), so a tier that failed on any finding would fail on every touch of a legacy file and be switched off. Pre-existing findings are printed, not counted. A renamed file is ratcheted against its original blob (git's rename detection for a staged move, an exact content match for an unstaged plain move); an unstaged move with edits has no findable baseline, so stage it first. Whether phpcs actually analysed a file is read from its report line, not its exit code: the documented exit bitmask does not match what phpcs 3.13 returns, in both directions, and a run with no report is SKIP, never zero findings.
4. `unit` — `vendor/bin/phpunit --configuration phpunit.ci.xml --exclude-group integration --no-coverage`, which is exactly CI's invocation. Not the strict `phpunit.xml`: that one promotes deprecations to errors, and on a newer local PHP than CI's it reported 16 failures CI never sees. A failure is a failure: if the local PHP minor version differs from the one `php-unit.yml` pins, the FAIL carries a note naming both so the reader can judge whether the failures are deprecation notices from the interpreter, but the verdict is never downgraded to SKIP (the first version did that, and would have hidden a real `$this->fail()` behind "environmental"). CI's false-green guard is mirrored too: an exit-0 run that executed fewer than 600 tests is FAIL, because a collection-time `die()`/`exit(0)` aborts PHPUnit before any test runs and can still exit 0.
5. `go` — first `go list -m all`, so an empty module cache with no network is SKIP rather than a FAIL blamed on code that never compiled; then `gofmt -l .`, which is CI's first Go gate and fails the tier on any file it would reformat; then `cd go-cli && go vet ./... && go test ./...`, then `HOME=$(mktemp -d) go test ./cmd/...`. When the local Go minor differs from the one `go-cli.yml` pins, both Go tiers run CI's toolchain instead, fetched on demand through `GOTOOLCHAIN` (the latest patch of CI's minor; about 15 seconds the first time), so a PASS means what CI's PASS means. The `go 1.22` directive gates language features, not the standard library, so a newer local Go would otherwise accept imports CI cannot compile. If CI's toolchain cannot be resolved or fetched, the tier is SKIP, never a PASS with a footnote. Some hosts can vet under CI's toolchain but cannot run its test binaries (on the macOS this was written on, Go 1.22 test binaries that link `net` abort in dyld with `missing LC_UUID`); the tier then reports SKIP saying that vet passed under CI's toolchain and test could not run, decided by a probe that builds and runs a net-importing test in a temp module, not by the output text. When the output mentions the C toolchain, the tier compiles a known-good cgo program with the same `go` to decide whether the host is broken (SKIP) or the change is (FAIL); the text alone cannot tell a machine whose Xcode tools mismatch its Go from a change that links a library that is not there.
6. `golangci` — `cd go-cli && golangci-lint run ./...`
7. `schema` — `phpunit --configuration phpunit.ci.xml --group integration tests/Schema/` against a scratch database. PHPUnit 9 exits 0 when the tests skip themselves, which the schema test does when it cannot reach the database; a run that reports `Skipped: N` or `No tests executed` is SKIP, never PASS.
8. `patterns` — `scripts/check-code-patterns.sh`, the existing Stop hook
9. `actionlint` — `actionlint` over `.github/workflows/`, selected when a workflow changes
10. `swift` — `cd sdk/ios-attribution && swift build && swift test`, selected when that directory changes

The `--memory-limit` on tier 2 is not decoration. CI installs PHP through
`setup-php`, which leaves `memory_limit` uncapped; a stock local `php.ini`
caps it at 128M and PHPStan dies parsing the intl stubs, reporting FAIL for a
reason that has nothing to do with the change. `scripts/check-code-patterns.sh`
passes the same 512M for the same reason.

Tiers 11 and 12 are not scripted because they need a live instance and a
decision about what to exercise. Do them by hand:

11. **Live end-to-end.** Stand up an instance with
   `tests/fixtures/agent-eval/ci/install-instance.sh`, seed it with
   `tests/fixtures/agent-eval/seed.sh`, then drive the actual path a user
   would take. Reports stay empty until the dataengine cron runs; the seeder
   triggers `202-cronjobs/dej.php` itself. If an instance is already up in
   this session, there is no excuse to skip this.
12. **Agent eval.** If the change is agent-facing, add a case under
   `tests/fixtures/agent-eval/cases/` and run it. Grading is on final state,
   not on transcript wording.

The empty-`HOME` Go run in tier 5 is not redundant. A `--scope` check once
passed locally only because the sandbox had a URL configured while CI had
none, so the config error won and the flag was never examined.

## Step 4: report the scope, not the total

Never write "tests pass". Write which suites, in which environment, and what
could not run. Use this shape:

```
Verified:
  syntax    PASS
  phpstan   PASS   (0 new errors against baseline)
  unit      PASS   tests/Api tests/User tests/Crud
  go        PASS   go vet, go test, empty-HOME cmd run
  live      PASS   created tracker via bin/p202, click recorded, report populated after dej.php

Not run:
  schema    SKIP   no scratch database available in this environment
  golangci  SKIP   binary not installed

CI status: not yet observed. <or: run #123 green / in_progress>
```

Three rules that keep this honest:

- A workflow still `in_progress` has not passed. Read the run before citing it.
- If a tier failed for an environmental reason, say so and name the reason.
  Do not silently drop it from the list.
- A surprising result means the check is suspect before the code is. Eight
  workers producing three lines was interleaved stdout, not a split brain.

## Step 5: close the loop if you found a bug

A mistake is finished when it cannot recur silently, not when it is fixed. In
this order:

1. **A machine check**, if the pattern is mechanically detectable. Either a
   PHPStan rule in `202-config/PHPStan/Rules/` registered in
   `phpstan.neon.dist`, or a structural test under `tests/` in the shape of
   `UncheckedExecuteTest`, `ScopeCoverageTest`, or `StaticSqlSchemaTest`.
2. **An entry in "Error patterns to avoid"** in `CLAUDE.md` when the pattern
   needs judgement a checker cannot apply. Write the failure mode, not the
   fix.
3. **Both**, when the rule catches the common case but not all of it.

A regression test is not a substitute for either. It proves this instance is
gone and does nothing for the next one elsewhere. Grep for every analogous
site before deciding a check can cover them all.

## Reference

`references/sandbox-recovery.md` — recovering PHPUnit, PHPStan, the PHP CLI,
and the click path on a partial `vendor/`. Read it when the probe reports a
partial vendor and you need a tier it says is unavailable.
