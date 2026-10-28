#!/bin/bash
# Live pass: LTV webhooks sent by the minutely cron job (202-cronjobs/index.php),
# to a real https receiver, through the dispatcher's own guard and pinned POST.
#
#   1. a revenue event queues a delivery, and one cron run delivers it once,
#      signed with the webhook's secret; the next run sends nothing;
#   2. with the receiver stopped, the run records the failure on the delivery
#      (attempts 1, the curl error, the retry 2 minutes on);
#   3. with it back, the run after the retry is due delivers it (attempts 2);
#   4. a delivery queued 30 days ago is expired with its reason, never sent.
#
# The guard refuses loopback and any port but 443 and 8443, as it must, so the
# receiver (https on 127.0.0.1:P202_RECEIVER_PORT) is reached at a public-
# looking address put on lo for the pass (P202_RECEIVER_ADDR, 6.7.8.9 by
# default), port 8443, through socat; its certificate is signed by a CA made
# for the pass, which the cron trusts through curl.cainfo. So this needs root,
# openssl, socat and python3.
#
# Runs the cron job from this checkout (`php 202-cronjobs/index.php`, a default
# scheduler's line), so its 202-config.php must name the instance's database;
# each run waits for a new minute, the minutely tier's own schedule. Takes
# about four minutes. The pass's webhook is deleted on exit.
#
#   P202_BASE=http://127.0.0.1:8130 P202_DB=p202_qsb2_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   sudo -E bash tests/live/ltv-webhooks.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
ADDR=${P202_RECEIVER_ADDR:-6.7.8.9}
RECEIVER_PORT=${P202_RECEIVER_PORT:-8131}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass registers a webhook and records revenue through the API" >&2
    exit 2
fi
for tool in openssl socat python3 curl mysql ip; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
done
if [ "$(id -u)" != 0 ]; then
    echo "root is required: the receiver's address is put on lo for the pass" >&2
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

PASS=0; FAIL=0
WORK=$(mktemp -d)
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
json() { "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) ? ($d[$k] ?? null) : null; } echo is_bool($d) ? var_export($d, true) : (is_scalar($d) ? $d : json_encode($d));' "$1"; }

ADDED_ADDR=
RECEIVER_PID=
FRONT_PID=
WEBHOOK_ID=
cleanup() {
    [ -n "$RECEIVER_PID" ] && kill "$RECEIVER_PID" 2>/dev/null
    [ -n "$FRONT_PID" ] && kill "$FRONT_PID" 2>/dev/null
    [ -n "$ADDED_ADDR" ] && ip addr del "$ADDR/32" dev lo 2>/dev/null
    [ -n "$WEBHOOK_ID" ] && api -X DELETE "$BASE/api/v3/ltv/webhooks/$WEBHOOK_ID" >/dev/null
    rm -rf "$WORK"
}
trap cleanup EXIT

say "a receiver at https://$ADDR:8443, its certificate from a CA the cron trusts"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/ca.key" -out "$WORK/ca.pem" -days 1 \
    -subj '/CN=p202 live ltv-webhooks CA' 2>/dev/null || exit 2
openssl req -newkey rsa:2048 -nodes -keyout "$WORK/server.key" -out "$WORK/server.csr" -subj "/CN=$ADDR" 2>/dev/null || exit 2
printf 'subjectAltName=IP:%s\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' "$ADDR" > "$WORK/ext.cnf"
openssl x509 -req -in "$WORK/server.csr" -CA "$WORK/ca.pem" -CAkey "$WORK/ca.key" -CAcreateserial \
    -out "$WORK/server.pem" -days 1 -extfile "$WORK/ext.cnf" 2>/dev/null || exit 2
cat /etc/ssl/certs/ca-certificates.crt "$WORK/ca.pem" > "$WORK/bundle.pem"
if ! ip addr show dev lo | grep -q " $ADDR/32 "; then
    ip addr add "$ADDR/32" dev lo || exit 2
    ADDED_ADDR=1
fi
cat > "$WORK/receiver.py" <<'PY'
import json, ssl, sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
port, tls, log = int(sys.argv[1]), sys.argv[2], sys.argv[3]
class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0'))).decode()
        with open(log, 'a') as f:
            f.write(json.dumps({'path': self.path, 'delivery': self.headers.get('X-P202-Delivery'),
                                'signature': self.headers.get('X-P202-Signature'), 'body': body}) + '\n')
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'received')
    def log_message(self, *args):
        pass
server = ThreadingHTTPServer(('127.0.0.1', port), Handler)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(tls + '/server.pem', tls + '/server.key')
server.socket = ctx.wrap_socket(server.socket, server_side=True)
server.serve_forever()
PY
start_receiver() {
    python3 -I "$WORK/receiver.py" "$RECEIVER_PORT" "$WORK" "$WORK/received.log" >"$WORK/receiver.out" 2>&1 &
    RECEIVER_PID=$!
    for _ in $(seq 50); do
        (exec 3<>"/dev/tcp/127.0.0.1/$RECEIVER_PORT") 2>/dev/null && return 0
        sleep 0.1
    done
    echo "the receiver did not start: $(cat "$WORK/receiver.out")" >&2
    exit 2
}
start_receiver
socat "TCP-LISTEN:8443,bind=$ADDR,reuseaddr,fork" "TCP:127.0.0.1:$RECEIVER_PORT" 2>"$WORK/socat.out" &
FRONT_PID=$!
sleep 0.5
# The requests for this pass's webhook: another webhook on the instance may
# name the same address.
HOOK_PATH="/p202-live-$$"
mine() { grep "\"path\": \"$HOOK_PATH\"" "$WORK/received.log" 2>/dev/null; }
received() { mine | wc -l | tr -d ' '; }

LAST_MINUTE=
cron() {
    # The minutely tier runs once a minute, whoever starts it: run until a run
    # is the minute's, waiting for a new minute between tries.
    for _ in 1 2 3; do
        while [ "$(date +%Y%m%d%H%M)" = "$LAST_MINUTE" ]; do sleep 1; done
        LAST_MINUTE=$(date +%Y%m%d%H%M)
        (cd "$ROOT" && "$PHP" -d curl.cainfo="$WORK/bundle.pem" 202-cronjobs/index.php) > "$WORK/cron.out" 2>&1
        grep -q 'Processing Seconds Jobs' "$WORK/cron.out" && return 0
    done
    bad "the minutely tier did not run in three minutes: $(head -c 300 "$WORK/cron.out")"
}
delivery() { # $1 = delivery id, $2 = column
    Q "SELECT $2 FROM 202_ltv_webhook_deliveries WHERE delivery_id = $1"
}

say "1. a queued event is delivered once, signed"
HOOK=$(api -X POST "$BASE/api/v3/ltv/webhooks" -d "{\"url\":\"https://$ADDR:8443$HOOK_PATH\",\"events\":[\"revenue.recorded\"]}")
WEBHOOK_ID=$(printf '%s' "$HOOK" | json data.webhook_id)
SECRET=$(printf '%s' "$HOOK" | json data.secret)
[ -n "$WEBHOOK_ID" ] && [ -n "$SECRET" ] || { echo "the webhook was not created: $HOOK" >&2; exit 2; }
REF="live-ltv-$$-$(date +%s)"
api -X POST "$BASE/api/v3/ltv/revenue" -d "{\"customer_ref\":\"$REF\",\"amount\":49,\"idempotency_key\":\"$REF-1\"}" >/dev/null
FIRST=$(Q "SELECT MAX(delivery_id) FROM 202_ltv_webhook_deliveries WHERE webhook_id = $WEBHOOK_ID")
eq "$(delivery "$FIRST" "CONCAT(status, '/', attempts)")" 'pending/0' 'POST /ltv/revenue queued a delivery'
cron
eq "$(received)" 1 'the receiver got one request'
eq "$(delivery "$FIRST" "CONCAT(status, '/', attempts, '/', last_status_code)")" 'delivered/1/200' 'recorded as delivered'
SIGNED=$(mine | tail -1 |"$PHP" -r '$r = json_decode(stream_get_contents(STDIN), true); echo hash_equals("sha256=" . hash_hmac("sha256", $r["body"], $argv[1]), (string) $r["signature"]) && $r["delivery"] === $argv[2] ? "yes" : "no";' "$SECRET" "$FIRST")
eq "$SIGNED" yes 'signed with the secret, X-P202-Delivery its id'
cron
eq "$(received)" 1 'the next run sends nothing again'

say "2. the receiver down: the failure is recorded with its retry"
kill "$RECEIVER_PID"; wait "$RECEIVER_PID" 2>/dev/null; RECEIVER_PID=
api -X POST "$BASE/api/v3/ltv/revenue" -d "{\"customer_ref\":\"$REF\",\"amount\":19,\"idempotency_key\":\"$REF-2\"}" >/dev/null
SECOND=$(Q "SELECT MAX(delivery_id) FROM 202_ltv_webhook_deliveries WHERE webhook_id = $WEBHOOK_ID")
cron
eq "$(delivery "$SECOND" "CONCAT(status, '/', attempts)")" 'pending/1' 'pending, one attempt'
case "$(delivery "$SECOND" last_response_body)" in curl:*) ok 'the curl error is its last response' ;; *) bad "last response: $(delivery "$SECOND" last_response_body)" ;; esac
RETRY_IN=$(( $(delivery "$SECOND" next_attempt_at) - $(delivery "$SECOND" updated_at) ))
eq "$RETRY_IN" 120 'the retry is 2 minutes on'
DUE=$(api "$BASE/api/v3/ltv/webhooks/$WEBHOOK_ID/deliveries?status=pending" | json data.0.next_attempt_at)
eq "$DUE" "$(delivery "$SECOND" next_attempt_at)" 'GET /ltv/webhooks/{id}/deliveries shows the retry'

say "3. the receiver back: the retry is delivered"
start_receiver
while [ "$(date +%s)" -le "$(delivery "$SECOND" next_attempt_at)" ]; do sleep 2; done
cron
eq "$(delivery "$SECOND" "CONCAT(status, '/', attempts)")" 'delivered/2' 'delivered on the second attempt'
eq "$(grep -c "\"delivery\": \"$SECOND\"" "$WORK/received.log")" 1 'and received once'

say "4. a delivery queued 30 days ago expires unsent"
Q "INSERT INTO 202_ltv_webhook_deliveries (webhook_id, user_id, event_name, payload, status, attempts,
     next_attempt_at, created_at, updated_at)
   SELECT webhook_id, user_id, 'revenue.recorded', '{}', 'pending', 0, UNIX_TIMESTAMP() - 30 * 86400,
     UNIX_TIMESTAMP() - 30 * 86400, UNIX_TIMESTAMP() - 30 * 86400 FROM 202_ltv_webhooks WHERE webhook_id = $WEBHOOK_ID"
OLD=$(Q "SELECT MAX(delivery_id) FROM 202_ltv_webhook_deliveries WHERE webhook_id = $WEBHOOK_ID")
BEFORE=$(received)
cron
eq "$(delivery "$OLD" "CONCAT(status, '/', attempts)")" 'failed/0' 'failed, never tried'
case "$(delivery "$OLD" last_response_body)" in expired:*) ok 'its reason is in the log' ;; *) bad "last response: $(delivery "$OLD" last_response_body)" ;; esac
eq "$(received)" "$BEFORE" 'and nothing was sent'
eq "$(Q "SELECT status FROM 202_ltv_webhooks WHERE webhook_id = $WEBHOOK_ID")" active 'the webhook stays active'

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
