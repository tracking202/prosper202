#!/bin/bash
# Live pass: one run of 202-cronjobs/index.php at a time, for as long as it
# runs, whichever way it was started.
#
# The lock was a file's presence (file_exists(), then touch()), deleted as
# stale once it was ten minutes old: a run longer than that had a second run
# started beside it, and both ran the minute and hourly tiers; a run that died
# left the file, and every run for ten minutes after it was skipped. It is an
# flock() on 202-cronjobs/cron-run.lock now (Prosper202\Cron\CronLock). This
# pass holds a real run in its first tier (another session holds
# 202_cronjobs in LOCK TABLES … WRITE) and, while it waits there:
#
#   1. runs the job again on the command line, sends a signed-in page's ping
#      (a body-less POST to 202-cronjobs/) and fetches index.php as a
#      scheduler's curl and AutoCron do: each says the cron is already
#      running and does nothing, the command line exits 0 and logs why, and
#      no run is recorded; health.php says a run holds the lock
#   2. sets the lock file's time eleven minutes back (what the old lock read
#      as stale) and runs the job again: still already running
#   3. lets the held run go: it finishes its tiers, the one minute run, and is
#      the one recorded; health.php says nobody holds the lock
#   4. kills a held run with SIGKILL: the next run takes the lock at once
#   5. runs the job beside a cron.lock an earlier version left: it runs
#   6. puts a directory where the lock file goes: the run fails loudly (exit
#      1, the reason on stderr), runs nothing and records nothing; the file
#      is put back on any exit
#
# tests/Cron/CronLockTest and EveryCronRunTakesTheLockTest are the PHP half.
#
# Runs the job from this checkout, so its 202-config.php must name the
# instance's database, which must be a scratch one: the pass deletes the
# cron's own records (202_cronjobs, 202_cronjob_logs).
#
# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
PHP=${P202_PHP:-php}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass signs in as $P202_USER to read health.php." >&2
    exit 2
fi
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
if [ ! -f "$ROOT/202-config.php" ]; then
    echo "$ROOT/202-config.php is missing; this pass runs the checkout's cron job against the instance's database" >&2
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
JAR="$OUT/cookies"
LOCK="$ROOT/202-cronjobs/cron-run.lock"
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3 (it said: $(head -c 300 "$1"))"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3 (it said: $(head -c 300 "$1"))"; else ok "$3"; fi; }

LOCKERS=()
restore() {
    release_tiers
    for pid in "${LOCKERS[@]}"; do kill "$pid" 2>/dev/null; done
    if [ -d "$LOCK" ] && [ -f "$OUT/kept.lock" ]; then
        rmdir "$LOCK" && mv "$OUT/kept.lock" "$LOCK"
    fi
    rm -f "$ROOT/202-cronjobs/cron.lock"
    rm -rf "$OUT"
}
trap restore EXIT

# Holds 202_cronjobs from another session until release_tiers (at most two
# minutes): a run reaching its first tier waits there, holding the cron lock.
HOLD='SELECT SLEEP(119.5)'
hold_tiers() {
    Q "LOCK TABLES 202_cronjobs WRITE; $HOLD;" >/dev/null 2>&1 &
    LOCKERS+=($!)
    sleep 0.5
}
release_tiers() {
    local id
    for id in $(Q "SELECT ID FROM information_schema.PROCESSLIST WHERE DB = '$DB' AND INFO LIKE '$HOLD%'"); do
        Q "KILL $id" 2>/dev/null
    done
}
going() { # $1 = pid, $2 = what
    if kill -0 "$1" 2>/dev/null; then ok "$2"; else bad "$2 (it has ended: the pass cannot test what follows)"; fi
}
held() { "$PHP" -r 'require $argv[1]; var_export(Prosper202\Cron\CronLock::isHeld($argv[2]));' "$ROOT/vendor/autoload.php" "$LOCK"; }
fresh_minute() { Q "DELETE FROM 202_cronjobs WHERE cronjob_type = 'secon'"; }
minute_runs() { Q "SELECT COUNT(*) FROM 202_cronjobs WHERE cronjob_type = 'secon'"; }
recorded() { Q "SELECT IFNULL(MAX(last_execution_time), 0) FROM 202_cronjob_logs"; }
cron() { # $1 = name; runs the job from / as cron does; echoes the exit status
    (cd / && timeout 120 "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/$1.out" 2>"$OUT/$1.err")
    echo $?
}
health() { # $1 = name
    curl -sS -b "$JAR" "$BASE/202-cronjobs/health.php" -o "$OUT/$1.json"
    "$PHP" -r '$h = json_decode((string) file_get_contents($argv[1]), true); var_export($h["isLocked"] ?? "missing");' "$OUT/$1.json"
}

say "sign in (health.php is a signed-in page)"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
    --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
    --data-urlencode "user_pass=$P202_PASS" -o "$OUT/signed-in.html"
eq "$(health before)" "false" "health.php answers, signed in: nobody holds the lock"

say "1. a run held in its first tier keeps every other way in out"
Q "DELETE FROM 202_cronjob_logs"
fresh_minute
hold_tiers
(cd / && "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/held.out" 2>"$OUT/held.err") &
HELD=$!
sleep 1.5
going "$HELD" "the run is going, waiting in its first tier"
eq "$(held)" "true" "and holds the lock"
eq "$(cron second)" "0" "a second run on the command line exits 0"
has "$OUT/second.out" "Cron job already running" "and says the cron is already running"
has "$OUT/second.err" "Cron job already running: another run holds $LOCK; this run did nothing." "and logs why"
hasnt "$OUT/second.out" "Processing" "and runs no tier"
curl -sS -X POST --data '' "$BASE/202-cronjobs/?page_ping=1" -o "$OUT/ping.out"
has "$OUT/ping.out" "Cron job already running" "a signed-in page's ping is turned away"
curl -sS "$BASE/202-cronjobs/index.php" -o "$OUT/scheduler.out"
has "$OUT/scheduler.out" "Cron job already running" "a scheduler's curl, and AutoCron's request, are turned away"
eq "$(health during)" "true" "health.php says a run holds the lock"
eq "$(recorded)" "0" "no run is recorded"
eq "$(minute_runs)" "0" "no minute tier has run"

say "2. a run older than ten minutes is not stale"
touch -d '11 minutes ago' "$LOCK"
eq "$(( $(date +%s) - $(stat -c %Y "$LOCK") >= 600 ))" "1" "the lock file reads eleven minutes old"
going "$HELD" "the held run is still going"
eq "$(cron eleven)" "0" "a run eleven minutes into the held one exits 0"
has "$OUT/eleven.out" "Cron job already running" "and is still turned away"
hasnt "$OUT/eleven.out" "Processing" "and runs no tier"

say "3. the held run finishes, and is the one recorded"
going "$HELD" "the held run was going until now"
release_tiers
wait "$HELD"
eq "$?" "0" "the held run exits 0"
has "$OUT/held.out" "Processing Seconds Jobs" "it ran its tiers"
eq "$(minute_runs)" "1" "one minute tier ran"
if [ "$(recorded)" -gt 0 ]; then ok "the held run is recorded"; else bad "the held run is recorded"; fi
eq "$(held)" "false" "nobody holds the lock"
eq "$(health after)" "false" "health.php says so"

say "4. a killed run's lock is free at once"
fresh_minute
hold_tiers
(cd / && exec "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/killed.out" 2>"$OUT/killed.err") &
KILLED=$!
sleep 1
going "$KILLED" "a run is going, waiting in its first tier"
eq "$(held)" "true" "and holds the lock"
kill -9 "$KILLED"
wait "$KILLED" 2>/dev/null
eq "$(held)" "false" "killed, it holds it no longer"
(cd / && "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/next.out" 2>"$OUT/next.err") &
NEXT=$!
sleep 1
going "$NEXT" "the next run, started at once, is going"
eq "$(held)" "true" "and holds the lock"
release_tiers
wait "$NEXT"
eq "$?" "0" "and exits 0"
hasnt "$OUT/next.out" "already running" "it was not turned away"
has "$OUT/next.out" "Processing Seconds Jobs" "and ran its tiers"

say "5. a cron.lock an earlier version left does not hold the cron"
fresh_minute
touch "$ROOT/202-cronjobs/cron.lock"
eq "$(cron beside-old)" "0" "a run beside a fresh cron.lock exits 0"
has "$OUT/beside-old.out" "Processing Seconds Jobs" "and runs its tiers"
rm -f "$ROOT/202-cronjobs/cron.lock"

say "6. a lock file that cannot be made fails loudly, not as busy"
fresh_minute
BEFORE=$(recorded)
mv "$LOCK" "$OUT/kept.lock"
mkdir "$LOCK"
eq "$(cron unmade)" "1" "the run exits 1"
has "$OUT/unmade.err" "Prosper202 (index.php): the cron job could not make its lock file $LOCK, so it ran nothing" "the reason is on stderr"
hasnt "$OUT/unmade.out" "already running" "it is not read as another run"
eq "$(minute_runs)" "0" "no tier ran"
eq "$(recorded)" "$BEFORE" "and no run is recorded"
rmdir "$LOCK" && mv "$OUT/kept.lock" "$LOCK"
eq "$(cron restored)" "0" "with the file back, the next run exits 0"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
