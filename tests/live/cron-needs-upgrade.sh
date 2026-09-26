#!/bin/bash
# Live pass: a cron job against a database that needs an upgrade says so and
# exits non-zero; the web still redirects to the upgrade page.
#
# Before PR 13, 202-config/connect.php answered "the database needs an
# upgrade" by redirecting to 202-config/upgrade.php and die()ing. On the
# command line that is silence and exit status 0: the attribution worker, and
# every other job in 202-cronjobs/, looked like a job with nothing to do
# (measurement plan §8.1). This pass, over every entry point in
# 202-cronjobs/:
#
#   1. winds the stored version back so upgrade_needed() is true
#   2. runs each job from another directory, as cron does: exit status 1, the
#      reason on stderr naming both versions, nothing on stdout, and nothing
#      done (the outbox untouched, no cron lock left behind)
#   3. GETs one job over HTTP: still the 302 to the upgrade page
#   4. restores the version (also on any exit), and runs the attribution
#      worker, the sync worker and the data engine job again: exit 0, so the
#      refusal is about the version and not a job that always fails
#
# tests/Cron/CronEntryPointsFailLoudlyTest is the static half.
#
# Runs the jobs from this checkout, so its 202-config.php must name the
# instance's database (as app-core.sh and android-intake.sh require).
#
# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
PRIOR=${P202_PRIOR_VERSION:-1.9.75}
PHP=${P202_PHP:-php}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

CODE=$("$PHP" -r 'require $argv[1]; echo PROSPER202_VERSION;' "$ROOT/202-config/version.php")
if [ -z "$CODE" ]; then
    echo "could not read PROSPER202_VERSION from 202-config/version.php" >&2
    exit 2
fi
if [ ! -f "$ROOT/202-config.php" ]; then
    echo "$ROOT/202-config.php is missing; this pass runs the checkout's cron jobs against the instance's database" >&2
    exit 2
fi
CONFIGURED=$("$PHP" -r 'require $argv[1]; echo $dbname ?? "";' "$ROOT/202-config.php" 2>/dev/null)
if [ "$CONFIGURED" != "$DB" ]; then
    echo "this checkout's 202-config.php names database '$CONFIGURED', not P202_DB=$DB" >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }
# -----------------------------------------------------------------------

PASS=0; FAIL=0
OUT=$(mktemp -d)
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
empty(){ if [ ! -s "$1" ]; then ok "$2"; else bad "$2 (it printed: $(head -c 200 "$1"))"; fi; }

ORIGINAL=$(Q "SELECT version FROM 202_version")
if [ "$ORIGINAL" != "$CODE" ]; then
    echo "the instance is at $ORIGINAL, not the code version $CODE; upgrade it first" >&2
    exit 2
fi
restore() { Q "UPDATE 202_version SET version='$CODE'"; rm -rf "$OUT"; }
trap restore EXIT

# One job, from a directory that is not 202-cronjobs/, as cron runs it.
run_job() { # $1 = file; writes $OUT/<name>.{out,err}, echoes the exit status
    local name
    name=$(basename "$1" .php)
    (cd / && timeout 120 "$PHP" "$1" > "$OUT/$name.out" 2> "$OUT/$name.err")
    echo $?
}

say "wind the stored version back to $PRIOR (code is $CODE)"
Q "UPDATE 202_version SET version='$PRIOR'"
eq "$(Q "SELECT version FROM 202_version")" "$PRIOR" "stored version is $PRIOR, so upgrade_needed() is true"
PENDING=$(Q "SELECT COUNT(*) FROM 202_attribution_pending")
rm -f "$ROOT/202-cronjobs/cron.lock"

JOBS=("$ROOT"/202-cronjobs/*.php)
if [ "${#JOBS[@]}" -ge 10 ]; then ok "${#JOBS[@]} cron entry points found"; else bad "cron entry points found (${#JOBS[@]})"; fi
for job in "${JOBS[@]}"; do
    name=$(basename "$job" .php)
    say "$name.php against a database that needs an upgrade"
    STATUS=$(run_job "$job")
    eq "$STATUS" 1 "exits 1"
    has "$OUT/$name.err" "the database needs an upgrade: its schema is version $PRIOR and this code is version $CODE" "says why on stderr, naming both versions"
    has "$OUT/$name.err" "202-config/upgrade.php" "and names the page that upgrades it"
    empty "$OUT/$name.out" "prints nothing on stdout: nothing ran"
done

say "nothing was done"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_pending")" "$PENDING" "the attribution outbox is as it was"
if [ ! -e "$ROOT/202-cronjobs/cron.lock" ]; then ok "the minutely cron left no lock behind"; else bad "the minutely cron left no lock behind"; fi
eq "$(Q "SELECT version FROM 202_version")" "$PRIOR" "the version is still $PRIOR"

say "over HTTP the job still redirects to the upgrade page"
LOC=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/202-cronjobs/dej.php")
case "$LOC" in
    "302 "*/202-config/upgrade.php) ok "302 to 202-config/upgrade.php ($LOC)" ;;
    *) bad "302 to 202-config/upgrade.php (got '$LOC')" ;;
esac

say "with the version restored, the jobs run"
Q "UPDATE 202_version SET version='$CODE'"
for name in attribution-worker sync-worker dej; do
    STATUS=$(run_job "$ROOT/202-cronjobs/$name.php")
    eq "$STATUS" 0 "$name.php exits 0"
    if grep -qF "needs an upgrade" "$OUT/$name.err"; then bad "$name.php does not report an upgrade"; else ok "$name.php does not report an upgrade"; fi
done
has "$OUT/attribution-worker.out" "attribution-worker: processed" "the worker reports its run"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
