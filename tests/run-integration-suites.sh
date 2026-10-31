#!/bin/bash
# Run every database integration suite, one file per PHPUnit invocation, each
# against a freshly created database. This is what CI's integration job runs.
#
# The files come from tests/integration-suites.php (every `@group integration`
# file that is not also `@group instance`), so a new suite runs in CI the day
# it is written; the job used to name nine files by hand and the other 30 ran
# nowhere.
#
# A suite passes only when PHPUnit exits 0 AND reports `OK (N tests…)` with
# N >= 1. The integration suites skip themselves when they cannot reach a
# database, and PHPUnit exits 0 on a run that skipped everything — or that a
# bootstrap die() ended before any test (tests/DataEngine did exactly that
# without a 202-config.php). "Skipped" here is "did not run", so it fails.
#
# Environment:
#   P202_TEST_DB_HOST / _PORT / _USER / _PASS   MySQL (127.0.0.1 / 3306 / root / empty)
#   P202_TEST_DB_NAME   the scratch database, dropped and recreated per suite
#                       (p202_integration_test)
#   P202_PHPUNIT        the PHPUnit command (vendor/bin/phpunit); a phar works:
#                       P202_PHPUNIT="php /path/to/phpunit-9.phar"
#   P202_IT_LOG_DIR     where each suite's output is kept (mktemp -d)
#   P202_IT_SHARD_COUNT / P202_IT_SHARD_INDEX  partition the suite list (1 / 0).
#                       Each shard needs its own database and checkout.
#   P202_IT_WEIGHTS_FILE  the suites' estimated seconds and slices
#                       (tests/integration-suite-weights.txt)
#   P202_IT_DEFAULT_WEIGHT  seconds assumed for a suite the file does not list (3)
#   P202_IT_PLAN_ONLY   1 prints the whole plan and runs nothing

set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT" || exit 2

export P202_TEST_DB_HOST=${P202_TEST_DB_HOST:-127.0.0.1}
export P202_TEST_DB_PORT=${P202_TEST_DB_PORT:-3306}
export P202_TEST_DB_USER=${P202_TEST_DB_USER:-root}
export P202_TEST_DB_PASS=${P202_TEST_DB_PASS:-}
export P202_TEST_DB_NAME=${P202_TEST_DB_NAME:-p202_integration_test}
read -r -a PHPUNIT <<< "${P202_PHPUNIT:-vendor/bin/phpunit}"
LOG_DIR=${P202_IT_LOG_DIR:-$(mktemp -d)}
mkdir -p "$LOG_DIR"

# shellcheck source=tests/live/guard.sh
. "$ROOT/tests/live/guard.sh"
p202_require_scratch_db "$P202_TEST_DB_NAME" || exit 2

mysql_q() {
    MYSQL_PWD="$P202_TEST_DB_PASS" mysql -h "$P202_TEST_DB_HOST" --protocol=TCP -P "$P202_TEST_DB_PORT" \
        -u "$P202_TEST_DB_USER" "$@"
}

# connect.php needs a 202-config.php to exist (the suites that bootstrap it
# set the connection globals themselves). Write one only if there is none,
# and remove only the one written here.
WROTE_CONFIG=
if [ ! -f 202-config.php ]; then
    esc() { printf '%s' "$1" | sed -e 's/[\\/&]/\\&/g'; }
    sed -e "s/putyourdbnamehere/$(esc "$P202_TEST_DB_NAME")/" \
        -e "s/usernamehere/$(esc "$P202_TEST_DB_USER")/" \
        -e "s/yourpasswordhere/$(esc "$P202_TEST_DB_PASS")/" \
        -e "s/localhosthere/$(esc "$P202_TEST_DB_HOST:$P202_TEST_DB_PORT")/" \
        -e "s/localhostreplica/$(esc "$P202_TEST_DB_HOST:$P202_TEST_DB_PORT")/" \
        -e "s/localhostmemcache/127.0.0.1/" \
        202-config-sample.php > 202-config.php || exit 2
    WROTE_CONFIG=1
fi
cleanup() { [ -n "$WROTE_CONFIG" ] && rm -f "$ROOT/202-config.php"; }
trap cleanup EXIT

mapfile -t SUITES < <(php tests/integration-suites.php)
# A selector that broke and found nothing must not read as "nothing failed".
if [ "${#SUITES[@]}" -lt 30 ]; then
    echo "tests/integration-suites.php found ${#SUITES[@]} suites; expected at least 30" >&2
    exit 2
fi

# Deterministic weighted partitioning: every shard computes the same plan from
# the same suite list and weights file, so each unit runs exactly once. A unit
# is a suite, or one slice of a suite the file splits by PHPUnit --filter.
shard_count=${P202_IT_SHARD_COUNT:-1}
shard_index=${P202_IT_SHARD_INDEX:-0}
if [[ ! "$shard_count" =~ ^[1-9][0-9]{0,2}$ || ! "$shard_index" =~ ^(0|[1-9][0-9]{0,2})$ ]] \
    || (( shard_index >= shard_count || shard_count > ${#SUITES[@]} )); then
    echo "invalid integration shard index/count: $shard_index/$shard_count" >&2
    exit 2
fi

# Weights file lines: "<seconds> <suite>", or "<seconds> <suite> <regex>" for a
# slice, plus one "<seconds> <suite> *" for the tests no regex names.
WEIGHTS_FILE=${P202_IT_WEIGHTS_FILE:-$ROOT/tests/integration-suite-weights.txt}
DEFAULT_WEIGHT=${P202_IT_DEFAULT_WEIGHT:-3}
bad_weights() { echo "$WEIGHTS_FILE: $1" >&2; exit 2; }
declare -A IS_SUITE=() WHOLE=() REST=() SLICES=()
for suite in "${SUITES[@]}"; do IS_SUITE[$suite]=1; done
if [ -f "$WEIGHTS_FILE" ]; then
    while read -r weight suite filter || [ -n "$weight" ]; do
        case "$weight" in '' | '#'*) continue ;; esac
        [[ "$weight" =~ ^[1-9][0-9]{0,5}$ && -n "$suite" ]] || bad_weights "bad line: $weight $suite $filter"
        if [ -z "${IS_SUITE[$suite]:-}" ]; then
            echo "$WEIGHTS_FILE: $suite is not a selected suite; ignored" >&2
            continue
        fi
        if [ -z "$filter" ]; then
            WHOLE[$suite]=$weight
        elif [ "$filter" = '*' ]; then
            REST[$suite]=$weight
        else
            [[ "$filter" != */* ]] || bad_weights "$suite: a slice regex cannot contain '/'"
            SLICES[$suite]+="$weight"$'\t'"$filter"$'\n'
        fi
    done < "$WEIGHTS_FILE"
fi

UNIT_SUITE=() UNIT_FILTER=() UNIT_WEIGHT=() UNIT_LABEL=()
add_unit() { UNIT_SUITE+=("$1"); UNIT_FILTER+=("$2"); UNIT_WEIGHT+=("$3"); UNIT_LABEL+=("$4"); }
for suite in "${SUITES[@]}"; do
    if [ -n "${SLICES[$suite]:-}" ]; then
        [ -n "${REST[$suite]:-}" ] && [ -z "${WHOLE[$suite]:-}" ] \
            || bad_weights "$suite: slices need exactly one '*' line and no whole-suite line"
        slices=0 named=
        while IFS=$'\t' read -r weight filter; do
            [ -n "$filter" ] || continue
            slices=$((slices + 1))
            named+="${named:+|}(?:$filter)"
            add_unit "$suite" "/$filter/" "$weight" "slice $slices"
        done <<< "${SLICES[$suite]}"
        add_unit "$suite" "/^(?!.*(?:$named))/" "${REST[$suite]}" "the rest"
    elif [ -n "${REST[$suite]:-}" ]; then
        bad_weights "$suite: '*' needs at least one slice regex"
    else
        add_unit "$suite" "" "${WHOLE[$suite]:-$DEFAULT_WEIGHT}" ""
    fi
done

# Longest unit first onto the lightest shard (ties: the lower index), so the
# slices of one heavy suite land on different shards. Equal weights give the
# round-robin by position.
SHARD_LOAD=()
for ((s = 0; s < shard_count; s++)); do SHARD_LOAD[s]=0; done
UNIT_SHARD=()
while read -r _ u; do
    best=0
    for ((s = 1; s < shard_count; s++)); do
        if (( SHARD_LOAD[s] < SHARD_LOAD[best] )); then best=$s; fi
    done
    UNIT_SHARD[u]=$best
    SHARD_LOAD[best]=$(( SHARD_LOAD[best] + UNIT_WEIGHT[u] ))
done < <(for u in "${!UNIT_WEIGHT[@]}"; do printf '%d %d\n' "${UNIT_WEIGHT[$u]}" "$u"; done | sort -k1,1nr -k2,2n)

if [ "${P202_IT_PLAN_ONLY:-}" = 1 ]; then
    for u in "${!UNIT_SUITE[@]}"; do
        printf 'plan\t%d\t%d\t%s\t%s\n' "${UNIT_SHARD[$u]}" "${UNIT_WEIGHT[$u]}" "${UNIT_SUITE[$u]}" "${UNIT_FILTER[$u]}"
    done
    exit 0
fi

SHARD_UNITS=()
for u in "${!UNIT_SUITE[@]}"; do
    if (( UNIT_SHARD[u] == shard_index )); then
        SHARD_UNITS+=("$u")
    fi
done
printf 'Running shard %d/%d: %d of %d units (%d suites), planned %d s\n' "$((shard_index + 1))" "$shard_count" \
    "${#SHARD_UNITS[@]}" "${#UNIT_SUITE[@]}" "${#SUITES[@]}" "${SHARD_LOAD[shard_index]}"

FAILED=()
FAILED_LOGS=()
TOTAL_TESTS=0
SHARD_START=$SECONDS
for u in "${SHARD_UNITS[@]}"; do
    suite=${UNIT_SUITE[$u]}
    filter=()
    name=$suite
    log="$LOG_DIR/$(printf '%s' "$suite" | tr '/' '_')"
    if [ -n "${UNIT_FILTER[$u]}" ]; then
        filter=(--filter "${UNIT_FILTER[$u]}")
        name="$suite (${UNIT_LABEL[$u]})"
        log+=".$u"
    fi
    log+=.log
    mysql_q -e "DROP DATABASE IF EXISTS \`$P202_TEST_DB_NAME\`; CREATE DATABASE \`$P202_TEST_DB_NAME\`;" || {
        echo "could not recreate $P202_TEST_DB_NAME" >&2
        exit 2
    }
    started=$SECONDS
    "${PHPUNIT[@]}" --configuration phpunit.ci.xml --group integration --no-coverage ${filter[@]+"${filter[@]}"} "$suite" > "$log" 2>&1
    rc=$?
    took=$((SECONDS - started))
    ran=$(grep -oE '^OK \([0-9]+ tests?' "$log" | grep -oE '[0-9]+' | tail -1)
    if [ "$rc" -eq 0 ] && [ -n "$ran" ] && [ "$ran" -ge 1 ]; then
        printf 'PASS %-70s %s tests, %d s\n' "$name" "$ran" "$took"
        TOTAL_TESTS=$((TOTAL_TESTS + ran))
    else
        summary=$(grep -E '^(OK|Tests:|FAILURES|ERRORS|No tests executed)' "$log" | tail -1)
        printf 'FAIL %-70s exit %s after %d s: %s\n' "$name" "$rc" "$took" "${summary:-no PHPUnit summary (the run ended early)}"
        FAILED+=("$name")
        FAILED_LOGS+=("$log")
    fi
done

printf '\n%d suites, %d tests passed; %d suite(s) failed\n' \
    "$(( ${#SHARD_UNITS[@]} - ${#FAILED[@]} ))" "$TOTAL_TESTS" "${#FAILED[@]}"
printf 'shard %d/%d took %d s, planned %d s\n' "$((shard_index + 1))" "$shard_count" "$((SECONDS - SHARD_START))" "${SHARD_LOAD[shard_index]}"
for i in "${!FAILED[@]}"; do
    printf '\n----- %s -----\n' "${FAILED[$i]}"
    tail -40 "${FAILED_LOGS[$i]}"
done
[ "${#FAILED[@]}" -eq 0 ]
