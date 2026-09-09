#!/usr/bin/env bash
# Prosper202 verification ladder.
#
# Runs each tier the environment can support and prints a scope report that
# distinguishes PASS, FAIL and SKIP. A tier that could not run is never
# reported as passing, because conflating "did not run" with "passed" is the
# failure this script exists to prevent (CLAUDE.md error pattern #10).
#
# Exit codes:
#   0  every tier that ran passed
#   1  at least one tier failed
#   2  bad usage
#
# Skips never affect the exit code. Read the report, not just the code.

set -uo pipefail

ALL_TIERS="syntax phpstan phpcs unit go golangci schema patterns actionlint"

usage() {
    cat <<'EOF'
Usage: verify.sh [options]

  (no options)     run every tier the environment supports
  --probe          detect the environment and report tier availability only
  --changed        run only the tiers implied by the working-tree diff
  --plan           print the tiers --changed would run, without running them
  --tier NAME      run one tier (repeatable)
  --list           list tier names
  -h, --help       this message

Tiers: syntax phpstan phpcs unit go golangci schema patterns actionlint

Tiers 8 (live end-to-end) and 9 (agent eval) are deliberately not scripted.
They need a running instance and a decision about what to exercise. See
SKILL.md.
EOF
}

# ---------------------------------------------------------------- setup

if ROOT=$(git rev-parse --show-toplevel 2>/dev/null); then
    :
else
    SELF=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
    ROOT=$(cd "$SELF/../../../.." && pwd)
fi
cd "$ROOT" || { echo "cannot cd to repo root" >&2; exit 2; }

MODE=run
SELECTED=""

while [ $# -gt 0 ]; do
    case "$1" in
        --probe)   MODE=probe ;;
        --changed) MODE=changed ;;
        --plan)    MODE=plan ;;
        --list)    echo "$ALL_TIERS" | tr ' ' '\n'; exit 0 ;;
        --tier)
            shift
            [ $# -gt 0 ] || { echo "--tier needs a name" >&2; exit 2; }
            SELECTED="$SELECTED $1"
            ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

# ---------------------------------------------------- environment probe

have() { command -v "$1" >/dev/null 2>&1; }

PHPUNIT_CMD=""
PHPSTAN_CMD=""
VENDOR_STATE="absent"

if [ -f vendor/autoload.php ]; then
    if [ -d vendor/bin ]; then VENDOR_STATE="full"; else VENDOR_STATE="partial"; fi
fi

if [ -x vendor/bin/phpunit ]; then
    PHPUNIT_CMD="vendor/bin/phpunit"
elif [ -f phpunit-9.phar ] && have php; then
    PHPUNIT_CMD="php phpunit-9.phar --bootstrap vendor/autoload.php"
fi

if [ -x vendor/bin/phpstan ]; then
    PHPSTAN_CMD="vendor/bin/phpstan"
elif [ -f phpstan.phar ] && have php; then
    PHPSTAN_CMD="php phpstan.phar"
fi

# A `go` on PATH is not necessarily a `go` that answers. Wrappers (ax, and
# some version-manager shims) exit 0 while emitting nothing, and golangci-lint
# reads GOROOT and GOCACHE out of `go env`, so it dies on "unexpected end of
# JSON input" before it lints a line. Walk the candidates and take the first
# that actually replies; the tiers then use that binary and put its directory
# first on PATH so the tools they shell out to resolve the same one.
GO_BIN=""
find_working_go() {
    local dir candidate search
    search="$PATH:/opt/homebrew/bin:/usr/local/go/bin:/usr/local/bin:/usr/bin"
    local IFS=:
    for dir in $search; do
        [ -n "$dir" ] || continue
        candidate="$dir/go"
        [ -x "$candidate" ] || continue
        if [ -n "$("$candidate" env GOROOT 2>/dev/null)" ]; then
            GO_BIN="$candidate"
            return 0
        fi
    done
    return 1
}
if [ -d go-cli ]; then
    find_working_go || true
fi

PHPCS_CMD=""
if [ -x vendor/bin/phpcs ]; then
    PHPCS_CMD="vendor/bin/phpcs"
elif have phpcs; then
    PHPCS_CMD="phpcs"
fi

# The PHP files a change touches: tracked modifications plus untracked new
# files. Every consumer of "which PHP changed" goes through here, because the
# first version of this script kept two copies of that selection and one of
# them forgot untracked files, which a reviewer caught after the same bug had
# been fixed in scripts/check-code-patterns.sh.
changed_php_files() {
    {
        git diff --name-only HEAD -- '*.php' 2>/dev/null
        git diff --name-only --cached -- '*.php' 2>/dev/null
        git ls-files --others --exclude-standard -- '*.php' 2>/dev/null
    } | awk 'NF' | sort -u
}

# The subset that still exists. A deletion-only change lists paths that are
# gone; a tier that "examines" those examines nothing, and two tiers were
# caught reporting PASS for exactly that.
existing_changed_php_files() {
    local f
    changed_php_files | while IFS= read -r f; do
        [ -f "$f" ] && printf '%s\n' "$f"
    done
}

SCHEMA_DB_READY=no
if [ -n "${P202_TEST_DB_HOST:-}" ] && [ -n "${P202_TEST_DB_NAME:-}" ]; then
    SCHEMA_DB_READY=yes
fi

reason_for() {
    case "$1" in
        syntax)
            have php || { echo "php not on PATH"; return; }
            ;;
        phpstan)
            [ -n "$PHPSTAN_CMD" ] || { echo "no vendor/bin/phpstan and no phpstan.phar (see references/sandbox-recovery.md)"; return; }
            [ -f phpstan.neon.dist ] || { echo "phpstan.neon.dist missing"; return; }
            ;;
        phpcs)
            [ -n "$PHPCS_CMD" ] || { echo "no vendor/bin/phpcs and no phpcs on PATH"; return; }
            ;;
        unit)
            [ -n "$PHPUNIT_CMD" ] || { echo "no vendor/bin/phpunit and no phpunit-9.phar (see references/sandbox-recovery.md)"; return; }
            [ -f phpunit.ci.xml ] || { echo "phpunit.ci.xml missing; this tier mirrors CI's invocation"; return; }
            [ -f vendor/autoload.php ] || { echo "vendor/autoload.php missing; run composer dump-autoload --dev"; return; }
            ;;
        go|golangci)
            [ -d go-cli ] || { echo "go-cli/ missing"; return; }
            if [ -z "$GO_BIN" ]; then
                if have go; then
                    echo "no working go found; the one on PATH ($(command -v go)) answers \`go env\` with nothing, and no fallback replied either"
                else
                    echo "go not on PATH"
                fi
                return
            fi
            if [ "$1" = golangci ]; then
                have golangci-lint || { echo "golangci-lint not installed"; return; }
            fi
            ;;
        schema)
            [ -n "$PHPUNIT_CMD" ] || { echo "phpunit unavailable"; return; }
            [ -f vendor/autoload.php ] || { echo "vendor/autoload.php missing; run composer dump-autoload --dev"; return; }
            [ -f phpunit.ci.xml ] || { echo "phpunit.ci.xml missing; this tier mirrors CI's invocation"; return; }
            [ "$SCHEMA_DB_READY" = yes ] || { echo "set P202_TEST_DB_HOST and P202_TEST_DB_NAME to a SCRATCH database (this tier drops and recreates tables)"; return; }
            ;;
        patterns)
            [ -x scripts/check-code-patterns.sh ] || { echo "scripts/check-code-patterns.sh not executable"; return; }
            git rev-parse --git-dir >/dev/null 2>&1 || { echo "not a git working tree"; return; }
            ;;
        actionlint)
            have actionlint || { echo "actionlint not installed (brew install actionlint, or the download script CI uses)"; return; }
            [ -d .github/workflows ] || { echo ".github/workflows/ missing"; return; }
            ;;
    esac
    echo ""
}

# ------------------------------------------------------------ tier runs

# Exit status 77 from a run_* function means "could not run", not "failed".
# The dispatcher turns it into a SKIP with a reason. Some breakage is only
# visible once the tool starts: a golangci-lint built against a different Go
# toolchain loads packages and then dies on missing export data, which is an
# environment gap and not a finding about this code.
readonly TIER_COULD_NOT_RUN=77
COULD_NOT_RUN_REASON=""
# A tier that fails may attach context for the reader. It is context only:
# a FAIL with a note is still a FAIL and still sets the exit code.
FAIL_NOTE=""
# A tier that passes may attach a caveat the same way: a PASS with a note is
# still a PASS, but the reader is told what this environment could not
# have seen. Used when the local toolchain is newer than CI's.
PASS_NOTE=""

run_syntax() {
    local status=0
    find . -path ./vendor -prune -o -path ./.git -prune -o \
        -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l >/dev/null || status=1
    find . -path ./vendor -prune -o -path ./.git -prune -o \
        -type f -name '*.sh' -print0 | xargs -0 -r -n1 bash -n || status=1
    return $status
}

run_phpstan() {
    # --memory-limit is not optional locally. CI installs PHP through
    # setup-php, which leaves memory_limit uncapped; a stock local php.ini
    # caps it at 128M and PHPStan dies parsing the intl stubs. Without the
    # flag this tier reports FAIL for an environment reason and sends the
    # reader hunting a regression that is not there.
    # shellcheck disable=SC2086
    $PHPSTAN_CMD analyse -c phpstan.neon.dist --no-progress --memory-limit=512M
}

# The PHP minor version CI pins for the unit job, read from the workflow so
# it cannot drift from what CI actually runs.
ci_php_version() {
    sed -nE "s/^[[:space:]]*php-version:[[:space:]]*['\"]?([0-9]+\.[0-9]+).*/\1/p" \
        "$ROOT/.github/workflows/php-unit.yml" 2>/dev/null | head -1
}

local_php_version() {
    php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null
}

# Non-empty when the local interpreter is not the one CI tests on. Attached
# to a FAIL as a note so the reader can judge whether the failures are the
# interpreter (a newer PHP promotes deprecations) or the change. It never
# downgrades the verdict: the first version turned every non-zero exit on a
# non-CI PHP into SKIP, which would have hidden a real \$this->fail() behind
# "environmental". A reviewer caught that before it shipped.
interpreter_mismatch_reason() {
    local ci local_v
    ci=$(ci_php_version)
    local_v=$(local_php_version)
    [ -n "$ci" ] && [ -n "$local_v" ] || return 0
    [ "$ci" != "$local_v" ] || return 0
    echo "local PHP is $local_v, CI pins $ci (.github/workflows/php-unit.yml); if every failure above is a deprecation notice that is the interpreter, not the change, see references/sandbox-recovery.md"
}

# CI's false-green guard (.github/workflows/php-unit.yml): a collection-time
# die()/exit(0) aborts PHPUnit before any test runs, yet can exit 0. PHPUnit
# prints two summary forms, "OK (N tests, M assertions)" for a clean run and
# "Tests: N, Assertions: M, ..." otherwise; parse both. CI requires 600.
MIN_UNIT_TESTS="${P202_MIN_UNIT_TESTS:-600}"

phpunit_test_count() {
    local n
    n=$(printf '%s' "$1" | grep -oE 'OK \([0-9]+ test' | tail -1 | grep -oE '[0-9]+')
    if [ -z "$n" ]; then
        n=$(printf '%s' "$1" | grep -oE 'Tests: [0-9]+' | tail -1 | grep -oE '[0-9]+')
    fi
    echo "${n:-0}"
}

run_unit() {
    local out rc
    # Exactly CI's invocation (.github/workflows/php-unit.yml). The strict
    # phpunit.xml is for local development and promotes deprecations to
    # errors; running it here reported 16 failures on PHP 8.5 that CI, on
    # 8.3 with phpunit.ci.xml, never sees.
    # shellcheck disable=SC2086
    out=$( $PHPUNIT_CMD --configuration phpunit.ci.xml --exclude-group integration --no-coverage 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ]; then
        FAIL_NOTE=$(interpreter_mismatch_reason)
        return 1
    fi
    local count
    count=$(phpunit_test_count "$out")
    if [ "$count" -lt "$MIN_UNIT_TESTS" ]; then
        FAIL_NOTE="PHPUnit exited 0 but ran only $count tests (expected >= $MIN_UNIT_TESTS); the suite probably aborted during collection (a load-time die()/exit), which CI also treats as a failure"
        return 1
    fi
    return 0
}

# PHPUnit 9 exits 0 when every test it selected skipped itself, and the
# schema test skips when it cannot reach the database. "OK, but incomplete,
# skipped, or risky tests!" is not a pass of anything.
phpunit_ran_nothing() {
    printf '%s' "$1" | grep -qE 'No tests executed|Tests: 0,|Skipped: [1-9]'
}

# PSR12 as a ratchet. AGENTS.md asks for `phpcs --standard=PSR12 .`, CI does
# not run it, and the tree is nowhere near clean (api/v3 alone carries a few
# hundred findings), so a tier that fails on any finding would fail on every
# touch of a legacy file and be switched off within the week. This one fails
# only when a change makes a file worse than it was at HEAD; a brand-new file
# has no HEAD and must be clean.
# Turns one phpcs run into "ERRORS WARNINGS", or "?" when phpcs did not
# actually analyse the file. The exit code cannot be trusted for that: the
# documented bitmask (1 errors, 2 warnings, 3 both) does not match what
# PHP_CodeSniffer 3.13 does (2 for errors, 1 for warnings only, 3 for a
# missing file or unknown standard, 2 for out-of-memory). The first version
# read exit 3 as a tool failure and would have skipped the ratchet for a
# file with both errors and warnings. The stable signal is the report: a
# successful analysis either prints "A TOTAL OF N ERRORS AND M WARNINGS" or
# prints nothing at all with exit 0 (clean). Anything else is "?", which
# must never be read as 0 (CLAUDE.md error pattern #11).
phpcs_counts_from_run() { # $1 = exit code, $2 = output
    local e w
    e=$(printf '%s' "$2" | grep -oE 'A TOTAL OF [0-9]+ ERROR' | grep -oE '[0-9]+' | head -1)
    w=$(printf '%s' "$2" | grep -oE '[0-9]+ WARNING' | grep -oE '[0-9]+' | head -1)
    if [ -n "$e" ] && [ -n "$w" ]; then
        echo "$e $w"
    elif [ "$1" -eq 0 ] && [ -z "$(printf '%s' "$2" | tr -d '[:space:]')" ]; then
        echo "0 0"
    else
        echo "?"
    fi
}

phpcs_counts_file() {
    local out rc
    # shellcheck disable=SC2086
    out=$( $PHPCS_CMD -d memory_limit=512M --standard=PSR12 --report=summary "$1" 2>&1 )
    rc=$?
    phpcs_counts_from_run "$rc" "$out"
}

# The HEAD path to ratchet $1 against. A path that exists at HEAD is its
# own baseline. A path that does not may still be a rename: git's rename
# detection covers a staged move (with or without edits), and an exact
# content match against a deleted path covers an unstaged plain move. With
# neither, the file is new and must be clean. The first version treated
# every path absent from HEAD as new, so moving a legacy file failed the
# ratchet on every finding it already had.
phpcs_baseline_path() {
    local f="$1" old blob d
    if git cat-file -e "HEAD:$f" 2>/dev/null; then
        echo "$f"
        return
    fi
    old=$(git diff -M50 --name-status HEAD 2>/dev/null | awk -F'\t' -v f="$f" '$1 ~ /^R/ && $3 == f { print $2; exit }')
    if [ -n "$old" ]; then
        echo "$old"
        return
    fi
    blob=$(git hash-object "$f" 2>/dev/null) || return 0
    git diff --name-only --diff-filter=D HEAD 2>/dev/null | while IFS= read -r d; do
        if [ "$(git rev-parse "HEAD:$d" 2>/dev/null)" = "$blob" ]; then
            echo "$d"
            break
        fi
    done
}

phpcs_counts_at_head() {
    local out rc
    # shellcheck disable=SC2086
    out=$( git show "HEAD:$1" 2>/dev/null | $PHPCS_CMD -d memory_limit=512M --standard=PSR12 --report=summary --stdin-path="$1" - 2>&1 )
    rc=$?
    phpcs_counts_from_run "$rc" "$out"
}

run_phpcs() {
    local files f base head wt he hw we ww worse=0 examined=0
    files=$(changed_php_files)
    if [ -z "$files" ]; then
        COULD_NOT_RUN_REASON="no new or modified .php files, so nothing was examined"
        return $TIER_COULD_NOT_RUN
    fi
    while IFS= read -r f; do
        [ -f "$f" ] || continue
        examined=$((examined + 1))
        wt=$(phpcs_counts_file "$f")
        base=$(phpcs_baseline_path "$f")
        if [ -n "$base" ]; then
            head=$(phpcs_counts_at_head "$base")
        else
            head="0 0"
        fi
        if [ "$wt" = "?" ] || [ "$head" = "?" ]; then
            COULD_NOT_RUN_REASON="phpcs did not analyse $f (no report; unknown standard, missing file or out of memory?)"
            return $TIER_COULD_NOT_RUN
        fi
        we=${wt%% *}; ww=${wt##* }; he=${head%% *}; hw=${head##* }
        # Errors and warnings ratchet independently: AGENTS.md's
        # `phpcs --standard=PSR12 .` fails on either, so a new file may not
        # introduce warnings any more than errors.
        if [ "$we" -gt "$he" ] || [ "$ww" -gt "$hw" ]; then
            printf 'phpcs: %s: %s errors / %s warnings, was %s / %s at HEAD%s\n' "$f" "$we" "$ww" "$he" "$hw" "${base:+ ($base)}"
            if [ -z "$base" ]; then
                printf 'phpcs: %s has no baseline at HEAD; if it is a renamed legacy file with edits, stage the rename (git add -A) so it can be matched\n' "$f"
            fi
            worse=$((worse + 1))
        elif [ "$we" -gt 0 ] || [ "$ww" -gt 0 ]; then
            printf 'phpcs: %s: %s pre-existing errors / %s warnings, none added\n' "$f" "$we" "$ww"
        fi
    done <<< "$files"
    # A change consisting only of deletions lists files that no longer
    # exist; the loop above skips them and examines nothing. That is not a
    # pass of anything.
    if [ "$examined" -eq 0 ]; then
        COULD_NOT_RUN_REASON="every changed .php file has been deleted, so nothing was examined"
        return $TIER_COULD_NOT_RUN
    fi
    printf 'phpcs: %s file(s) examined, %s made worse\n' "$examined" "$worse"
    [ "$worse" -eq 0 ]
}

# Output that MIGHT mean the host toolchain is broken. A genuine Go compile
# error names a repo file and line (./cmd/foo.go:12:3) and never matches. A
# cgo or linker failure matches, but that output is ambiguous: a host whose
# Xcode tools do not match the installed Go produces it for every package,
# and so does a change that adds bad C or links a library that is not there.
# The text cannot tell those apart, so this is only the trigger for the
# probe below; the verdict comes from the probe. The first version treated a
# match as proof and would have reported a change that broke the build as
# SKIP with exit 0.
go_env_broken() {
    printf '%s' "$1" | grep -qE 'missing LC_UUID|__cgo_|clang: error|(^|[[:space:]])ld: |cannot find -l|no export data|running cc failed|(^|[[:space:]])cgo: |runtime/cgo: '
}

# Settles the ambiguity by execution: build a known-good cgo program with the
# same toolchain, in a temp module that shares nothing with the change. If
# that fails, the host cannot build cgo at all and the tier could not run. If
# it succeeds, whatever failed in go-cli is the change, and a FAIL.
host_go_toolchain_broken() {
    local dir rc
    dir=$(mktemp -d) || return 0
    printf 'module p202probe\n\ngo 1.22\n' > "$dir/go.mod"
    printf 'package main\n\nimport "C"\n\nfunc main() {}\n' > "$dir/main.go"
    ( cd "$dir" && PATH="$(dirname "$GO_BIN"):$PATH" "$GO_BIN" build -o /dev/null . >/dev/null 2>&1 )
    rc=$?
    rm -rf "$dir"
    [ $rc -ne 0 ]
}

# The Go minor version CI pins for go-cli, read from the workflow.
ci_go_version() {
    sed -nE "s/^[[:space:]]*go-version:[[:space:]]*['\"]?([0-9]+\.[0-9]+).*/\1/p" \
        "$ROOT/.github/workflows/go-cli.yml" 2>/dev/null | head -1
}

local_go_version() {
    "$GO_BIN" env GOVERSION 2>/dev/null | sed -nE 's/^go([0-9]+\.[0-9]+).*/\1/p'
}

# The `go 1.22` directive gates language features, not the standard library:
# a module that imports a package added in a later release builds on a newer
# local Go and fails on CI's. Pinning CI's toolchain here is not an option on
# every machine (Go 1.22 could not link on the macOS this was written on), so
# a PASS from a newer Go carries the caveat rather than pretending to be CI.
go_version_mismatch_note() {
    local ci local_v
    ci=$(ci_go_version)
    local_v=$(local_go_version)
    [ -n "$ci" ] && [ -n "$local_v" ] || return 0
    [ "$ci" != "$local_v" ] || return 0
    echo "local Go is $local_v, CI pins $ci (.github/workflows/go-cli.yml); standard-library APIs newer than $ci would pass here and fail CI"
}

# The committed module graph must load before anything is attributed to the
# change. On a fresh or network-restricted checkout with an empty module
# cache, `go vet` fails while downloading the existing dependencies, having
# compiled nothing of this repository; that is the environment, not the code.
# Only a fetch that the environment prevented counts, and the environment
# is judged on the committed module graph, not the working tree's. Two
# earlier versions got this wrong in the same direction: the first reported
# any `go list -m all` failure as SKIP (a malformed go.mod is the change's
# fault); the second required a network signature, which a requirement the
# change itself added also produces, since go tries to fetch it. So the
# probe loads HEAD's go.mod and go.sum in a temp dir. If the baseline graph
# loads, the environment can fetch and whatever failed is the change; if the
# baseline fails with a network signature, nothing of this repository could
# have been compiled here.
go_modules_unavailable() {
    local dir out rc
    ( cd go-cli && PATH="$(dirname "$GO_BIN"):$PATH" "$GO_BIN" list -m all >/dev/null 2>&1 ) && return 1
    dir=$(mktemp -d) || return 1
    git show HEAD:go-cli/go.mod > "$dir/go.mod" 2>/dev/null || { rm -rf "$dir"; return 1; }
    git show HEAD:go-cli/go.sum > "$dir/go.sum" 2>/dev/null || true
    out=$( cd "$dir" && PATH="$(dirname "$GO_BIN"):$PATH" "$GO_BIN" list -m all 2>&1 >/dev/null )
    rc=$?
    rm -rf "$dir"
    [ $rc -ne 0 ] && printf '%s' "$out" | grep -qE 'proxy\.golang\.org|GOPROXY|module lookup disabled|dial tcp|no such host|connection refused|i/o timeout|TLS handshake|Forbidden|Service Unavailable'
}

run_go() {
    local out rc godir gofmt_bin unformatted
    godir=$(dirname "$GO_BIN")
    # CI's first gate (.github/workflows/go-cli.yml): any file gofmt would
    # reformat fails the job before vet or test run. It needs no module
    # cache and no network, so it runs before the availability check: a
    # tier that cannot fetch dependencies can still report a code failure
    # it is able to see.
    gofmt_bin="$("$GO_BIN" env GOROOT 2>/dev/null)/bin/gofmt"
    if [ -x "$gofmt_bin" ]; then
        local gofmt_rc
        # stderr kept and exit status checked: a file gofmt cannot parse
        # produces a diagnostic on stderr, nothing on stdout, and exit 2. The
        # first version discarded both and walked on to the module check,
        # which on a machine that cannot fetch reported the whole tier SKIP
        # for a syntax error. CI rejects that file at the same step.
        unformatted=$( cd go-cli && "$gofmt_bin" -l . 2>&1 )
        gofmt_rc=$?
        if [ $gofmt_rc -ne 0 ]; then
            printf 'gofmt failed:\n%s\n' "$unformatted"
            FAIL_NOTE="gofmt exited $gofmt_rc, which means a file it could not parse; CI's Go workflow fails on this before vet or test"
            return 1
        fi
        if [ -n "$unformatted" ]; then
            printf 'gofmt would reformat:\n%s\n' "$unformatted"
            FAIL_NOTE="gofmt -l lists $(printf '%s\n' "$unformatted" | grep -c .) file(s); CI's Go workflow fails on this before vet or test"
            return 1
        fi
    fi
    if go_modules_unavailable; then
        COULD_NOT_RUN_REASON="the committed Go module graph could not be loaded (empty module cache with no network or proxy?); nothing of this repository was compiled"
        return $TIER_COULD_NOT_RUN
    fi
    out=$( cd go-cli && PATH="$godir:$PATH" "$GO_BIN" vet ./... 2>&1 \
           && PATH="$godir:$PATH" "$GO_BIN" test ./... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && go_env_broken "$out" && host_go_toolchain_broken; then
        COULD_NOT_RUN_REASON="the host Go toolchain cannot build a trivial cgo program (probe failed); \`go test\` never ran this code"
        return $TIER_COULD_NOT_RUN
    fi
    if [ $rc -ne 0 ] && go_env_broken "$out"; then
        FAIL_NOTE="output mentions the C toolchain, but a trivial cgo program builds on this host, so the failure is in the change"
    fi
    [ $rc -eq 0 ] || return 1

    # An empty HOME catches flag checks that only pass because this machine
    # has a CLI config. CI has none.
    out=$( cd go-cli && HOME=$(mktemp -d) PATH="$godir:$PATH" "$GO_BIN" test ./cmd/... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && go_env_broken "$out" && host_go_toolchain_broken; then
        COULD_NOT_RUN_REASON="the host Go toolchain cannot build a trivial cgo program (probe failed) during the empty-HOME run"
        return $TIER_COULD_NOT_RUN
    fi
    [ $rc -eq 0 ] && PASS_NOTE=$(go_version_mismatch_note)
    return $rc
}

# Output that MIGHT mean golangci-lint cannot run on this host. As with the
# Go tier, the text is ambiguous: a golangci-lint built against a different
# Go fails to load every package, and so does a change that imports a
# package that does not exist. Which strings appear even varies by
# golangci-lint version (2.1.6 prints "could not load export data" for a bad
# import; 2.13 prints "typecheck" and "no required module"). So this is the
# trigger only; the probe decides.
golangci_env_broken() {
    printf '%s' "$1" | grep -qE 'Running error:|context loading failed|could not load export data|Failed to discover go env'
}

# Lint a known-good module with the same golangci-lint and the same go. If
# that fails, the host cannot run the linter at all; if it succeeds, whatever
# failed in go-cli is the change.
host_golangci_broken() {
    local dir rc
    dir=$(mktemp -d) || return 0
    printf 'module p202probe\n\ngo 1.22\n' > "$dir/go.mod"
    printf 'package main\n\nfunc main() {}\n' > "$dir/main.go"
    ( cd "$dir" && PATH="$(dirname "$GO_BIN"):$PATH" golangci-lint run ./... >/dev/null 2>&1 )
    rc=$?
    rm -rf "$dir"
    [ $rc -ne 0 ]
}

run_golangci() {
    local out rc godir
    if go_modules_unavailable; then
        COULD_NOT_RUN_REASON="the committed Go module graph could not be loaded (empty module cache with no network or proxy?); golangci-lint cannot type-check without it"
        return $TIER_COULD_NOT_RUN
    fi
    # golangci-lint shells out to `go env`, so the PATH it inherits decides
    # whether it can start at all.
    godir=$(dirname "$GO_BIN")
    out=$( cd go-cli && PATH="$godir:$PATH" golangci-lint run ./... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && golangci_env_broken "$out" && host_golangci_broken; then
        COULD_NOT_RUN_REASON="golangci-lint cannot lint a trivial module on this host (probe failed): toolchain mismatch or go wrapper; not a finding about this code"
        return $TIER_COULD_NOT_RUN
    fi
    if [ $rc -ne 0 ] && golangci_env_broken "$out"; then
        FAIL_NOTE="output mentions package loading, but golangci-lint lints a trivial module on this host, so the failure is in the change"
    fi
    [ $rc -eq 0 ] && PASS_NOTE=$(go_version_mismatch_note)
    return $rc
}

run_schema() {
    local out rc
    # CI's invocation (.github/workflows/php-integration.yml).
    # shellcheck disable=SC2086
    out=$( $PHPUNIT_CMD --configuration phpunit.ci.xml --group integration --no-coverage tests/Schema/ 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    # Only a run PHPUnit itself called successful can be "ran nothing". A
    # non-zero exit means at least one test failed, and a failure next to a
    # skip is still a failure: the first version checked the summary before
    # the exit status and would have reported SKIP for a run in which the
    # scanner test failed while the database test skipped.
    if [ $rc -eq 0 ] && phpunit_ran_nothing "$out"; then
        COULD_NOT_RUN_REASON="the schema tests skipped themselves ($(printf '%s' "$out" | grep -oE 'Tests: [0-9]+.*|No tests executed' | tail -1)); is P202_TEST_DB_HOST reachable?"
        return $TIER_COULD_NOT_RUN
    fi
    if [ $rc -ne 0 ] && phpunit_ran_nothing "$out"; then
        FAIL_NOTE="some schema tests also skipped themselves (is P202_TEST_DB_HOST reachable?), but at least one failed and that is the verdict"
    fi
    return $rc
}

run_patterns() {
    # check-code-patterns.sh exits 0 immediately when it has no PHP to look
    # at. Reporting that as PASS would claim a verification that never looked
    # at anything, which is the conflation this whole script exists to
    # prevent. The selection here must match the hook's own, tracked changes
    # plus untracked files, or this guard skips a tier the hook would have
    # run: the first version of this guard did exactly that, and a reviewer
    # caught it two commits after the same bug was fixed in the hook.
    if [ -z "$(existing_changed_php_files)" ]; then
        COULD_NOT_RUN_REASON="no new or modified .php files still exist, so no lines were examined"
        return $TIER_COULD_NOT_RUN
    fi
    scripts/check-code-patterns.sh
    local rc=$?
    # The Stop hook uses exit 2 for "violations found".
    [ $rc -eq 0 ] && return 0
    return 1
}

# CI's workflow gate (.github/workflows/scripts-lint.yml, actionlint job):
# invalid action inputs, unknown contexts, workflow syntax. A workflow-only
# change previously selected nothing that could see any of that, and one
# such change on this branch was rejected by CI for an input that did not
# exist in the action version named.
run_actionlint() {
    actionlint
}

# ------------------------------------------------- tiers from the diff

tiers_from_diff() {
    local files tiers="syntax patterns"
    # Untracked files must be included. A brand-new .php file is invisible to
    # `git diff`, so without this the phpstan and unit tiers are silently
    # dropped and the scope report still reads clean -- error pattern #10
    # rebuilt inside the tool that exists to prevent it.
    files=$(
        git diff --name-only HEAD 2>/dev/null
        git diff --name-only --cached 2>/dev/null
        git ls-files --others --exclude-standard 2>/dev/null
    )
    if [ -z "$files" ]; then
        echo "$tiers"
        return
    fi
    echo "$files" | grep -q '\.php$'                    && tiers="$tiers phpstan phpcs unit"
    echo "$files" | grep -q '^go-cli/'                  && tiers="$tiers go golangci"
    # 202-config/Database/Tables/*.php hold the CREATE TABLE definitions the
    # installer runs, and 202-config/migrations/ the ALTERs; neither has
    # "schema" in its path, which is how the first version of this line
    # missed the repository's primary schema-edit path.
    # api/v3 is where StaticSqlSchemaTest scans for SQL literals to prepare
    # against the schema, so a query change there is a schema change.
    echo "$files" | grep -qE '^(202-config/(Database|migrations)/|tests/Schema/|api/v3/)' && tiers="$tiers schema"
    echo "$files" | grep -qE '^\.github/workflows/.*\.ya?ml$' && tiers="$tiers actionlint"
    echo "$files" | grep -q '\.sql$'                    && tiers="$tiers schema"
    echo "$tiers" | tr ' ' '\n' | awk 'NF' | sort -u | tr '\n' ' '
}

# ------------------------------------------------------------- dispatch

case "$MODE" in
    plan)    tiers_from_diff | tr ' ' '\n' | awk 'NF'; exit 0 ;;
    changed) TIERS=$(tiers_from_diff) ;;
    *)       TIERS="${SELECTED:-$ALL_TIERS}" ;;
esac

echo "Prosper202 verification ladder"
echo "repo:   $ROOT"
echo "vendor: $VENDOR_STATE"
[ "$VENDOR_STATE" = partial ] && echo "        partial vendor: see .claude/skills/p202-verify/references/sandbox-recovery.md"
echo

if [ "$MODE" = probe ]; then
    printf '%-10s %-11s %s\n' TIER AVAILABLE REASON
    for t in $ALL_TIERS; do
        r=$(reason_for "$t")
        if [ -z "$r" ]; then
            printf '%-10s %-11s\n' "$t" yes
        else
            printf '%-10s %-11s %s\n' "$t" no "$r"
        fi
    done
    echo
    echo "Not scripted: live end-to-end, agent eval. Do these by hand (SKILL.md steps 8-9)."
    exit 0
fi

RESULTS=""
FAILED=0

for t in $TIERS; do
    case " $ALL_TIERS " in
        *" $t "*) ;;
        *) echo "unknown tier: $t" >&2; exit 2 ;;
    esac

    r=$(reason_for "$t")
    if [ -n "$r" ]; then
        RESULTS="${RESULTS}${t}|SKIP|${r}"$'\n'
        echo "--- $t: SKIP ($r)"
        continue
    fi

    echo "--- $t: running"
    COULD_NOT_RUN_REASON=""
    FAIL_NOTE=""
    PASS_NOTE=""
    "run_$t"
    rc=$?
    if [ $rc -eq 0 ]; then
        RESULTS="${RESULTS}${t}|PASS|${PASS_NOTE}"$'\n'
        echo "--- $t: PASS${PASS_NOTE:+ ($PASS_NOTE)}"
    elif [ $rc -eq $TIER_COULD_NOT_RUN ]; then
        # Never counted as a pass and never counted as a failure.
        RESULTS="${RESULTS}${t}|SKIP|${COULD_NOT_RUN_REASON:-could not run}"$'\n'
        echo "--- $t: SKIP (${COULD_NOT_RUN_REASON:-could not run})"
    else
        RESULTS="${RESULTS}${t}|FAIL|${FAIL_NOTE}"$'\n'
        FAILED=1
        echo "--- $t: FAIL${FAIL_NOTE:+ ($FAIL_NOTE)}"
    fi
    echo
done

echo
echo "Scope report"
echo "------------"
printf '%s' "$RESULTS" | while IFS='|' read -r tier verdict note; do
    [ -n "$tier" ] || continue
    if [ -n "$note" ]; then
        printf '  %-10s %-5s %s\n' "$tier" "$verdict" "$note"
    else
        printf '  %-10s %s\n' "$tier" "$verdict"
    fi
done

cat <<'EOF'

Not covered by this script:
  live      stand up an instance, drive the path a user would take
  agent     add and run a case under tests/fixtures/agent-eval/cases/
  ci        a workflow still in_progress has not passed; read the run

Report the lines above verbatim. Do not write "tests pass".
EOF

exit $FAILED
