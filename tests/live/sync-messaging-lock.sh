#!/bin/bash
# Live pass: one product-analytics delivery at a time, whichever job makes it.
#
# 202-cronjobs/sync-messaging.php kept its runs apart by a file's presence
# (messaging-sync.lock: file_exists(), then touch(), deleted as stale once ten
# minutes old), and nothing kept it apart from the hourly delivery of
# 202-cronjobs/index.php: two deliveries at once read the same waiting events
# and sent each twice. Both deliver through
# MessagingService::deliverPendingExclusive() now, under an flock() on
# 202-cronjobs/analytics-delivery.lock (Prosper202\Cron\CronLock). This pass
# serves a stand-in portal on loopback that records each /track request and,
# while held, answers it only when let go (at most eight seconds, inside the
# client's ten-second timeout), so a run is held mid-delivery. Then:
#
#   1. two runs of sync-messaging.php started together: one delivers, the
#      other says the delivery is already running, exits 0 and logs why; the
#      portal is asked once and each event is marked sent once
#   2. a run held mid-delivery with the lock file (and a messaging-sync.lock,
#      what the old lock read) set eleven minutes back: the next run is still
#      turned away (the old lock deleted the file and sent the events again)
#   3. index.php's hourly run while sync-messaging.php is held: it skips its
#      delivery, says so, and runs the rest of its tiers
#   4. sync-messaging.php while index.php's hourly delivery is held: turned
#      away
#   5. a held run killed with SIGKILL: the next run delivers at once
#   6. a directory where the lock file goes: sync-messaging.php exits 1 with
#      the reason and sends nothing; index.php's hourly run logs the same and
#      runs the rest of its tiers; the file is put back on any exit
#
# tests/Cron/OneAnalyticsDeliveryAtATimeIntegrationTest and
# EveryCronRunTakesTheLockTest are the PHP half.
#
# Runs the jobs from this checkout on the command line, as a crontab does, so
# its 202-config.php must name the instance's database, which must be a
# scratch one: the pass deletes every row of the messaging tables
# (202_messaging_events, _attributes, _sync) and the cron's minute and hourly
# records (202_cronjobs). Needs no web server and no licence.
#
# --- environment -------------------------------------------------------
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
PHP=${P202_PHP:-php}
PORT=${MESSAGING_STUB_PORT:-8788}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
if [ ! -f "$ROOT/202-config.php" ]; then
    echo "$ROOT/202-config.php is missing; this pass runs the checkout's jobs against the instance's database" >&2
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
STUB="$OUT/stub"
mkdir -p "$STUB"
LOCK="$ROOT/202-cronjobs/analytics-delivery.lock"
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3 (it said: $(head -c 400 "$1"))"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3 (it said: $(head -c 400 "$1"))"; else ok "$3"; fi; }

# The stand-in portal: records each request, then answers {"ok":true}, or
# while $STUB/hold exists waits (at most eight seconds) first.
cat > "$STUB/router.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.log', json_encode([
    'target' => $_SERVER['REQUEST_URI'],
    'body' => (string) file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);
$answerBy = microtime(true) + 8;
while (is_file(__DIR__ . '/hold') && microtime(true) < $answerBy) {
    usleep(20000);
    clearstatcache();
}
header('Content-Type: application/json');
echo '{"ok":true}';
PHP
PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "127.0.0.1:$PORT" "$STUB/router.php" >"$OUT/stub.log" 2>&1 &
STUB_PID=$!
export MESSAGING_API_URL="http://127.0.0.1:$PORT/api/v3/messaging"

restore() {
    rm -f "$STUB/hold"
    kill "$STUB_PID" 2>/dev/null
    if [ -d "$LOCK" ]; then
        rmdir "$LOCK" && { [ ! -f "$OUT/kept.lock" ] || mv "$OUT/kept.lock" "$LOCK"; }
    fi
    rm -f "$ROOT/202-cronjobs/messaging-sync.lock"
    rm -rf "$OUT"
}
trap restore EXIT
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
    "$PHP" -r 'usleep(100000);'
done
: > "$STUB/requests.log"

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_active = 1 AND user_deleted = 0 ORDER BY user_id LIMIT 1")
if [ -z "$USER_ID" ]; then
    echo "the instance has no active user to record analytics for" >&2
    exit 2
fi

hold()    { touch "$STUB/hold"; }
release() { rm -f "$STUB/hold"; }
asked()   { grep -c . "$STUB/requests.log"; }
wait_asked() { # $1 = how many requests, within five seconds
    for _ in $(seq 1 100); do
        [ "$(asked)" -ge "$1" ] && return 0
        "$PHP" -r 'usleep(50000);'
    done
    return 1
}
# Two waiting events of the instance's first user, and nothing else waiting.
seed() {
    : > "$STUB/requests.log"
    Q "DELETE FROM 202_messaging_events; DELETE FROM 202_messaging_attributes; DELETE FROM 202_messaging_sync;
       INSERT INTO 202_messaging_events (user_id, event_name, client_token, delivery_status)
       VALUES ($USER_ID, 'live_one', 'sml-one', 'pending'), ($USER_ID, 'live_two', 'sml-two', 'pending')"
}
events()  { Q "SELECT GROUP_CONCAT(CONCAT(delivery_status, '/', sync_attempts) ORDER BY id) FROM 202_messaging_events"; }
tokens()  { "$PHP" -r 'foreach (file($argv[1]) as $l) { $b = json_decode(json_decode($l, true)["body"], true); echo implode(",", array_column($b["events"] ?? [], "client_token")), ";"; }' "$STUB/requests.log"; }
held()    { "$PHP" -r 'require $argv[1]; var_export(Prosper202\Cron\CronLock::isHeld($argv[2]));' "$ROOT/vendor/autoload.php" "$LOCK"; }
hourly_due() { Q "DELETE FROM 202_cronjobs WHERE cronjob_type IN ('secon', 'hour', 'hourl')"; }
sync() { # $1 = name; sync-messaging.php from / as cron runs it; echoes the exit status
    (cd / && timeout 60 "$PHP" "$ROOT/202-cronjobs/sync-messaging.php" >"$OUT/$1.out" 2>"$OUT/$1.err")
    echo $?
}
cron() { # $1 = name; index.php the same way
    (cd / && timeout 60 "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/$1.out" 2>"$OUT/$1.err")
    echo $?
}
going() { # $1 = pid, $2 = what
    if kill -0 "$1" 2>/dev/null; then ok "$2"; else bad "$2 (it has ended: the pass cannot test what follows)"; fi
}
BUSY="Analytics delivery already running"
WHY="Analytics delivery already running: another run holds $LOCK; this run did nothing."
DELIVERED="Results: 1 delivered, 0 with more pending, 0 with nothing pending, 0 failed"

say "0. the day's tiers run first, with nothing to deliver"
Q "DELETE FROM 202_messaging_events; DELETE FROM 202_messaging_attributes; DELETE FROM 202_messaging_sync"
eq "$(cron first)" "0" "index.php runs"
eq "$(asked)" "0" "and asks the portal nothing"

say "1. two runs started together: one delivers"
seed
hold
(cd / && exec "$PHP" "$ROOT/202-cronjobs/sync-messaging.php" >"$OUT/a.out" 2>"$OUT/a.err") &
A=$!
(cd / && exec "$PHP" "$ROOT/202-cronjobs/sync-messaging.php" >"$OUT/b.out" 2>"$OUT/b.err") &
B=$!
wait_asked 1 || bad "a run reached the portal"
"$PHP" -r 'usleep(1500000);'
if kill -0 "$A" 2>/dev/null; then HOLDER=$A; OTHER=b; else HOLDER=$B; OTHER=a; fi
if kill -0 "$A" 2>/dev/null && kill -0 "$B" 2>/dev/null; then bad "one run ended while the other delivers (both are going)"; fi
wait "$([ "$OTHER" = a ] && echo "$A" || echo "$B")"
eq "$?" "0" "the other exits 0"
has "$OUT/$OTHER.out" "$BUSY" "and says the delivery is already running"
has "$OUT/$OTHER.err" "$WHY" "and logs why"
hasnt "$OUT/$OTHER.out" "Results:" "and delivers nothing"
eq "$(held)" "true" "the delivering run holds the lock"
eq "$(asked)" "1" "the portal has been asked once"
release
wait "$HOLDER"
eq "$?" "0" "the delivering run exits 0"
HOLDER_NAME=$([ "$OTHER" = a ] && echo b || echo a)
has "$OUT/$HOLDER_NAME.out" "$DELIVERED" "and delivered"
eq "$(asked)" "1" "the portal was asked once"
eq "$(tokens)" "sml-one,sml-two;" "with each event once"
eq "$(events)" "sent/0,sent/0" "each event is marked sent, no attempt spent"
eq "$(held)" "false" "nobody holds the lock"

say "2. a delivery past ten minutes is not stale"
seed
hold
(cd / && exec "$PHP" "$ROOT/202-cronjobs/sync-messaging.php" >"$OUT/long.out" 2>"$OUT/long.err") &
LONG=$!
wait_asked 1 || bad "the run reached the portal"
going "$LONG" "a run is delivering"
touch -d '11 minutes ago' "$LOCK"
if [ -e "$ROOT/202-cronjobs/messaging-sync.lock" ]; then touch -d '11 minutes ago' "$ROOT/202-cronjobs/messaging-sync.lock"; fi
eq "$(( $(date +%s) - $(stat -c %Y "$LOCK") >= 600 ))" "1" "the lock file reads eleven minutes old"
eq "$(sync eleven)" "0" "a run eleven minutes into it exits 0"
has "$OUT/eleven.out" "$BUSY" "and is still turned away"
hasnt "$OUT/eleven.out" "Results:" "and delivers nothing"
eq "$(asked)" "1" "the portal has been asked once"
going "$LONG" "the long run is still delivering"
release
wait "$LONG"
has "$OUT/long.out" "$DELIVERED" "the long run delivered"
eq "$(asked)" "1" "the portal was asked once"
eq "$(events)" "sent/0,sent/0" "each event is marked sent once"

say "3. index.php's hourly run beside a delivering sync-messaging.php"
seed
hourly_due
hold
(cd / && exec "$PHP" "$ROOT/202-cronjobs/sync-messaging.php" >"$OUT/own.out" 2>"$OUT/own.err") &
OWN=$!
wait_asked 1 || bad "sync-messaging.php reached the portal"
eq "$(cron hourly)" "0" "index.php exits 0"
has "$OUT/hourly.out" "Processing Hourly Jobs" "its hourly tier runs"
has "$OUT/hourly.out" "Analytics delivery: another run holds the lock; skipped" "and skips its delivery, saying so"
has "$OUT/hourly.out" "skipped<br>Done" "and finishes the tier"
eq "$(asked)" "1" "the portal has been asked once"
going "$OWN" "sync-messaging.php is still delivering"
release
wait "$OWN"
has "$OUT/own.out" "$DELIVERED" "sync-messaging.php delivered"
eq "$(asked)" "1" "the portal was asked once"
eq "$(events)" "sent/0,sent/0" "each event is marked sent once"

say "4. sync-messaging.php beside index.php's delivering hourly run"
seed
hourly_due
hold
(cd / && exec "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/tier.out" 2>"$OUT/tier.err") &
TIER=$!
wait_asked 1 || bad "index.php's hourly delivery reached the portal"
going "$TIER" "index.php is delivering"
eq "$(sync beside)" "0" "sync-messaging.php exits 0"
has "$OUT/beside.out" "$BUSY" "and is turned away"
hasnt "$OUT/beside.out" "Results:" "and delivers nothing"
eq "$(asked)" "1" "the portal has been asked once"
release
wait "$TIER"
eq "$?" "0" "index.php exits 0"
has "$OUT/tier.out" "Analytics delivery: 1 delivered" "and delivered"
eq "$(asked)" "1" "the portal was asked once"
eq "$(events)" "sent/0,sent/0" "each event is marked sent once"

say "5. a killed run's lock is free at once"
seed
hold
(cd / && exec "$PHP" "$ROOT/202-cronjobs/sync-messaging.php" >"$OUT/killed.out" 2>"$OUT/killed.err") &
KILLED=$!
wait_asked 1 || bad "the run reached the portal"
going "$KILLED" "a run is delivering"
kill -9 "$KILLED"
wait "$KILLED" 2>/dev/null
eq "$(held)" "false" "killed, it holds the lock no longer"
release
eq "$(sync next)" "0" "the next run, at once, exits 0"
hasnt "$OUT/next.out" "$BUSY" "it is not turned away"
has "$OUT/next.out" "$DELIVERED" "and delivers what the killed run never marked"
eq "$(events)" "sent/0,sent/0" "each event is marked sent"

say "6. a lock file that cannot be made fails loudly, not as busy"
seed
if [ -f "$LOCK" ]; then mv "$LOCK" "$OUT/kept.lock"; fi
mkdir "$LOCK"
eq "$(sync unmade)" "1" "sync-messaging.php exits 1"
has "$OUT/unmade.out" "Analytics delivery failed: the cron job could not make its lock file $LOCK, so it ran nothing" "and says why"
hasnt "$OUT/unmade.out" "$BUSY" "it is not read as another run"
hourly_due
eq "$(cron unmade-hourly)" "0" "index.php's hourly run exits 0"
has "$OUT/unmade-hourly.err" "Analytics delivery failed: the cron job could not make its lock file $LOCK" "and logs why its delivery failed"
has "$OUT/unmade-hourly.out" "Processing Hourly Jobs...Done" "and runs the rest of the tier"
eq "$(asked)" "0" "the portal was asked nothing"
eq "$(events)" "pending/0,pending/0" "each event waits, no attempt spent"
rmdir "$LOCK" && { [ ! -f "$OUT/kept.lock" ] || mv "$OUT/kept.lock" "$LOCK"; }
eq "$(sync restored)" "0" "with the file back, the next run exits 0"
has "$OUT/restored.out" "$DELIVERED" "and delivers"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
