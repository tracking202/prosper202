#!/bin/bash
# Live pass: an LTV webhook killed against a stopped receiver, revived, and
# sent what it missed, once.
#
#   1. a revenue event is delivered, signed; the receiver is stopped and the
#      next event's delivery fails 6 times, which marks the webhook dead;
#   2. two events queued while it is dead are held by the dispatcher, never
#      tried, and the delivery log says so ("held: …");
#   3. revived (PUT /ltv/webhooks/{id}), it is active with its id and secret,
#      and the next run of the cron job (202-cronjobs/index.php, the
#      installer's line) sends the three it missed, oldest first, each once;
#      the delivered one is not sent again, and the run after sends nothing;
#   4. killed again and revived through the PHP CLI (bin/p202
#      ltv:webhook:revive --no-requeue): active, nothing it missed is sent,
#      and a new event is;
#   5. killed again and revived from LTV Settings' Revive button (the POST its
#      script makes, with the page's own token), when P202_PASS is given.
#
# The retries' backoff (2 to 32 minutes) is the clock's: between the
# dispatcher's runs (202-cronjobs/ltv_webhooks.php) this pass sets the
# failing delivery due again, which is what the minutes passing do.
#
# The guard refuses loopback and any port but 443 and 8443, as it must, so the
# receiver (https on 127.0.0.1:P202_RECEIVER_PORT) is reached at a public-
# looking address put on lo (P202_RECEIVER_ADDR, 6.7.8.9 by default), port
# 8443, through socat; its certificate is signed by a CA made for the pass,
# which the cron trusts through curl.cainfo. So this needs root, openssl,
# socat and python3. The cron runs from this checkout, so its 202-config.php
# must name the instance's database. Deletes its webhook on exit.
#
#   P202_BASE=http://127.0.0.1:8240 P202_DB=p202_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... [P202_PASS=...] \
#   sudo -E bash tests/live/ltv-webhook-revive.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
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
    -subj '/CN=p202 live ltv-webhook-revive CA' 2>/dev/null || exit 2
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
stop_receiver() {
    kill "$RECEIVER_PID"; wait "$RECEIVER_PID" 2>/dev/null; RECEIVER_PID=
}
start_receiver
socat "TCP-LISTEN:8443,bind=$ADDR,reuseaddr,fork" "TCP:127.0.0.1:$RECEIVER_PORT" 2>"$WORK/socat.out" &
FRONT_PID=$!
sleep 0.5
HOOK_PATH="/p202-live-revive-$$"
mine() { grep "\"path\": \"$HOOK_PATH\"" "$WORK/received.log" 2>/dev/null; }
# The delivery ids the receiver got, in order, one per line.
received() { mine | "$PHP" -r 'while (($l = fgets(STDIN)) !== false) { echo json_decode($l, true)["delivery"], "\n"; }'; }
times_received() { received | grep -cx "$1"; }

dispatch() { (cd "$ROOT" && "$PHP" -d curl.cainfo="$WORK/bundle.pem" 202-cronjobs/ltv_webhooks.php) > "$WORK/dispatch.out" 2>&1; }
cron() {
    # The installer's cron line, made due now: this minute's record goes, as
    # the clock passing would take it.
    Q "DELETE FROM 202_cronjobs WHERE cronjob_type = 'secon' AND cronjob_time >= UNIX_TIMESTAMP() - 120"
    (cd "$ROOT" && "$PHP" -d curl.cainfo="$WORK/bundle.pem" 202-cronjobs/index.php) > "$WORK/cron.out" 2>&1
    grep -q 'Processing Seconds Jobs' "$WORK/cron.out" || bad "the minutely tier did not run: $(head -c 300 "$WORK/cron.out")"
}
delivery() { Q "SELECT $2 FROM 202_ltv_webhook_deliveries WHERE delivery_id = $1"; }
hook_status() { Q "SELECT status FROM 202_ltv_webhooks WHERE webhook_id = $WEBHOOK_ID"; }
last_delivery() { Q "SELECT MAX(delivery_id) FROM 202_ltv_webhook_deliveries WHERE webhook_id = $WEBHOOK_ID"; }
REF="live-revive-$$-$(date +%s)"
N=0
revenue() { N=$((N + 1)); api -X POST "$BASE/api/v3/ltv/revenue" -d "{\"customer_ref\":\"$REF\",\"amount\":$((10 + N)),\"idempotency_key\":\"$REF-$N\"}" >/dev/null; }
# A delivery the stopped receiver fails 6 times: the webhook is dead.
kill_webhook() {
    stop_receiver
    revenue
    KILLER=$(last_delivery)
    for _ in 1 2 3 4 5 6; do
        Q "UPDATE 202_ltv_webhook_deliveries SET next_attempt_at = UNIX_TIMESTAMP() WHERE delivery_id = $KILLER AND status = 'pending'"
        dispatch
    done
    eq "$(delivery "$KILLER" "CONCAT(status, '/', attempts)")" 'failed/6' 'the 6th failed attempt fails the delivery'
    eq "$(hook_status)" dead 'and marks the webhook dead'
    start_receiver
}

say "1. a delivered event, then a delivery that fails 6 times"
HOOK=$(api -X POST "$BASE/api/v3/ltv/webhooks" -d "{\"url\":\"https://$ADDR:8443$HOOK_PATH\",\"events\":[\"revenue.recorded\"]}")
WEBHOOK_ID=$(printf '%s' "$HOOK" | json data.webhook_id)
SECRET=$(printf '%s' "$HOOK" | json data.secret)
[ -n "$WEBHOOK_ID" ] && [ -n "$SECRET" ] || { echo "the webhook was not created: $HOOK" >&2; exit 2; }
revenue
DELIVERED=$(last_delivery)
dispatch
eq "$(delivery "$DELIVERED" status)" delivered 'the first event is delivered'
kill_webhook
case "$(delivery "$KILLER" last_response_body)" in curl:*) ok 'each failure is the curl error' ;; *) bad "last response: $(delivery "$KILLER" last_response_body)" ;; esac

say "2. while dead, events are held, never tried"
revenue; HELD1=$(last_delivery)
revenue; HELD2=$(last_delivery)
dispatch
grep -q '2 held for a dead webhook' "$WORK/dispatch.out" && ok 'the run says it held two' || bad "the run: $(cat "$WORK/dispatch.out")"
for id in "$HELD1" "$HELD2"; do
    eq "$(delivery "$id" "CONCAT(status, '/', attempts)")" 'failed/0' "delivery $id held, 0 attempts"
done
case "$(api "$BASE/api/v3/ltv/webhooks/$WEBHOOK_ID/deliveries?limit=1" | json data.0.last_response_body)" in
    held:*) ok 'GET /ltv/webhooks/{id}/deliveries shows "held: …"' ;;
    *) bad 'the delivery log does not say held' ;;
esac
eq "$(received | wc -l | tr -d ' ')" 1 'the receiver has the delivered one only'

say "3. revived through the API; the next cron run sends what it missed, once"
REVIVED=$(api -X PUT "$BASE/api/v3/ltv/webhooks/$WEBHOOK_ID" -d '{"status":"active"}')
eq "$(printf '%s' "$REVIVED" | json data.revived)/$(printf '%s' "$REVIVED" | json data.requeued)" 'true/3' 'PUT answers revived, 3 sent again'
eq "$(printf '%s' "$REVIVED" | json data.webhook_id)/$(hook_status)" "$WEBHOOK_ID/active" 'the same webhook, active'
eq "$(printf '%s' "$REVIVED" | json data.webhook_secret)" null 'the answer holds no secret'
cron
for id in "$KILLER" "$HELD1" "$HELD2"; do
    eq "$(times_received "$id")" 1 "delivery $id received once"
    eq "$(delivery "$id" status)" delivered "delivery $id recorded as delivered"
done
eq "$(received | tail -3 | tr '\n' ' ')" "$KILLER $HELD1 $HELD2 " 'oldest first'
eq "$(times_received "$DELIVERED")" 1 'the one delivered before is not sent again'
SIGNED=$(mine | tail -1 | "$PHP" -r '$r = json_decode(stream_get_contents(STDIN), true); echo hash_equals("sha256=" . hash_hmac("sha256", $r["body"], $argv[1]), (string) $r["signature"]) ? "yes" : "no";' "$SECRET")
eq "$SIGNED" yes 'signed with the secret it had'
BEFORE=$(received | wc -l | tr -d ' ')
cron
eq "$(received | wc -l | tr -d ' ')" "$BEFORE" 'the next run sends nothing'

say "4. killed again, revived through the PHP CLI without the missed ones"
kill_webhook
mkdir -p "$WORK/php-home"
HOME="$WORK/php-home" "$PHP" "$ROOT/bin/p202" config:set-url "$BASE" >/dev/null
HOME="$WORK/php-home" "$PHP" "$ROOT/bin/p202" config:set-key "$P202_API_KEY" >/dev/null
CLI=$(HOME="$WORK/php-home" "$PHP" "$ROOT/bin/p202" ltv:webhook:revive "$WEBHOOK_ID" --no-requeue --json 2>&1)
eq "$(printf '%s' "$CLI" | json data.revived)/$(printf '%s' "$CLI" | json data.requeued)" 'true/0' 'bin/p202 ltv:webhook:revive --no-requeue'
eq "$(hook_status)" active 'active again'
revenue; NEW=$(last_delivery)
cron
eq "$(times_received "$NEW")" 1 'a new event is sent'
eq "$(times_received "$KILLER")" 0 'the missed one is not'
eq "$(delivery "$KILLER" status)" failed 'and stays failed'

if [ -n "$P202_PASS" ]; then
    say "5. killed again, revived from LTV Settings' Revive button"
    kill_webhook
    JAR="$WORK/jar"
    curl -sS -b "$JAR" -c "$JAR" -o "$WORK/login.html" "$BASE/202-login.php"
    python3 "$HERE/form-body.py" "$WORK/login.html" user_name "user_name=$P202_USER" "user_pass=$P202_PASS" > "$WORK/login.body"
    curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-binary @"$WORK/login.body" \
        -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-login.php"
    curl -sS -b "$JAR" -c "$JAR" -o "$WORK/settings.html" "$BASE/tracking202/ajax/ltv_settings.php"
    grep -q "'revive_webhook', 'webhook_id', $WEBHOOK_ID," "$WORK/settings.html" \
        && ok 'the dead webhook'"'"'s row has a Revive button' || bad 'no Revive button on the row'
    TOKEN=$(grep -o "token: \"[0-9a-f]*\"" "$WORK/settings.html" | head -1 | sed 's/token: "//; s/"//')
    curl -sS -b "$JAR" -c "$JAR" -o "$WORK/revived.html" --data "action=revive_webhook&token=$TOKEN&webhook_id=$WEBHOOK_ID" \
        "$BASE/tracking202/ajax/ltv_settings.php"
    grep -q "Webhook #$WEBHOOK_ID is active again; 2 delivery(ies) it missed" "$WORK/revived.html" \
        && ok 'the page says it is active again, with the 2 it missed' || bad "the page's answer: $(grep -o 'Webhook #[^<]*' "$WORK/revived.html" | head -1)"
    eq "$(hook_status)" active 'active again'
    cron
    eq "$(delivery "$KILLER" status)" delivered 'what it missed is sent'
else
    echo '  (P202_PASS not set: the LTV Settings button was not pressed)'
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
