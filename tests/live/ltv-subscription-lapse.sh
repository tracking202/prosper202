#!/bin/bash
# Live pass: an LTV subscription no renewal reaches lapses on the cron job's
# hourly run (202-cronjobs/index.php, fetched as the installer's cron line
# fetches it), and MRR, churn and the customer rollups follow.
#
#   1. four subscriptions recorded through POST /ltv/subscriptions, active;
#   2. three period ends moved into the past in the database: 5 days ago
#      (past_due), 60 days ago (canceled 27 days ago, in churn's 90 days) and
#      400 days ago (canceled long ago, not in churn);
#   3. one cron run moves them, and GET /ltv/subscriptions, /ltv/mrr,
#      /ltv/summary and /ltv/customers/{id}, `p202 ltv mrr` and `p202 ltv
#      subscriptions`, and the PHP CLI's ltv:mrr all read the change; the
#      next run changes nothing;
#   4. a renewal brings the past_due one back, and a run leaves it active;
#   5. 202-cronjobs/ltv_maintenance.php refuses an option it does not know,
#      and runs.
#
# The hourly tier runs once an hour, whoever starts it, and only in a run of
# the minutely tier: each run here deletes this hour's and this minute's
# records from 202_cronjobs first, which is what the clock passing does. The
# sweep reads every account's subscriptions, so this wants a scratch instance.
#
#   P202_BASE=http://127.0.0.1:8240 P202_DB=p202_9ba0_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   P202_GO_CLI=/path/to/p202 bash tests/live/ltv-subscription-lapse.sh
#
# P202_GO_CLI (a built go-cli binary) and the PHP CLI (bin/p202) are run with
# a HOME of their own, configured for the instance; without P202_GO_CLI the
# Go checks are skipped and say so.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
GO_CLI=${P202_GO_CLI:-}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass records subscriptions through the API" >&2
    exit 2
fi
for tool in curl mysql; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
done
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

PASS=0; FAIL=0
WORK=$(mktemp -d)
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
json() { "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) ? ($d[$k] ?? null) : null; } echo is_bool($d) ? var_export($d, true) : (is_scalar($d) ? $d : json_encode($d));' "$1"; }
# The subscription with this external id in a GET /ltv/subscriptions answer: field $2.
subfield() { "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] ?? [] as $s) { if (($s["external_sub_id"] ?? null) === $argv[1]) { echo $s[$argv[2]] ?? "null"; } }' "$1" "$2"; }
num()  { "$PHP" -r 'echo round((float) $argv[1] - (float) $argv[2], 5);' "$1" "$2"; }

cleanup() {
    [ -n "${PREFIX:-}" ] && Q "DELETE FROM 202_subscriptions WHERE external_sub_id LIKE '$PREFIX%';
        DELETE FROM 202_customers WHERE primary_ref LIKE '$PREFIX%';
        DELETE FROM 202_customer_aliases WHERE alias_value LIKE '$PREFIX%'" >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT

cron() {
    Q "DELETE FROM 202_cronjobs WHERE cronjob_type IN ('secon', 'hour', 'hourl') AND cronjob_time >= UNIX_TIMESTAMP() - 3600"
    curl -s "$BASE/202-cronjobs/index.php" > "$WORK/cron.out"
    grep -q 'Processing Hourly Jobs' "$WORK/cron.out" || bad "the hourly tier did not run: $(head -c 300 "$WORK/cron.out")"
}
mrr() { api "$BASE/api/v3/ltv/mrr"; }
subs() { api "$BASE/api/v3/ltv/subscriptions?limit=500"; }
gocli() { HOME="$WORK/go-home" "$GO_CLI" "$@"; }
phpcli() { HOME="$WORK/php-home" "$PHP" "$ROOT/bin/p202" "$@"; }

NOW=$(date +%s)
PREFIX="lapse-$$-$NOW-"
DAY=86400

say "0. a run first, so nothing already lapsed on the instance moves under this pass"
cron
BEFORE=$(mrr)
MRR0=$(printf '%s' "$BEFORE" | json data.mrr)
ACTIVE0=$(printf '%s' "$BEFORE" | json data.active_subscriptions)
DUE0=$(printf '%s' "$BEFORE" | json data.past_due)
CANCELED0=$(printf '%s' "$BEFORE" | json data.canceled_total)
WINDOW0=$(printf '%s' "$BEFORE" | json data.churn_inputs.canceled_in_window)

say "1. four active subscriptions, recorded through the API"
declare -A CUST
for s in current:30 recent:20 quarter:10 old:40; do
    name=${s%%:*}; amount=${s#*:}
    OUT=$(api -X POST "$BASE/api/v3/ltv/subscriptions" -d "{\"external_sub_id\":\"$PREFIX$name\",\"customer_ref\":\"$PREFIX$name\",\"amount\":$amount,\"current_period_start\":$((NOW - 10 * DAY)),\"current_period_end\":$((NOW + 20 * DAY))}")
    CUST[$name]=$(printf '%s' "$OUT" | json data.customerId)
    [ -n "$(printf '%s' "$OUT" | json data.subscriptionId)" ] || { echo "the subscription was not recorded: $OUT" >&2; exit 2; }
done
AFTER=$(mrr)
eq "$(num "$(printf '%s' "$AFTER" | json data.mrr)" "$MRR0")" 100 'MRR counts all four'
eq "$(( $(printf '%s' "$AFTER" | json data.active_subscriptions) - ACTIVE0 ))" 4 'four more active'

say "2. three period ends moved into the past"
Q "UPDATE 202_subscriptions SET current_period_start = current_period_end - 40 * $DAY, current_period_end = CASE external_sub_id
     WHEN '${PREFIX}recent' THEN $((NOW - 5 * DAY)) WHEN '${PREFIX}quarter' THEN $((NOW - 60 * DAY))
     WHEN '${PREFIX}old' THEN $((NOW - 400 * DAY)) END
   WHERE external_sub_id IN ('${PREFIX}recent', '${PREFIX}quarter', '${PREFIX}old')"
eq "$(subs | subfield "${PREFIX}recent" status)" active 'still active until the cron runs'

say "3. one cron run"
cron
grep -q 'LTV maintenance: subscriptions 1 to past_due, 2 to canceled, 3 customer rollups refreshed' "$WORK/cron.out" \
    && ok 'the run says what it moved' || bad "the run's line: $(grep -o 'LTV maintenance[^<]*' "$WORK/cron.out")"
LIST=$(subs)
eq "$(printf '%s' "$LIST" | subfield "${PREFIX}current" status)" active 'the current one is active'
eq "$(printf '%s' "$LIST" | subfield "${PREFIX}recent" status)" past_due 'period and grace over: past_due'
eq "$(printf '%s' "$LIST" | subfield "${PREFIX}quarter" status)" canceled '60 days over: canceled'
eq "$(printf '%s' "$LIST" | subfield "${PREFIX}old" status)" canceled '400 days over: canceled'
eq "$(printf '%s' "$LIST" | subfield "${PREFIX}quarter" canceled_at)" "$((NOW - 60 * DAY + 33 * DAY))" 'canceled as of period end + 3 grace days + 30'
eq "$(printf '%s' "$LIST" | subfield "${PREFIX}old" canceled_at)" "$((NOW - 400 * DAY + 33 * DAY))" 'the old one too'
M=$(mrr)
eq "$(num "$(printf '%s' "$M" | json data.mrr)" "$MRR0")" 30 'GET /ltv/mrr: only the current one in MRR'
eq "$(( $(printf '%s' "$M" | json data.active_subscriptions) - ACTIVE0 ))" 1 'one more active than before'
eq "$(( $(printf '%s' "$M" | json data.past_due) - DUE0 ))" 1 'one more past_due'
eq "$(( $(printf '%s' "$M" | json data.canceled_total) - CANCELED0 ))" 2 'two more canceled'
eq "$(( $(printf '%s' "$M" | json data.churn_inputs.canceled_in_window) - WINDOW0 ))" 1 'churn counts the one canceled 27 days ago, not 367'
eq "$(api "$BASE/api/v3/ltv/summary?period=alltime" | json data.mrr)" "$(printf '%s' "$M" | json data.mrr)" 'GET /ltv/summary (the customer rollups) agrees'
for name in recent quarter old; do
    C=$(api "$BASE/api/v3/ltv/customers/${CUST[$name]}")
    eq "$(num "$(printf '%s' "$C" | json data.mrr)" 0)/$(printf '%s' "$C" | json data.active_subscription_count)" '0/0' "customer $name: no MRR, no active subscription"
done
C=$(api "$BASE/api/v3/ltv/customers/${CUST[current]}")
eq "$(num "$(printf '%s' "$C" | json data.mrr)" 0)/$(printf '%s' "$C" | json data.active_subscription_count)" '30/1' 'the current customer keeps its own'

if [ -n "$GO_CLI" ]; then
    mkdir -p "$WORK/go-home"
    gocli config set-url "$BASE" >/dev/null && gocli config set-key "$P202_API_KEY" >/dev/null
    eq "$(gocli ltv mrr --json | json data.mrr)" "$(printf '%s' "$M" | json data.mrr)" 'p202 ltv mrr reads the same MRR'
    eq "$(gocli ltv subscriptions --status past_due --json | subfield "${PREFIX}recent" status)" past_due 'p202 ltv subscriptions --status past_due lists it'
else
    echo '  (P202_GO_CLI not set: the Go CLI was not run)'
fi
mkdir -p "$WORK/php-home"
phpcli config:set-url "$BASE" >/dev/null && phpcli config:set-key "$P202_API_KEY" >/dev/null
eq "$(phpcli ltv:mrr --json 2>/dev/null | json data.past_due)" "$(printf '%s' "$M" | json data.past_due)" 'bin/p202 ltv:mrr reads the same past_due count'

cron
grep -q 'LTV maintenance: subscriptions' "$WORK/cron.out" && bad 'the next run moved something again' || ok 'the next run moves nothing'

say "4. a renewal brings the past_due one back"
api -X POST "$BASE/api/v3/ltv/subscriptions/${PREFIX}recent/events" -d "{\"event_type\":\"renewal\",\"transaction_id\":\"${PREFIX}renew\"}" >/dev/null
cron
eq "$(subs | subfield "${PREFIX}recent" status)" active 'active again, and the run leaves it so'
eq "$(num "$(mrr | json data.mrr)" "$MRR0")" 50 'back in MRR'

say "5. the job on its own"
(cd "$ROOT" && "$PHP" 202-cronjobs/ltv_maintenance.php --budget=abc) > "$WORK/job.out" 2>&1
eq "$?" 1 'a bad option exits 1'
grep -q 'unrecognised argument "--budget=abc"' "$WORK/job.out" && ok 'naming it' || bad "$(cat "$WORK/job.out")"
(cd "$ROOT" && "$PHP" 202-cronjobs/ltv_maintenance.php --budget=30) > "$WORK/job.out" 2>&1
eq "$?" 0 'a run exits 0'
grep -q '^ltv_maintenance: subscriptions 0 to past_due' "$WORK/job.out" && ok 'and prints its line' || bad "$(cat "$WORK/job.out")"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
