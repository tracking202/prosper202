#!/bin/bash
# LTV webhooks are sent only to public addresses (J0F7):
#
#   - POST /ltv/webhooks refuses, as a 422 on url with nothing written, the
#     addresses the LTV webhooks' old guard approved: Azure's 168.63.129.16
#     and the IPv6 spellings of loopback and the metadata address
#     (::ffff:127.x, ::ffff:169.254.169.254, ::ffff:a9fe:a9fe,
#     64:ff9b::a9fe:a9fe);
#   - webhooks already stored with those URLs (an older version stored them),
#     and webhooks whose host name resolves to this machine, are not sent to
#     when a revenue event's deliveries are dispatched by the cron entry
#     (202-cronjobs/ltv_webhooks.php): a TCP listener on the internal address
#     hears no connection at all, and each delivery's log is "blocked: …"
#     with the reason;
#   - a public receiver by name (httpbin.org, where this machine reaches it
#     without a proxy; P202_PUBLIC_HOST, empty to skip) still receives its
#     delivery, signed.
#
# The internal listener is on P202_INTERNAL_ADDR (127.0.82.10, a loopback
# address of its own) port 8443, a port an LTV webhook is sent to, so the
# only thing between a delivery and it is the address check. The host names
# are /etc/hosts entries the caller adds (P202_INTERNAL_NAME naming the
# listener's address, P202_MAPPED_NAME naming ::ffff:<it>, which the C
# library hands back as the IPv4 address); either may be empty to skip. A
# name whose AAAA answer is a mapped address cannot be made here without a
# DNS server of one's own: tests/Ltv/DeliverySenderTest resolves one through
# a stub, with the real transport.
#
# Run from the repository root of the served instance (the cron reads its
# 202-config.php). Needs an API key (P202_API_KEY) and a scratch database;
# it removes the webhooks, deliveries and customer it adds.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
ADDR=${P202_INTERNAL_ADDR:-127.0.82.10}
INTERNAL_NAME=${P202_INTERNAL_NAME-}
MAPPED_NAME=${P202_MAPPED_NAME-}
PUBLIC_HOST=${P202_PUBLIC_HOST-httpbin.org}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY must be set: the pass writes over the API." >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
[ -f 202-cronjobs/ltv_webhooks.php ] && [ -f 202-config.php ] || { echo "run from the repository root of the served instance" >&2; exit 2; }

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
contains() { case "$1" in *"$2"*) ok "$3" ;; *) bad "$3 (got '$1')" ;; esac; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
js()   { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
[ -n "$USER_ID" ] || { echo "no user $P202_USER in $DB" >&2; exit 2; }
MARK=j0f7-ltv-webhook-guard
CUSTOMER_REF="$MARK-$$"
PIDS=()

cleanup() {
  for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; done
  local hooks cust
  hooks=$(Q "SELECT GROUP_CONCAT(webhook_id) FROM 202_ltv_webhooks WHERE user_id=$USER_ID AND webhook_url LIKE '%$MARK%'")
  if [ -n "$hooks" ] && [ "$hooks" != NULL ]; then
    Q "DELETE FROM 202_ltv_webhook_deliveries WHERE webhook_id IN ($hooks); DELETE FROM 202_ltv_webhooks WHERE webhook_id IN ($hooks)"
  fi
  cust=$(Q "SELECT GROUP_CONCAT(customer_id) FROM 202_customer_aliases WHERE user_id=$USER_ID AND alias_value LIKE '$MARK-%'" 2>/dev/null)
  if [ -n "$cust" ] && [ "$cust" != NULL ]; then
    for t in 202_revenue_line_items 202_revenue_events 202_customer_aliases 202_customers; do
      Q "DELETE FROM $t WHERE customer_id IN ($cust)" 2>/dev/null
    done
  fi
}
trap cleanup EXIT
cleanup

# ─────────────────────────────────────────────────────────────────────
say "an internal listener on $ADDR:8443 that logs every connection"
cat > "$OUT/listener.py" <<'PY'
import socket, sys
addr, log = sys.argv[1], sys.argv[2]
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind((addr, 8443))
s.listen(16)
open(log, 'w').close()
while True:
    c, peer = s.accept()
    with open(log, 'a') as f:
        f.write('connection from %s:%d\n' % peer)
    c.close()
PY
python3 "$OUT/listener.py" "$ADDR" "$OUT/listener.log" & PIDS+=($!)
for _ in $(seq 1 50); do [ -f "$OUT/listener.log" ] && break; sleep 0.1; done
python3 - "$ADDR" <<'PY'
import socket, sys
socket.create_connection((sys.argv[1], 8443), 2).close()
PY
sleep 0.3
eq "$(wc -l < "$OUT/listener.log" | tr -d ' ')" "1" "the listener sees a connection made to it (the probe works)"
: > "$OUT/listener.log"

# ─────────────────────────────────────────────────────────────────────
say "POST /ltv/webhooks refuses what the old guard approved"
REFUSED=(
  "https://168.63.129.16/$MARK|cloud metadata endpoint"
  "https://[::ffff:$ADDR]:8443/$MARK|loopback address (carried inside an IPv6 address)"
  "https://[::ffff:169.254.169.254]/latest/$MARK|link-local address, where cloud metadata services answer"
  "https://[::ffff:a9fe:a9fe]/$MARK|link-local"
  "https://[64:ff9b::a9fe:a9fe]/$MARK|link-local"
)
for entry in "${REFUSED[@]}"; do
  url=${entry%%|*}; why=${entry#*|}
  status=$(api -o "$OUT/create.json" -w '%{http_code}' -X POST "$BASE/api/v3/ltv/webhooks" -d "{\"url\":\"$url\",\"events\":[\"revenue.recorded\"]}")
  eq "$status" "422" "$url is refused"
  contains "$(js "d.get('field_errors',{}).get('url','')" < "$OUT/create.json")" "$why" "  on url: $why"
done
eq "$(Q "SELECT COUNT(*) FROM 202_ltv_webhooks WHERE user_id=$USER_ID AND webhook_url LIKE '%$MARK%'")" "0" "nothing was written"

# ─────────────────────────────────────────────────────────────────────
say "webhooks stored as an older version stored them, and by name"
NOW=$(date +%s)
for entry in "${REFUSED[@]}"; do
  url=${entry%%|*}
  Q "INSERT INTO 202_ltv_webhooks (user_id, webhook_url, webhook_secret, webhook_headers, subscribed_events, status, created_at, updated_at)
     VALUES ($USER_ID, '$url', 'pass-secret-pass-secret', NULL, 'revenue.recorded', 'active', $NOW, $NOW)"
done
for name in "$INTERNAL_NAME" "$MAPPED_NAME"; do
  [ -n "$name" ] || continue
  url="https://$name:8443/$MARK"
  status=$(api -o "$OUT/create.json" -w '%{http_code}' -X POST "$BASE/api/v3/ltv/webhooks" -d "{\"url\":\"$url\",\"events\":[\"revenue.recorded\"]}")
  eq "$status" "201" "$url is saved: a name is resolved when each delivery is sent, not when it is saved"
done
if [ -n "$PUBLIC_HOST" ]; then
  status=$(api -o "$OUT/public.json" -w '%{http_code}' -X POST "$BASE/api/v3/ltv/webhooks" -d "{\"url\":\"https://$PUBLIC_HOST/anything/$MARK\",\"events\":[\"revenue.recorded\"]}")
  eq "$status" "201" "https://$PUBLIC_HOST/anything/$MARK is saved"
  PUBLIC_SECRET=$(js "d['data']['secret']" < "$OUT/public.json")
fi

# ─────────────────────────────────────────────────────────────────────
say "a revenue event queues a delivery for each, and the cron entry sends them"
status=$(api -o "$OUT/revenue.json" -w '%{http_code}' -X POST "$BASE/api/v3/ltv/revenue" -d "{\"customer_ref\":\"$CUSTOMER_REF\",\"amount\":49,\"idempotency_key\":\"$CUSTOMER_REF\"}")
eq "$status" "201" "POST /ltv/revenue"
HOOKS=$(Q "SELECT COUNT(*) FROM 202_ltv_webhooks WHERE user_id=$USER_ID AND webhook_url LIKE '%$MARK%'")
eq "$(Q "SELECT COUNT(*) FROM 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w USING (webhook_id) WHERE w.webhook_url LIKE '%$MARK%' AND d.status='pending' AND d.attempts=0")" "$HOOKS" "one pending delivery per webhook"
"$PHP" 202-cronjobs/ltv_webhooks.php > "$OUT/cron.out" 2>&1
eq "$?" "0" "202-cronjobs/ltv_webhooks.php ran: $(tail -1 "$OUT/cron.out")"
sleep 0.5
eq "$(wc -l < "$OUT/listener.log" | tr -d ' ')" "0" "the internal listener heard no connection"

say "each refused delivery's log says why, and nothing was sent"
check_blocked() { # url-pattern, reason
  local row
  row=$(Q "SELECT CONCAT_WS('|', d.status, d.attempts, IFNULL(d.last_status_code, 'NULL'), d.last_response_body)
           FROM 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w USING (webhook_id)
           WHERE w.webhook_url = '$1' ORDER BY d.delivery_id DESC LIMIT 1")
  contains "$row" "pending|1|NULL|blocked: " "$1: attempted once, no status, blocked"
  contains "$row" "$2" "  $2"
}
for entry in "${REFUSED[@]}"; do
  check_blocked "${entry%%|*}" "${entry#*|}"
done
[ -n "$INTERNAL_NAME" ] && check_blocked "https://$INTERNAL_NAME:8443/$MARK" "$INTERNAL_NAME resolves to $ADDR, which is a loopback address"
[ -n "$MAPPED_NAME" ] && check_blocked "https://$MAPPED_NAME:8443/$MARK" "$MAPPED_NAME resolves to $ADDR, which is a loopback address"
first=$(Q "SELECT webhook_id FROM 202_ltv_webhooks WHERE webhook_url LIKE '%[::ffff:169.254.169.254]%$MARK' LIMIT 1")
api "$BASE/api/v3/ltv/webhooks/$first/deliveries" > "$OUT/log.json"
contains "$(js "d['data'][0]['last_response_body']" < "$OUT/log.json")" "blocked: ::ffff:169.254.169.254, which is a link-local address" "GET /ltv/webhooks/{id}/deliveries shows the refusal"

if [ -n "$PUBLIC_HOST" ]; then
  say "the public receiver received its delivery"
  row=$(Q "SELECT CONCAT_WS('|', d.status, d.attempts, d.last_status_code) FROM 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w USING (webhook_id)
           WHERE w.webhook_url = 'https://$PUBLIC_HOST/anything/$MARK'")
  eq "$row" "delivered|1|200" "https://$PUBLIC_HOST/anything/$MARK: delivered, 200"
  body=$(Q "SELECT d.last_response_body FROM 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w USING (webhook_id) WHERE w.webhook_url = 'https://$PUBLIC_HOST/anything/$MARK'")
  payload=$(Q "SELECT d.payload FROM 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w USING (webhook_id) WHERE w.webhook_url = 'https://$PUBLIC_HOST/anything/$MARK'")
  want="sha256=$(printf '%s' "$payload" | openssl dgst -sha256 -hmac "$PUBLIC_SECRET" -r | cut -d' ' -f1)"
  contains "$body" "\"X-P202-Signature\": \"$want\"" "  the receiver got the body signed with the webhook's secret"
  contains "$body" "\"X-P202-Event\": \"revenue.recorded\"" "  and its event"
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
