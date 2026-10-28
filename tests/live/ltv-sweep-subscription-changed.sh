#!/bin/bash
# Live pass: the cron job's hourly sweep (202-cronjobs/index.php, fetched as
# the installer's cron line fetches it) queues subscription.changed for each
# status it writes, as the API's change of that status does, and only for a
# change that took effect in the last 7 days.
#
#   1. a webhook subscribed to subscription.changed, and five subscriptions
#      recorded through POST /ltv/subscriptions: twins that lapse (period end
#      5 days ago), twins past their cancel point (35 days ago), and one that
#      lapsed 400 days ago;
#   2. one of each twin changed through the API: POST /ltv/subscriptions
#      with status past_due, and the cancel event;
#   3. one cron run sweeps the other three: its line says 2 announced and 1
#      not, and the deliveries queued for the webhook hold, for each status,
#      the same event and body as the API's twin, and nothing for the old one;
#   4. the next run announces nothing.
#
# The bodies are read from 202_ltv_webhook_deliveries: the API's delivery log
# leaves the payload out. The webhook's address answers nothing, so the
# minutely sender only records its attempts. The hourly tier runs once an
# hour: each run here deletes this hour's and this minute's records from
# 202_cronjobs first, as ltv-subscription-lapse.sh does. The sweep reads every
# account's subscriptions, so this wants a scratch instance.
#
#   P202_BASE=http://127.0.0.1:8240 P202_DB=p202_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/ltv-sweep-subscription-changed.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass records subscriptions and a webhook through the API" >&2
    exit 2
fi
for tool in curl mysql; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
done

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

HOOK=
cleanup() {
    [ -n "${PREFIX:-}" ] && Q "DELETE FROM 202_subscriptions WHERE external_sub_id LIKE '$PREFIX%';
        DELETE FROM 202_customers WHERE primary_ref LIKE '$PREFIX%';
        DELETE FROM 202_customer_aliases WHERE alias_value LIKE '$PREFIX%'" >/dev/null 2>&1
    [ -n "$HOOK" ] && api -X DELETE "$BASE/api/v3/ltv/webhooks/$HOOK" >/dev/null
    rm -rf "$WORK"
}
trap cleanup EXIT

cron() {
    Q "DELETE FROM 202_cronjobs WHERE cronjob_type IN ('secon', 'hour', 'hourl') AND cronjob_time >= UNIX_TIMESTAMP() - 3600"
    curl -s "$BASE/202-cronjobs/index.php" > "$WORK/cron.out"
    grep -q 'Processing Hourly Jobs' "$WORK/cron.out" || bad "the hourly tier did not run: $(head -c 300 "$WORK/cron.out")"
}
# The body queued for the webhook about subscription $1, as "event action keys:types" (one line per delivery).
queued() {
    Q "SELECT payload FROM 202_ltv_webhook_deliveries WHERE webhook_id = $HOOK
        AND JSON_EXTRACT(payload, '\$.data.subscription_id') = $1 ORDER BY delivery_id" \
        | "$PHP" -r 'while (($l = fgets(STDIN)) !== false) { $b = json_decode($l, true); $d = $b["data"]; echo $b["event"], " ", $d["action"], " ", implode(",", array_map(fn ($k, $v) => $k . ":" . get_debug_type($v), array_keys($d), $d)), " ", implode(",", array_keys($b)), "\n"; }'
}

NOW=$(date +%s)
PREFIX="sweepchg-$$-$NOW-"
DAY=86400

say "0. a run first, so nothing already lapsed on the instance moves under this pass"
cron

say "1. a webhook for subscription.changed, and five subscriptions through the API"
HOOK=$(api -X POST "$BASE/api/v3/ltv/webhooks" -d '{"url":"https://hooks.example/subscriptions","events":["subscription.changed"]}' | json data.webhook_id)
[[ "$HOOK" =~ ^[1-9][0-9]*$ ]] || { echo "the API did not make the webhook (got '$HOOK')" >&2; exit 2; }
declare -A SUB END
END[sweep_due]=$((NOW - 5 * DAY)); END[api_due]=$((NOW - 5 * DAY))
END[sweep_cancel]=$((NOW - 35 * DAY)); END[api_cancel]=$((NOW - 35 * DAY))
END[sweep_old]=$((NOW - 400 * DAY))
for name in sweep_due api_due sweep_cancel api_cancel sweep_old; do
    OUT=$(api -X POST "$BASE/api/v3/ltv/subscriptions" -d "{\"external_sub_id\":\"$PREFIX$name\",\"customer_ref\":\"$PREFIX$name\",\"amount\":10,\"current_period_start\":$((NOW - 10 * DAY)),\"current_period_end\":$((NOW + 20 * DAY))}")
    SUB[$name]=$(printf '%s' "$OUT" | json data.subscriptionId)
    [[ "${SUB[$name]}" =~ ^[1-9][0-9]*$ ]] || { echo "the subscription was not recorded: $OUT" >&2; exit 2; }
    Q "UPDATE 202_subscriptions SET current_period_start = ${END[$name]} - 30 * $DAY, current_period_end = ${END[$name]}
        WHERE subscription_id = ${SUB[$name]}"
done
Q "DELETE FROM 202_ltv_webhook_deliveries WHERE webhook_id = $HOOK"
ok "the period ends moved into the past; the recording's own events set aside"

say "2. one of each twin changed through the API"
OUT=$(api -X POST "$BASE/api/v3/ltv/subscriptions" -d "{\"external_sub_id\":\"${PREFIX}api_due\",\"customer_ref\":\"${PREFIX}api_due\",\"amount\":10,\"status\":\"past_due\",\"current_period_start\":$((END[api_due] - 30 * DAY)),\"current_period_end\":${END[api_due]}}")
eq "$(printf '%s' "$OUT" | json data.subscriptionId)" "${SUB[api_due]}" 'POST /ltv/subscriptions with status past_due'
OUT=$(api -X POST "$BASE/api/v3/ltv/subscriptions/${PREFIX}api_cancel/events" -d '{"event_type":"cancel"}')
eq "$(printf '%s' "$OUT" | json data.changed)" true 'the cancel event'

say "3. one cron run sweeps the other three"
cron
grep -q 'LTV maintenance: subscriptions 1 to past_due, 2 to canceled, 3 customer rollups refreshed, 2 announced as subscription.changed (1 that took effect more than 7 days ago not announced)' "$WORK/cron.out" \
    && ok 'the run says what it moved, announced and did not' || bad "the run's line: $(grep -o 'LTV maintenance[^<]*' "$WORK/cron.out")"
eq "$(Q "SELECT GROUP_CONCAT(status ORDER BY subscription_id) FROM 202_subscriptions WHERE subscription_id IN (${SUB[sweep_due]}, ${SUB[sweep_cancel]}, ${SUB[sweep_old]})")" \
    'past_due,canceled,canceled' 'the sweep moved them'
for pair in sweep_due:api_due:upsert sweep_cancel:api_cancel:cancel; do
    IFS=: read -r swept twin action <<< "$pair"
    S=$(queued "${SUB[$swept]}"); T=$(queued "${SUB[$twin]}")
    eq "$(printf '%s' "$S" | grep -c .)" 1 "$swept: one event queued"
    eq "$S" "$T" "$swept: the same event, action and body as the API's change of $twin"
    case "$S" in "subscription.changed $action "*) ok "$swept: subscription.changed, action $action" ;; *) bad "$swept: queued '$S'" ;; esac
done
eq "$(queued "${SUB[sweep_old]}")" '' 'the one that lapsed 400 days ago: no event'
echo "  (as queued: $(queued "${SUB[sweep_due]}"))"

say "4. the next run announces nothing"
BEFORE=$(Q "SELECT COUNT(*) FROM 202_ltv_webhook_deliveries WHERE webhook_id = $HOOK")
cron
eq "$(Q "SELECT COUNT(*) FROM 202_ltv_webhook_deliveries WHERE webhook_id = $HOOK")" "$BEFORE" 'no new delivery'

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
