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

FAILED=()
TOTAL_TESTS=0
for suite in "${SUITES[@]}"; do
    mysql_q -e "DROP DATABASE IF EXISTS \`$P202_TEST_DB_NAME\`; CREATE DATABASE \`$P202_TEST_DB_NAME\`;" || {
        echo "could not recreate $P202_TEST_DB_NAME" >&2
        exit 2
    }
    log="$LOG_DIR/$(printf '%s' "$suite" | tr '/' '_').log"
    "${PHPUNIT[@]}" --configuration phpunit.ci.xml --group integration --no-coverage "$suite" > "$log" 2>&1
    rc=$?
    ran=$(grep -oE '^OK \([0-9]+ tests?' "$log" | grep -oE '[0-9]+' | tail -1)
    if [ "$rc" -eq 0 ] && [ -n "$ran" ] && [ "$ran" -ge 1 ]; then
        printf 'PASS %-70s %s tests\n' "$suite" "$ran"
        TOTAL_TESTS=$((TOTAL_TESTS + ran))
    else
        summary=$(grep -E '^(OK|Tests:|FAILURES|ERRORS|No tests executed)' "$log" | tail -1)
        printf 'FAIL %-70s exit %s: %s\n' "$suite" "$rc" "${summary:-no PHPUnit summary (the run ended early)}"
        FAILED+=("$suite")
    fi
done

printf '\n%d suites, %d tests passed; %d suite(s) failed\n' \
    "$(( ${#SUITES[@]} - ${#FAILED[@]} ))" "$TOTAL_TESTS" "${#FAILED[@]}"
for suite in "${FAILED[@]}"; do
    log="$LOG_DIR/$(printf '%s' "$suite" | tr '/' '_').log"
    printf '\n----- %s -----\n' "$suite"
    tail -40 "$log"
done
[ "${#FAILED[@]}" -eq 0 ]
