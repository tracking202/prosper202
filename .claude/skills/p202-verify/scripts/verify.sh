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

ALL_TIERS="syntax phpstan unit go golangci schema patterns"

usage() {
    cat <<'EOF'
Usage: verify.sh [options]

  (no options)     run every tier the environment supports
  --probe          detect the environment and report tier availability only
  --changed        run only the tiers implied by the working-tree diff
  --tier NAME      run one tier (repeatable)
  --list           list tier names
  -h, --help       this message

Tiers: syntax phpstan unit go golangci schema patterns

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
        unit)
            [ -n "$PHPUNIT_CMD" ] || { echo "no vendor/bin/phpunit and no phpunit-9.phar (see references/sandbox-recovery.md)"; return; }
            [ -f vendor/autoload.php ] || { echo "vendor/autoload.php missing; run composer dump-autoload --dev"; return; }
            ;;
        go|golangci)
            [ -d go-cli ] || { echo "go-cli/ missing"; return; }
            have go || { echo "go not on PATH"; return; }
            # `go` being on PATH is not the same as `go` answering. A wrapper
            # that intercepts the toolchain (ax, asdf shims, a corporate
            # proxy) can exit 0 while emitting nothing, and golangci-lint
            # then dies on `go env -json` with "unexpected end of JSON input".
            # Catch that here so it reports SKIP rather than a FAIL that reads
            # as a code regression.
            if [ -z "$(go env -json GOROOT 2>/dev/null)" ]; then
                echo "\`go env -json\` returned nothing; the go on PATH ($(command -v go)) is a wrapper that swallows output"
                return
            fi
            if [ "$1" = golangci ]; then
                have golangci-lint || { echo "golangci-lint not installed"; return; }
            fi
            ;;
        schema)
            [ -n "$PHPUNIT_CMD" ] || { echo "phpunit unavailable"; return; }
            [ "$SCHEMA_DB_READY" = yes ] || { echo "set P202_TEST_DB_HOST and P202_TEST_DB_NAME to a SCRATCH database (this tier drops and recreates tables)"; return; }
            ;;
        patterns)
            [ -x scripts/check-code-patterns.sh ] || { echo "scripts/check-code-patterns.sh not executable"; return; }
            git rev-parse --git-dir >/dev/null 2>&1 || { echo "not a git working tree"; return; }
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

run_unit() {
    # shellcheck disable=SC2086
    $PHPUNIT_CMD
}

# Toolchain breakage, not a finding about this code. These markers are
# deliberately narrow: a genuine Go compile error names a repo file and line
# (./cmd/foo.go:12:3), which must still count as FAIL. A cgo or linker failure
# names the C toolchain, and on a machine whose Xcode tools do not match the
# installed Go it fails identically for every package.
go_env_broken() {
    printf '%s' "$1" | grep -qE 'missing LC_UUID|__cgo_|clang: error|^ld: |cannot find -l|no export data'
}

run_go() {
    local out rc
    out=$( cd go-cli && go vet ./... 2>&1 && go test ./... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && go_env_broken "$out"; then
        COULD_NOT_RUN_REASON="Go toolchain cannot build (cgo/linker); \`go test\` never ran this code"
        return $TIER_COULD_NOT_RUN
    fi
    [ $rc -eq 0 ] || return 1

    # An empty HOME catches flag checks that only pass because this machine
    # has a CLI config. CI has none.
    out=$( cd go-cli && HOME=$(mktemp -d) go test ./cmd/... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && go_env_broken "$out"; then
        COULD_NOT_RUN_REASON="Go toolchain cannot build the empty-HOME run (cgo/linker)"
        return $TIER_COULD_NOT_RUN
    fi
    return $rc
}

run_golangci() {
    local out rc
    out=$( cd go-cli && golangci-lint run ./... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && printf '%s' "$out" | grep -qE 'Running error:|context loading failed|could not load export data'; then
        COULD_NOT_RUN_REASON="golangci-lint could not load packages (toolchain mismatch or go wrapper); not a lint finding"
        return $TIER_COULD_NOT_RUN
    fi
    return $rc
}

run_schema() {
    # shellcheck disable=SC2086
    $PHPUNIT_CMD --group integration tests/Schema/
}

run_patterns() {
    # check-code-patterns.sh inspects only ADDED/MODIFIED lines and exits 0
    # immediately when no tracked .php file has changed. Reporting that as
    # PASS would claim a verification that never looked at anything, which is
    # the conflation this whole script exists to prevent.
    if [ -z "$(git diff --name-only HEAD -- '*.php' 2>/dev/null)" ]; then
        COULD_NOT_RUN_REASON="no modified tracked .php files, so no lines were examined"
        return $TIER_COULD_NOT_RUN
    fi
    scripts/check-code-patterns.sh
    local rc=$?
    # The Stop hook uses exit 2 for "violations found".
    [ $rc -eq 0 ] && return 0
    return 1
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
    echo "$files" | grep -q '\.php$'                    && tiers="$tiers phpstan unit"
    echo "$files" | grep -q '^go-cli/'                  && tiers="$tiers go golangci"
    echo "$files" | grep -qE '^(202-config/.*[Ss]chema|tests/Schema/)' && tiers="$tiers schema"
    echo "$files" | grep -q '\.sql$'                    && tiers="$tiers schema"
    echo "$tiers" | tr ' ' '\n' | awk 'NF' | sort -u | tr '\n' ' '
}

# ------------------------------------------------------------- dispatch

case "$MODE" in
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
    "run_$t"
    rc=$?
    if [ $rc -eq 0 ]; then
        RESULTS="${RESULTS}${t}|PASS|"$'\n'
        echo "--- $t: PASS"
    elif [ $rc -eq $TIER_COULD_NOT_RUN ]; then
        # Never counted as a pass and never counted as a failure.
        RESULTS="${RESULTS}${t}|SKIP|${COULD_NOT_RUN_REASON:-could not run}"$'\n'
        echo "--- $t: SKIP (${COULD_NOT_RUN_REASON:-could not run})"
    else
        RESULTS="${RESULTS}${t}|FAIL|"$'\n'
        FAILED=1
        echo "--- $t: FAIL"
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
