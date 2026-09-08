#!/usr/bin/env bash
#
# Regression tests for scripts/check-code-patterns.sh.
#
# The hook selected its work with `git diff --name-only HEAD`, which lists
# tracked modifications only. A brand-new .php file was therefore invisible:
# the hook exited 0 having examined nothing and reported clean. That is a
# check that appeared to run and did not (CLAUDE.md error pattern #10), so it
# gets a test rather than only a fix.
#
# Runs the real script against a throwaway git repository. Part 2 (PHPStan)
# self-skips there because ./vendor/bin/phpstan is absent, so the behavioural
# cases below cover file selection and the diff-versus-whole-file decision.
# The PHPStan invocation is covered by static assertions at the end.
#
# Usage: scripts/tests/check-code-patterns.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT="$HERE/../check-code-patterns.sh"

if [ ! -f "$SCRIPT" ]; then
    echo "cannot find check-code-patterns.sh at $SCRIPT" >&2
    exit 1
fi

REPO=$(mktemp -d)
trap 'rm -rf "$REPO"' EXIT

pass=0
fail=0

cd "$REPO" || exit 1
git init -q .
git config user.email test@example.com
git config user.name test

# A committed file whose legacy body already violates pattern 1. Nothing may
# report it unless a *changed* line reintroduces the pattern.
mkdir -p src tests
printf '<?php\nfunction legacy($stmt) {\n    $stmt->execute();\n}\n' > src/legacy.php
printf '<?php\n// placeholder\n' > src/clean.php
git add -A
git commit -qm "initial"

cp "$SCRIPT" ./check.sh
chmod +x ./check.sh

# Runs the hook and reports both its exit status and whether it named the
# pattern. Checking only the exit status would pass on an unrelated violation.
expect() {
    local name="$1" want_exit="$2" want_caught="$3"
    local got_exit got_caught

    ./check.sh >/dev/null 2>"$REPO/stderr.txt"
    got_exit=$?

    if grep -qF 'Direct $stmt->execute()' "$REPO/stderr.txt"; then
        got_caught=yes
    else
        got_caught=no
    fi

    if [ "$got_exit" = "$want_exit" ] && [ "$got_caught" = "$want_caught" ]; then
        printf '  ok    %-46s exit=%s caught=%s\n' "$name" "$got_exit" "$got_caught"
        pass=$((pass + 1))
    else
        printf '  FAIL  %-46s exit=%s caught=%s (wanted exit=%s caught=%s)\n' \
            "$name" "$got_exit" "$got_caught" "$want_exit" "$want_caught"
        fail=$((fail + 1))
    fi
}

echo "check-code-patterns.sh"

expect "clean tree" 0 no

# The regression. Before the fix this returned exit 0 with nothing caught.
printf '<?php\nfunction fresh($stmt) {\n    $stmt->execute();\n}\n' > src/brand_new.php
expect "untracked new file WITH violation" 2 yes
rm -f src/brand_new.php

printf '<?php\nfunction ok() {\n    return 1;\n}\n' > src/brand_new_ok.php
expect "untracked new file, clean" 0 no
rm -f src/brand_new_ok.php

# tests/ is exempt from this pattern; whole-file treatment must not bypass that.
printf '<?php\nfunction t($stmt) {\n    $stmt->execute();\n}\n' > tests/NewTest.php
expect "untracked new file under tests/ (exempt)" 0 no
rm -f tests/NewTest.php

printf '<?php\nfunction c($stmt) {\n    $stmt->execute();\n}\n' > src/clean.php
expect "tracked file, violation in ADDED line" 2 yes
git checkout -q -- src/clean.php

# The reason the diff scoping exists: legacy lines must stay invisible.
printf '\n// harmless trailing comment\n' >> src/legacy.php
expect "tracked file, violation only in LEGACY lines" 0 no
git checkout -q -- src/legacy.php

printf '<?php\nfunction s($stmt) {\n    $stmt->execute();\n}\n' > src/staged.php
git add src/staged.php
expect "staged-but-uncommitted new file" 2 yes
git reset -q
rm -f src/staged.php

# ── Static assertions on the PHPStan invocation ──
#
# PHPStan under --error-format=raw prints bare "path:line:message" lines and
# never the "[ERROR]" marker the default table renderer uses. The hook used to
# grep for that literal, so the whole PHPStan gate passed regardless of what
# was found. Guard both halves of that fix.
assert_script() {
    local name="$1" pattern="$2" want="$3" got=no
    if grep -qE -- "$pattern" "$SCRIPT"; then got=yes; fi
    if [ "$got" = "$want" ]; then
        printf '  ok    %-46s\n' "$name"
        pass=$((pass + 1))
    else
        printf '  FAIL  %-46s (found=%s wanted=%s)\n' "$name" "$got" "$want"
        fail=$((fail + 1))
    fi
}

assert_script "does not grep for the [ERROR] marker" 'grep -qF .\[ERROR\]' no
assert_script "uses PHPStan exit status" 'phpstan_status' yes
assert_script "pins the committed config (baseline)" '\-c phpstan\.neon\.dist' yes
assert_script "selects untracked files too" 'ls-files --others --exclude-standard' yes

# ── The hook driven through verify.sh's patterns tier ──
#
# verify.sh guards the tier with its own file selection so that "nothing to
# examine" reports SKIP rather than a vacuous PASS. That selection has to
# match the hook's, or the ladder skips a tier the hook would have run. It
# did, once: the guard listed tracked changes only, two commits after the
# hook was taught to see untracked files.
VERIFY="$HERE/../../.claude/skills/p202-verify/scripts/verify.sh"
if [ -f "$VERIFY" ]; then
    mkdir -p scripts
    cp "$SCRIPT" scripts/check-code-patterns.sh
    chmod +x scripts/check-code-patterns.sh
    cp "$VERIFY" ./verify.sh
    chmod +x ./verify.sh

    expect_tier() { # name want_verdict
        local name="$1" want="$2" got
        got=$(./verify.sh --tier patterns 2>/dev/null | awk '/^  patterns / {print $2}')
        if [ "$got" = "$want" ]; then
            printf '  ok    %-46s patterns=%s\n' "$name" "$got"
            pass=$((pass + 1))
        else
            printf '  FAIL  %-46s patterns=%s (wanted %s)\n' "$name" "$got" "$want"
            fail=$((fail + 1))
        fi
    }

    expect_tier "ladder: clean tree skips with a reason" SKIP

    printf '<?php\nfunction fresh($stmt) {\n    $stmt->execute();\n}\n' > src/brand_new.php
    expect_tier "ladder: untracked violation must FAIL, not SKIP" FAIL
    rm -f src/brand_new.php

    printf '<?php\nfunction ok() {\n    return 1;\n}\n' > src/brand_new_ok.php
    expect_tier "ladder: untracked clean file must PASS, not SKIP" PASS
    rm -f src/brand_new_ok.php

    # ── --plan: which tiers a change selects, without running any ──
    expect_plan() { # name want_tier present(yes|no)
        local name="$1" tier="$2" want="$3" got=no
        if ./verify.sh --plan 2>/dev/null | grep -qx "$tier"; then got=yes; fi
        if [ "$got" = "$want" ]; then
            printf '  ok    %-46s %s=%s\n' "$name" "$tier" "$got"; pass=$((pass + 1))
        else
            printf '  FAIL  %-46s %s=%s (wanted %s)\n' "$name" "$tier" "$got" "$want"; fail=$((fail + 1))
        fi
    }
    mkdir -p 202-config/Database/Tables 202-config/migrations
    printf '<?php\n$sql = "CREATE TABLE x (id INT)";\n' > 202-config/Database/Tables/XTables.php
    expect_plan "plan: table definitions select schema" schema yes
    expect_plan "plan: a php change selects phpcs" phpcs yes
    rm -f 202-config/Database/Tables/XTables.php
    printf 'ALTER TABLE x ADD y INT;\n' > 202-config/migrations/0001.sql
    expect_plan "plan: a .sql file selects schema" schema yes
    rm -f 202-config/migrations/0001.sql
    printf '<?php\n// unrelated\n' > src/other.php
    expect_plan "plan: ordinary php does not select schema" schema no
    rm -f src/other.php
    rm -rf 202-config

    # ── unit-tier interpreter classifier, against a workflow file we write ──
    eval "$(sed -n '/^ci_php_version() {/,/^}/p; /^local_php_version() {/,/^}/p; /^interpreter_mismatch_reason() {/,/^}/p' ./verify.sh)"
    # Read by the sourced ci_php_version, which shellcheck cannot see.
    # shellcheck disable=SC2034
    ROOT="$REPO"
    mkdir -p .github/workflows
    local_v=$(local_php_version)
    printf "      php-version: '%s'\n" "$local_v" > .github/workflows/php-unit.yml
    if [ -z "$(interpreter_mismatch_reason)" ]; then
        printf '  ok    %-46s\n' "unit: CI on the same PHP gives no mismatch"; pass=$((pass + 1))
    else
        printf '  FAIL  %-46s\n' "unit: CI on the same PHP gives no mismatch"; fail=$((fail + 1))
    fi
    printf "      php-version: '7.4'\n" > .github/workflows/php-unit.yml
    if interpreter_mismatch_reason | grep -q "CI pins 7.4"; then
        printf '  ok    %-46s\n' "unit: CI on another PHP names both versions"; pass=$((pass + 1))
    else
        printf '  FAIL  %-46s\n' "unit: CI on another PHP names both versions"; fail=$((fail + 1))
    fi
    rm -rf .github

    # ── schema-tier vacuous-pass detector, against real PHPUnit 9 output shapes ──
    eval "$(sed -n '/^phpunit_ran_nothing() {/,/^}/p' ./verify.sh)"
    check_nothing() { # name text want(yes|no)
        local got=no; if phpunit_ran_nothing "$2"; then got=yes; fi
        if [ "$got" = "$3" ]; then printf '  ok    %-46s\n' "$1"; pass=$((pass + 1)); else printf '  FAIL  %-46s (got %s)\n' "$1" "$got"; fail=$((fail + 1)); fi
    }
    check_nothing "schema: skipped-self counts as ran nothing" "OK, but incomplete, skipped, or risky tests!
Tests: 2, Assertions: 1, Skipped: 1." yes
    check_nothing "schema: no tests executed counts" "No tests executed!" yes
    check_nothing "schema: a real green run does not" "OK (2 tests, 40 assertions)" no
    check_nothing "schema: a real red run does not" "FAILURES!
Tests: 2, Assertions: 40, Failures: 1." no

    # ── phpcs ratchet, behaviourally, using the repo's own vendor/ ──
    eval "$(sed -n '/^phpcs_summary_errors() {/,/^}/p' ./verify.sh)"
    if [ "$(printf 'A TOTAL OF 23 ERRORS AND 12 WARNINGS' | phpcs_summary_errors)" = 23 ] \
       && [ "$(printf '' | phpcs_summary_errors)" = 0 ] \
       && [ "$(printf 'A TOTAL OF 1 ERROR AND 0 WARNINGS' | phpcs_summary_errors)" = 1 ]; then
        printf '  ok    %-46s\n' "phpcs: summary parsing"; pass=$((pass + 1))
    else
        printf '  FAIL  %-46s\n' "phpcs: summary parsing"; fail=$((fail + 1))
    fi
    REAL_VENDOR="$HERE/../../vendor"
    if [ -x "$REAL_VENDOR/bin/phpcs" ]; then
        ln -s "$(cd "$REAL_VENDOR" && pwd)" vendor
        expect_phpcs() { # name want_verdict
            local got
            got=$(./verify.sh --tier phpcs 2>/dev/null | awk '/^  phpcs / {print $2}')
            if [ "$got" = "$2" ]; then printf '  ok    %-46s phpcs=%s\n' "$1" "$got"; pass=$((pass + 1)); else printf '  FAIL  %-46s phpcs=%s (wanted %s)\n' "$1" "$got" "$2"; fail=$((fail + 1)); fi
        }
        # A committed legacy file that already violates PSR12 (brace placement).
        printf '<?php\nclass Legacy {\n    public function a() { return 1; }\n}\n' > src/legacy_style.php
        git add src/legacy_style.php && git commit -qm "legacy style"
        expect_phpcs "ratchet: nothing changed skips" SKIP
        printf '\n// a comment does not add a violation\n' >> src/legacy_style.php
        expect_phpcs "ratchet: touching a legacy file without worsening it PASSES" PASS
        printf 'function worse() { return 2; }\n' >> src/legacy_style.php
        expect_phpcs "ratchet: adding a violation to a legacy file FAILS" FAIL
        git checkout -q -- src/legacy_style.php
        printf '<?php\n\nfunction fine(): int\n{\n    return 1;\n}\n' > src/new_clean.php
        expect_phpcs "ratchet: a clean new file PASSES" PASS
        rm -f src/new_clean.php
        printf '<?php\nfunction bad() { return 1; }\n' > src/new_bad.php
        expect_phpcs "ratchet: a new file with any violation FAILS" FAIL
        rm -f src/new_bad.php vendor
    else
        printf '  skip  phpcs ratchet cases: no vendor/bin/phpcs at %s\n' "$REAL_VENDOR"
    fi

    # ── unit tier, behaviourally: a real failure on a non-CI PHP is still FAIL ──
    #
    # The first backstop turned every non-zero PHPUnit exit on a non-CI PHP
    # into SKIP, so a deliberate $this->fail() reported as environmental with
    # exit 0. A reviewer caught it. These cases pin the fail-closed shape and
    # CI's false-green guard (an exit-0 run that executed no tests).
    if [ -x "$REAL_VENDOR/bin/phpunit" ]; then
        ln -s "$(cd "$REAL_VENDOR" && pwd)" vendor
        cat > phpunit.ci.xml <<'XML'
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="false" convertDeprecationsToExceptions="false">
  <testsuites><testsuite name="default"><directory>tests</directory></testsuite></testsuites>
</phpunit>
XML
        mkdir -p .github/workflows tests
        printf "      php-version: '7.4'\n" > .github/workflows/php-unit.yml
        printf '<?php\nfinal class ZzPassTest extends \\PHPUnit\\Framework\\TestCase { public function testOk(): void { $this->assertTrue(true); } }\n' > tests/ZzPassTest.php
        expect_unit() { # name want_verdict
            local got
            got=$(P202_MIN_UNIT_TESTS="${3:-1}" ./verify.sh --tier unit 2>/dev/null | awk '/^  unit / {print $2}')
            if [ "$got" = "$2" ]; then printf '  ok    %-46s unit=%s\n' "$1" "$got"; pass=$((pass + 1)); else printf '  FAIL  %-46s unit=%s (wanted %s)\n' "$1" "$got" "$2"; fail=$((fail + 1)); fi
        }
        expect_unit "unit: green suite on a non-CI PHP PASSES" PASS
        printf '<?php\nfinal class ZzFailTest extends \\PHPUnit\\Framework\\TestCase { public function testNo(): void { $this->fail("deliberate"); } }\n' > tests/ZzFailTest.php
        expect_unit "unit: a real failure on a non-CI PHP is FAIL, not SKIP" FAIL
        note_out=$(P202_MIN_UNIT_TESTS=1 ./verify.sh --tier unit 2>&1)
        if printf '%s' "$note_out" | grep -q "CI pins 7.4"; then
            printf '  ok    %-46s\n' "unit: the FAIL carries the interpreter note"; pass=$((pass + 1))
        else
            printf '  FAIL  %-46s\n' "unit: the FAIL carries the interpreter note"; fail=$((fail + 1))
            printf '%s\n' "$note_out" | grep -E "^--- unit|^  unit|php-version|PHP" | sed 's/^/        | /'
        fi
        rm -f tests/ZzFailTest.php
        printf '<?php\nexit(0);\n' > tests/ZzAbortTest.php
        expect_unit "unit: collection abort with exit 0 is FAIL (false green)" FAIL
        rm -f tests/ZzAbortTest.php
        expect_unit "unit: too few tests for CI's threshold is FAIL" FAIL 600
        rm -rf tests/ZzPassTest.php phpunit.ci.xml .github vendor
    else
        printf '  skip  unit tier cases: no vendor/bin/phpunit at %s\n' "$REAL_VENDOR"
    fi

    # ── schema tier, behaviourally: a skip next to a failure is FAIL ──
    #
    # The detector fires on "Skipped: N". The first version consulted it
    # before the exit status, so a run in which one test skipped (no DB) and
    # another failed reported SKIP and hid the failure.
    if [ -x "$REAL_VENDOR/bin/phpunit" ]; then
        ln -s "$(cd "$REAL_VENDOR" && pwd)" vendor
        cat > phpunit.ci.xml <<'XML'
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="false" convertDeprecationsToExceptions="false">
  <testsuites><testsuite name="default"><directory>tests</directory></testsuite></testsuites>
</phpunit>
XML
        mkdir -p tests/Schema
        expect_schema() { # name want_verdict
            local got
            got=$(P202_TEST_DB_HOST=127.0.0.1 P202_TEST_DB_NAME=zz_scratch ./verify.sh --tier schema 2>/dev/null | awk '/^  schema / {print $2}')
            if [ "$got" = "$2" ]; then printf '  ok    %-46s schema=%s\n' "$1" "$got"; pass=$((pass + 1)); else printf '  FAIL  %-46s schema=%s (wanted %s)\n' "$1" "$got" "$2"; fail=$((fail + 1)); fi
        }
        printf '<?php\n/** @group integration */\nfinal class ZzSkipTest extends \\PHPUnit\\Framework\\TestCase { public function testDb(): void { $this->markTestSkipped("no db"); } }\n' > tests/Schema/ZzSkipTest.php
        expect_schema "schema: only skips (no db) is SKIP, not PASS" SKIP
        printf '<?php\n/** @group integration */\nfinal class ZzScanTest extends \\PHPUnit\\Framework\\TestCase { public function testScan(): void { $this->fail("scanner regression"); } }\n' > tests/Schema/ZzScanTest.php
        expect_schema "schema: a failure next to a skip is FAIL, not SKIP" FAIL
        rm -f tests/Schema/ZzScanTest.php tests/Schema/ZzSkipTest.php
        printf '<?php\n/** @group integration */\nfinal class ZzOkTest extends \\PHPUnit\\Framework\\TestCase { public function testOk(): void { $this->assertTrue(true); } }\n' > tests/Schema/ZzOkTest.php
        expect_schema "schema: a green run PASSES" PASS
        rm -rf tests/Schema phpunit.ci.xml vendor
    else
        printf '  skip  schema tier cases: no vendor/bin/phpunit at %s\n' "$REAL_VENDOR"
    fi

    # ── go tier: a change that breaks the build is FAIL; only a host that
    #    cannot build cgo at all is SKIP. The output cannot tell the two
    #    apart, so the tier compiles a known-good cgo program to find out. ──
    if command -v go >/dev/null 2>&1 && [ -n "$(go env GOROOT 2>/dev/null)" ]; then
        mkdir -p go-cli/cmd/x
        printf 'module p202harness\n\ngo 1.22\n' > go-cli/go.mod
        printf 'package main\n\nfunc main() {}\n' > go-cli/cmd/x/main.go
        printf 'package main\n\nimport "testing"\n\nfunc TestOk(t *testing.T) {}\n' > go-cli/cmd/x/main_test.go
        expect_go() { # name want_verdict [env...]
            local name="$1" want="$2"; shift 2
            local got
            got=$(env "$@" ./verify.sh --tier go 2>/dev/null | awk '/^  go / {print $2}')
            if [ "$got" = "$want" ]; then printf '  ok    %-46s go=%s\n' "$name" "$got"; pass=$((pass + 1)); else printf '  FAIL  %-46s go=%s (wanted %s)\n' "$name" "$got" "$want"; fail=$((fail + 1)); fi
        }
        expect_go "go: healthy module PASSES" PASS
        printf 'package main\n\n// #cgo LDFLAGS: -lp202_no_such_lib_zz\nimport "C"\n\nfunc main() {}\n' > go-cli/cmd/x/main.go
        expect_go "go: a change with bad cgo is FAIL on a healthy host" FAIL
        expect_go "go: the same failure on a host that cannot build cgo is SKIP" SKIP CC=false
        eval "$(sed -n '/^host_go_toolchain_broken() {/,/^}/p' ./verify.sh)"
        # Read by the sourced host_go_toolchain_broken, which shellcheck cannot see.
        # shellcheck disable=SC2034
        GO_BIN=$(command -v go)
        if ! host_go_toolchain_broken && CC=false host_go_toolchain_broken; then
            printf '  ok    %-46s\n' "go: probe tells a healthy host from a broken one"; pass=$((pass + 1))
        else
            printf '  FAIL  %-46s\n' "go: probe tells a healthy host from a broken one"; fail=$((fail + 1))
        fi
        # ── golangci tier: same shape. A change that cannot load is FAIL; only a
        #    host where the linter cannot lint a trivial module is SKIP. ──
        if command -v golangci-lint >/dev/null 2>&1; then
            printf 'package main\n\nfunc main() {}\n' > go-cli/cmd/x/main.go
            expect_golangci() { # name want_verdict [env...]
                local name="$1" want="$2"; shift 2
                local got
                got=$(env "$@" ./verify.sh --tier golangci 2>/dev/null | awk '/^  golangci / {print $2}')
                if [ "$got" = "$want" ]; then printf '  ok    %-46s golangci=%s\n' "$name" "$got"; pass=$((pass + 1)); else printf '  FAIL  %-46s golangci=%s (wanted %s)\n' "$name" "$got" "$want"; fail=$((fail + 1)); fi
            }
            expect_golangci "golangci: healthy module PASSES" PASS
            printf 'package main\n\nimport _ "example.com/does/not/exist_zz"\n\nfunc main() {}\n' > go-cli/cmd/x/main.go
            expect_golangci "golangci: a change importing a missing package is FAIL" FAIL
            # A build cache that cannot be created breaks package loading for
            # every module while leaving `go env` answering, which is the
            # shape of a host problem the resolver does not catch up front.
            expect_golangci "golangci: the same failure on a broken host is SKIP" SKIP GOCACHE=/nonexistent/p202/cache
            # A change-side fault whose output DOES match the trigger on this
            # version ("Running error", "context loading failed"): a
            # malformed go.mod. Only the probe can tell it from a broken host.
            printf 'package main\n\nfunc main() {}\n' > go-cli/cmd/x/main.go
            cp go-cli/go.mod "$REPO/go.mod.keep"
            printf 'module p202harness\n\ngo 1.22\n\nthis is not valid\n' > go-cli/go.mod
            expect_golangci "golangci: a change-side load error that matches the trigger is FAIL" FAIL
            mv "$REPO/go.mod.keep" go-cli/go.mod
            eval "$(sed -n '/^host_golangci_broken() {/,/^}/p' ./verify.sh)"
            if ! host_golangci_broken && GOCACHE=/nonexistent/p202/cache host_golangci_broken; then
                printf '  ok    %-46s\n' "golangci: probe tells a healthy host from a broken one"; pass=$((pass + 1))
            else
                printf '  FAIL  %-46s\n' "golangci: probe tells a healthy host from a broken one"; fail=$((fail + 1))
            fi
            printf 'package main\n\nfunc main() {}\n' > go-cli/cmd/x/main.go
        else
            printf '  skip  golangci tier cases: golangci-lint not installed\n'
        fi
        rm -rf go-cli
    else
        printf '  skip  go tier cases: no working go on PATH\n'
    fi

    rm -rf scripts ./verify.sh
else
    printf '  skip  verify.sh not found at %s\n' "$VERIFY"
fi

echo "  ---- $pass passed, $fail failed"
[ "$fail" -eq 0 ]
