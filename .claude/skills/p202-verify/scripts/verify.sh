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

ALL_TIERS="syntax phpstan phpcs unit go golangci schema patterns"

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

Tiers: syntax phpstan phpcs unit go golangci schema patterns

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
            [ -f phpunit.ci.xml ] || { echo "phpunit.ci.xml missing; this tier mirrors CI's invocation"; return; }
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

# The PHP minor version CI pins for the unit job, read from the workflow so
# it cannot drift from what CI actually runs.
ci_php_version() {
    sed -nE "s/^[[:space:]]*php-version:[[:space:]]*['\"]?([0-9]+\.[0-9]+).*/\1/p" \
        "$ROOT/.github/workflows/php-unit.yml" 2>/dev/null | head -1
}

local_php_version() {
    php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null
}

# Non-empty when the local interpreter is not the one CI tests on. A newer
# PHP turns deprecations into failures the suite never sees on CI (16 of
# them on 8.5 against a project targeting 8.3), and the ladder promised that
# environmental inability reports SKIP rather than FAIL.
interpreter_mismatch_reason() {
    local ci local_v
    ci=$(ci_php_version)
    local_v=$(local_php_version)
    [ -n "$ci" ] && [ -n "$local_v" ] || return 0
    [ "$ci" != "$local_v" ] || return 0
    echo "PHPUnit failed on PHP $local_v but CI pins $ci (.github/workflows/php-unit.yml); a newer interpreter turns deprecations into failures, see references/sandbox-recovery.md"
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
        local why
        why=$(interpreter_mismatch_reason)
        if [ -n "$why" ]; then
            COULD_NOT_RUN_REASON="$why"
            return $TIER_COULD_NOT_RUN
        fi
    fi
    return $rc
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
phpcs_summary_errors() {
    local n
    n=$(grep -oE 'A TOTAL OF [0-9]+ ERROR' | grep -oE '[0-9]+')
    echo "${n:-0}"
}

# Prints an error count, or "?" when phpcs itself could not analyse the file
# (exit 3+, typically out of memory). "?" must never be read as 0: an
# unreadable answer resolving to "clean" is CLAUDE.md error pattern #11.
phpcs_error_count_file() {
    local out rc
    # shellcheck disable=SC2086
    out=$( $PHPCS_CMD -d memory_limit=512M --standard=PSR12 --report=summary "$1" 2>&1 )
    rc=$?
    if [ $rc -ge 3 ]; then echo "?"; return; fi
    printf '%s' "$out" | phpcs_summary_errors
}

phpcs_error_count_at_head() {
    local out rc
    # shellcheck disable=SC2086
    out=$( git show "HEAD:$1" 2>/dev/null | $PHPCS_CMD -d memory_limit=512M --standard=PSR12 --report=summary --stdin-path="$1" - 2>&1 )
    rc=$?
    if [ $rc -ge 3 ]; then echo "?"; return; fi
    printf '%s' "$out" | phpcs_summary_errors
}

run_phpcs() {
    local files f head_count wt_count worse=0 examined=0
    files=$(changed_php_files)
    if [ -z "$files" ]; then
        COULD_NOT_RUN_REASON="no new or modified .php files, so nothing was examined"
        return $TIER_COULD_NOT_RUN
    fi
    while IFS= read -r f; do
        [ -f "$f" ] || continue
        examined=$((examined + 1))
        wt_count=$(phpcs_error_count_file "$f")
        if git cat-file -e "HEAD:$f" 2>/dev/null; then
            head_count=$(phpcs_error_count_at_head "$f")
        else
            head_count=0
        fi
        if [ "$wt_count" = "?" ] || [ "$head_count" = "?" ]; then
            COULD_NOT_RUN_REASON="phpcs could not analyse $f (exit 3 or higher; out of memory?)"
            return $TIER_COULD_NOT_RUN
        fi
        if [ "$wt_count" -gt "$head_count" ]; then
            printf 'phpcs: %s: %s PSR12 errors, was %s at HEAD (+%s)\n' "$f" "$wt_count" "$head_count" $((wt_count - head_count))
            worse=$((worse + 1))
        elif [ "$wt_count" -gt 0 ]; then
            printf 'phpcs: %s: %s pre-existing PSR12 errors, none added\n' "$f" "$wt_count"
        fi
    done <<< "$files"
    printf 'phpcs: %s file(s) examined, %s made worse\n' "$examined" "$worse"
    [ "$worse" -eq 0 ]
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
    local out rc godir
    godir=$(dirname "$GO_BIN")
    out=$( cd go-cli && PATH="$godir:$PATH" "$GO_BIN" vet ./... 2>&1 \
           && PATH="$godir:$PATH" "$GO_BIN" test ./... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && go_env_broken "$out"; then
        COULD_NOT_RUN_REASON="Go toolchain cannot build (cgo/linker); \`go test\` never ran this code"
        return $TIER_COULD_NOT_RUN
    fi
    [ $rc -eq 0 ] || return 1

    # An empty HOME catches flag checks that only pass because this machine
    # has a CLI config. CI has none.
    out=$( cd go-cli && HOME=$(mktemp -d) PATH="$godir:$PATH" "$GO_BIN" test ./cmd/... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && go_env_broken "$out"; then
        COULD_NOT_RUN_REASON="Go toolchain cannot build the empty-HOME run (cgo/linker)"
        return $TIER_COULD_NOT_RUN
    fi
    return $rc
}

run_golangci() {
    local out rc godir
    # golangci-lint shells out to `go env`, so the PATH it inherits decides
    # whether it can start at all.
    godir=$(dirname "$GO_BIN")
    out=$( cd go-cli && PATH="$godir:$PATH" golangci-lint run ./... 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if [ $rc -ne 0 ] && printf '%s' "$out" | grep -qE 'Running error:|context loading failed|could not load export data'; then
        COULD_NOT_RUN_REASON="golangci-lint could not load packages (toolchain mismatch or go wrapper); not a lint finding"
        return $TIER_COULD_NOT_RUN
    fi
    return $rc
}

run_schema() {
    local out rc
    # CI's invocation (.github/workflows/php-integration.yml).
    # shellcheck disable=SC2086
    out=$( $PHPUNIT_CMD --configuration phpunit.ci.xml --group integration --no-coverage tests/Schema/ 2>&1 )
    rc=$?
    printf '%s\n' "$out"
    if phpunit_ran_nothing "$out"; then
        COULD_NOT_RUN_REASON="the schema tests skipped themselves ($(printf '%s' "$out" | grep -oE 'Tests: [0-9]+.*|No tests executed' | tail -1)); is P202_TEST_DB_HOST reachable?"
        return $TIER_COULD_NOT_RUN
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
    if [ -z "$(changed_php_files)" ]; then
        COULD_NOT_RUN_REASON="no new or modified .php files, so no lines were examined"
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
    echo "$files" | grep -q '\.php$'                    && tiers="$tiers phpstan phpcs unit"
    echo "$files" | grep -q '^go-cli/'                  && tiers="$tiers go golangci"
    # 202-config/Database/Tables/*.php hold the CREATE TABLE definitions the
    # installer runs, and 202-config/migrations/ the ALTERs; neither has
    # "schema" in its path, which is how the first version of this line
    # missed the repository's primary schema-edit path.
    echo "$files" | grep -qE '^(202-config/(Database|migrations)/|tests/Schema/)' && tiers="$tiers schema"
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
