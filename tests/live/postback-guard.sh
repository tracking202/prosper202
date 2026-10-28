#!/bin/bash
# Traffic-source postbacks are sent only to public addresses (8PPJ):
#
#   - a Postback pixel aimed at a loopback listener, at 169.254.169.254, with
#     a password in it or with another scheme is refused when it is saved,
#     through the API and through Setup › Traffic Sources, naming the field,
#     with nothing written;
#   - one already stored (as an older version stored it) is not called when a
#     conversion fires it, through gpb.php and through upx.php, which answer
#     as they always have; its outbox row is `failed` at once with the reason
#     and is never tried again, by the request or by the worker, and the log
#     line names the pixel and the host, never the query;
#   - an allowed postback is called, and a redirect from it to the loopback
#     listener is not followed.
#
# What is "allowed" here: a listener on 127.0.0.2, admitted for the pass's
# duration by the operator's allowlist, which this pass writes into the
# instance's 202-config.php and takes out on exit:
#   define('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.2/32');
# 127.0.0.1, where the "internal" listener runs, stays refused. Where this
# machine reaches httpbin.org without a proxy (P202_PUBLIC_HOST, empty to
# skip), the pass also sends to a public host by name, pinned, and through
# two of its redirects: one to another public URL (followed) and one to the
# loopback listener (not).
#
# Run from the repository root of the served instance (the server and the
# worker read its 202-config.php). Needs the admin's password (P202_PASS),
# an API key (P202_API_KEY) and a scratch database; it removes what it adds.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
INSIDE_PORT=${P202_INSIDE_PORT:-8122}
NETWORK_PORT=${P202_NETWORK_PORT:-8121}
PUBLIC_HOST=${P202_PUBLIC_HOST-httpbin.org}
SERVER_LOG=${P202_SERVER_LOG:-}

if [ -z "$P202_PASS" ] || [ -z "$P202_API_KEY" ]; then
    echo "P202_PASS and P202_API_KEY must be set: the pass signs in as $P202_USER and writes over the API." >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
[ -f tracking202/static/gpb.php ] && [ -f 202-config.php ] || { echo "run from the repository root of the served instance" >&2; exit 2; }
if [ -z "$SERVER_LOG" ] || [ ! -f "$SERVER_LOG" ]; then
    echo "P202_SERVER_LOG must name the served instance's log (php -S writes error_log() there): the pass reads the refusal's log line" >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
JAR="$OUT/jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
js()   { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
token_of() { grep -oE 'name="token" value="[^"]+"' "$1" | head -1 | sed 's/.*value="//; s/"//'; }

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
[ -n "$USER_ID" ] || { echo "no user $P202_USER in $DB" >&2; exit 2; }
NOW=$(date +%s)
ACIP=970001
CLICK=970101
CLICK_UPX=970102
NAME=postback-guard-pass
CONFIG_BACKUP="$OUT/202-config.php.orig"
cp 202-config.php "$CONFIG_BACKUP"
PIDS=()
LOG_START=$(wc -l < "$SERVER_LOG")

cleanup() {
  for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; done
  cp "$CONFIG_BACKUP" 202-config.php
  local camp acc net
  camp=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP")
  acc=$(Q "SELECT GROUP_CONCAT(ppc_account_id) FROM 202_ppc_accounts WHERE ppc_account_name='$NAME'")
  net=$(Q "SELECT GROUP_CONCAT(ppc_network_id) FROM 202_ppc_networks WHERE ppc_network_name='$NAME'")
  Q "DELETE FROM 202_notification_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICK, $CLICK_UPX))"
  Q "DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICK, $CLICK_UPX)"
  for t in 202_clicks 202_clicks_spy 202_clicks_tracking 202_click_latest_visits; do
    Q "DELETE FROM $t WHERE click_id IN ($CLICK, $CLICK_UPX)" 2>/dev/null
  done
  [ -n "$acc" ] && [ "$acc" != NULL ] && Q "DELETE FROM 202_notification_correction_urls WHERE pixel_id IN (SELECT pixel_id FROM 202_ppc_account_pixels WHERE ppc_account_id IN ($acc)); DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN ($acc); DELETE FROM 202_ppc_accounts WHERE ppc_account_id IN ($acc)"
  [ -n "$net" ] && [ "$net" != NULL ] && Q "DELETE FROM 202_ppc_networks WHERE ppc_network_id IN ($net)"
  [ -n "$camp" ] && Q "DELETE FROM 202_aff_campaigns WHERE aff_campaign_id=$camp"
}
trap cleanup EXIT
cleanup

# ─────────────────────────────────────────────────────────────────────
say "listeners: the network on 127.0.0.2, something internal on 127.0.0.1"
cat > "$OUT/listener.py" <<'PY'
import http.server, sys
host, port, log, redirect = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self):
        with open(log, 'a') as f: f.write(self.path + '\n')
        if self.path.startswith('/redir') and redirect:
            self.send_response(302); self.send_header('Location', redirect); self.send_header('Content-Length', '0'); self.end_headers(); return
        self.send_response(200); self.send_header('Content-Length', '2'); self.end_headers(); self.wfile.write(b'ok')
http.server.ThreadingHTTPServer((host, port), H).serve_forever()
PY
: > "$OUT/inside.log"; : > "$OUT/network.log"
python3 "$OUT/listener.py" 127.0.0.1 "$INSIDE_PORT" "$OUT/inside.log" '' & PIDS+=($!)
python3 "$OUT/listener.py" 127.0.0.2 "$NETWORK_PORT" "$OUT/network.log" "http://127.0.0.1:$INSIDE_PORT/from-network?token=SECRET-D" & PIDS+=($!)
for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s --noproxy '*' -o /dev/null "http://127.0.0.2:$NETWORK_PORT/probe" && break; sleep 0.3; done
eq "$(curl -s --noproxy '*' "http://127.0.0.1:$INSIDE_PORT/probe")" ok "the internal listener answers anyone who calls it"
eq "$(curl -s --noproxy '*' "http://127.0.0.2:$NETWORK_PORT/probe")" ok "the network's listener answers"
: > "$OUT/inside.log"; : > "$OUT/network.log"
printf "\n// tests/live/postback-guard.sh: the pass's network (removed on exit)\nif (!defined('P202_WEBHOOK_ALLOW_NETWORKS')) { define('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.2/32'); }\n" >> 202-config.php
"$PHP" -l 202-config.php > /dev/null && ok "202-config.php allows 127.0.0.2/32 for the pass" || bad "202-config.php no longer parses"
"$PHP" -r 'usleep(3500000);' # the web server revalidates opcache every 2 s
PUBLIC=""
if [ -n "$PUBLIC_HOST" ] && [ "$(curl -s --noproxy '*' -m 8 -o /dev/null -w '%{http_code}' "http://$PUBLIC_HOST/get")" = 200 ]; then
  PUBLIC=$PUBLIC_HOST
  ok "$PUBLIC answers without a proxy: the public cases run"
else
  printf '  SKIP the public-host cases: %s is not reachable from here without a proxy\n' "${PUBLIC_HOST:-no host}"
fi

# ─────────────────────────────────────────────────────────────────────
say "saving: the API refuses what the sender would never call, naming the field"
eq "$(api -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v3/ppc-networks" -d "{\"ppc_network_name\":\"$NAME\"}")" 201 "a traffic source"
NET=$(Q "SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_name='$NAME'")
eq "$(api -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v3/ppc-accounts" -d "{\"ppc_account_name\":\"$NAME\",\"ppc_network_id\":$NET}")" 201 "and its account"
ACC=$(Q "SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name='$NAME'")
pixel() { # code [correction] -> status, body in $OUT/pixel.json
  local body
  body=$(python3 -c 'import json,sys; d={"pixel_type_id":4,"pixel_code":sys.argv[1]}; d.update({"correction_url":sys.argv[2]} if len(sys.argv)>2 else {}); print(json.dumps(d))' "$@")
  api -o "$OUT/pixel.json" -w '%{http_code}' -X POST "$BASE/api/v3/ppc-accounts/$ACC/pixels" -d "$body"
}
refused() { # field fragment code [correction]
  local field="$1" fragment="$2"; shift 2
  eq "$(pixel "$@")" 422 "POST $1${2:+ (correction $2)} answers 422"
  local said
  said=$(js "d.get('field_errors', {}).get('$field', '')" < "$OUT/pixel.json" 2>/dev/null)
  case "$said" in *"$fragment"*) ok "  naming $field: $said" ;; *) bad "  naming $field with '$fragment': $(cat "$OUT/pixel.json")" ;; esac
}
refused pixel_code "127.0.0.1, which is a loopback address" "http://127.0.0.1:$INSIDE_PORT/pb?s=[[subid]]"
refused pixel_code "169.254.169.254, which is a link-local address, where cloud metadata services answer" "http://169.254.169.254/latest/meta-data/"
refused pixel_code "user name or password" "http://user:pass@pb.example.com/pb"
refused pixel_code "http:// or https://" "gopher://pb.example.com/pb"
refused pixel_code "single-label" "http://localhost:$INSIDE_PORT/pb"
refused correction_url "private address" "https://pb.example.com/pb" "http://10.0.0.1/fix"
eq "$(Q "SELECT COUNT(*) FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")" 0 "nothing refused was written"

say "saving: Setup › Traffic Sources refuses it too"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$(token_of "$OUT/login.html")" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o /dev/null
PAGE="$OUT/edit.html"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$ACC" -o "$PAGE"
has "$PAGE" "value=\"$NAME\"" "the account's edit form is shown"
python3 "$HERE/form-body.py" "$PAGE" ppc_account_name "pixel_type_id[]=4" "pixel_code[]=http://169.254.169.254/latest/meta-data/?key=SECRET-PAGE" > "$OUT/.body" \
  || bad "the account form is on the edit page"
curl -sS -b "$JAR" -c "$JAR" -o "$OUT/page-refused.html" --data-binary "@$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' \
  "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$ACC"
has "$OUT/page-refused.html" "Pixel code (Postback URL): 169.254.169.254, which is a link-local address, where cloud metadata services answer. Postbacks are only sent to public addresses." \
  "the page says why, naming the field"
has "$OUT/page-refused.html" "<details class=\"p202-disclosure mb-3\" data-p202-remember=\"setup-traffic-sources-pixels\" open>" "with the pixels' disclosure open"
eq "$(Q "SELECT COUNT(*) FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")" 0 "and nothing was written"
python3 "$HERE/form-body.py" "$PAGE" ppc_account_name "pixel_type_id[]=4" "pixel_code[]=http://127.0.0.2:$NETWORK_PORT/pb?via=page&s=[[subid]]" > "$OUT/.body"
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-binary "@$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' \
  "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$ACC"
eq "$(Q "SELECT pixel_code FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")" "http://127.0.0.2:$NETWORK_PORT/pb?via=page&s=[[subid]]" \
  "an address the operator's allowlist admits is saved"
PIX_PAGE=$(Q "SELECT pixel_id FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")

# ─────────────────────────────────────────────────────────────────────
say "sending: what was stored before the check is refused when it fires"
# As an older version stored them: straight into the table.
Q "INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_type_id, pixel_code) VALUES
   ($ACC, 4, 'http://127.0.0.1:$INSIDE_PORT/direct?key=SECRET-A&s=[[subid]]'),
   ($ACC, 4, 'http://169.254.169.254/latest/meta-data/?key=SECRET-B&s=[[subid]]')"
PIX_A=$(Q "SELECT pixel_id FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC AND pixel_code LIKE 'http://127.0.0.1:%'")
PIX_B=$(Q "SELECT pixel_id FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC AND pixel_code LIKE 'http://169.254.%'")
eq "$(pixel "http://127.0.0.2:$NETWORK_PORT/redir?s=[[subid]]")" 201 "a postback to the network that redirects inward is saved (the redirect is not known yet)"
PIX_D=$(js "d['data']['pixel_id']" < "$OUT/pixel.json")
if [ -n "$PUBLIC" ]; then
  eq "$(pixel "http://$PUBLIC/get?p202=8ppj&s=[[subid]]")" 201 "a public postback by name"
  PIX_E=$(js "d['data']['pixel_id']" < "$OUT/pixel.json")
  eq "$(pixel "http://$PUBLIC/redirect-to?url=http%3A%2F%2F127.0.0.1%3A$INSIDE_PORT%2Ffrom-public%3Ftoken%3DSECRET-F&status_code=302")" 201 "a public host that redirects to the loopback listener"
  PIX_F=$(js "d['data']['pixel_id']" < "$OUT/pixel.json")
  eq "$(pixel "http://$PUBLIC/redirect-to?url=https%3A%2F%2F$PUBLIC%2Fget%3Fmoved%3D1&status_code=301")" 201 "a public host that moved to https"
  PIX_G=$(js "d['data']['pixel_id']" < "$OUT/pixel.json")
fi

mysql_q "$DB" <<SQL
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode)
  VALUES ($ACIP, $USER_ID, 0, '$NAME', 'http://example.test/', 3.00, $NOW, 3.00, 'replace');
SQL
CAMP=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP")
for c in $CLICK $CLICK_UPX; do
  mysql_q "$DB" -e "
    INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
      VALUES ($c, $USER_ID, $CAMP, 0, $ACC, 0.10, 3.00, 0, 0, 0, 0, $((NOW-600)), 0, 0);
    INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
      VALUES ($c, $USER_ID, $CAMP, 0, $ACC, 0.10, 3.00, 0, 0, 0, 0, $((NOW-600)));
    INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES ($c, 0, 0, 0, 0);"
done

STATUS=$(curl -sS -o "$OUT/gpb.out" -w '%{http_code}' "$BASE/tracking202/static/gpb.php?subid=$CLICK&amount=3&txid=PB-1")
eq "$STATUS" 202 "gpb.php answers 202, as it does for a click whose source has a postback pixel"
has "$OUT/gpb.out" '"msg":"Postback successful"' "with the answer it always gave"
CONV=$(Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$CLICK AND deleted=0")
[ -n "$CONV" ] && ok "the conversion was recorded" || bad "no conversion for click $CLICK"
row() { Q "SELECT CONCAT(status, '|', attempts, '|', COALESCE(last_error, '')) FROM 202_notification_pending WHERE conv_id=$CONV AND pixel_id=$1 AND kind='reached'"; }
eq "$(row "$PIX_A")" "failed|1|not sent: 127.0.0.1, which is a loopback address. Postbacks are only sent to public addresses." \
  "the loopback pixel is failed at once, with the reason"
eq "$(row "$PIX_B")" "failed|1|not sent: 169.254.169.254, which is a link-local address, where cloud metadata services answer. Postbacks are only sent to public addresses." \
  "the metadata pixel is failed at once, with the reason"
eq "$(row "$PIX_PAGE")" "sent|1|" "the network's postback was sent"
eq "$(row "$PIX_D")" "failed|1|redirected to http://127.0.0.1:$INSIDE_PORT, which was not followed: 127.0.0.1, which is a loopback address. Postbacks are only sent to public addresses." \
  "the network's redirect inward was not followed"
has "$OUT/network.log" "/pb?via=page&s=$CLICK" "the network heard the conversion"
has "$OUT/network.log" "/redir?s=$CLICK" "and was asked once at the URL that redirects"
if [ -n "$PUBLIC" ]; then
  eq "$(row "$PIX_E")" "sent|1|" "the public postback was sent, by name"
  eq "$(row "$PIX_F")" "failed|1|redirected to http://127.0.0.1:$INSIDE_PORT, which was not followed: 127.0.0.1, which is a loopback address. Postbacks are only sent to public addresses." \
    "the public host's redirect to the loopback listener was not followed"
  eq "$(row "$PIX_G")" "sent|1|" "and its redirect to its own https URL was followed"
fi
eq "$(wc -l < "$OUT/inside.log" | tr -d ' ')" 0 "the internal listener was never called"
tail -n +"$((LOG_START + 1))" "$SERVER_LOG" > "$OUT/server.log"
has "$OUT/server.log" "traffic source postback refused for pixel $PIX_A (notification " "the refusal is logged, naming the pixel"
has "$OUT/server.log" "http://127.0.0.1:$INSIDE_PORT): not sent: 127.0.0.1, which is a loopback address" "and the host"
has "$OUT/server.log" "traffic source postback refused for pixel $PIX_D (notification " "a refused redirect is logged too"
hasnt "$OUT/server.log" "SECRET" "no line carries a query string"

say "sending: a refusal is final, for the request path and for the worker"
curl -sS -o /dev/null "$BASE/tracking202/static/gpb.php?subid=$CLICK&amount=3&txid=PB-1"
eq "$(row "$PIX_A" | cut -d'|' -f1,2)" "failed|1" "a retried postback does not try it again"
Q "UPDATE 202_notification_pending SET next_attempt_at = 0 WHERE conv_id=$CONV"
"$PHP" 202-cronjobs/app-installs.php > "$OUT/worker.out" 2>&1
has "$OUT/worker.out" "notifications: 0 sent" "the worker finds nothing to send"
eq "$(row "$PIX_A" | cut -d'|' -f1,2)" "failed|1" "and does not try it either"
# Pending again, as a row queued before this version: the worker refuses it.
Q "UPDATE 202_notification_pending SET status='pending', attempts=0, last_error=NULL, next_attempt_at=0 WHERE conv_id=$CONV AND pixel_id=$PIX_B"
"$PHP" 202-cronjobs/app-installs.php > "$OUT/worker.out" 2>&1
has "$OUT/worker.out" "notifications: 0 sent, 0 retrying, 1 failed" "the worker refuses a queued one"
eq "$(row "$PIX_B" | cut -d'|' -f1,2)" "failed|1" "failed on its first attempt"
eq "$(wc -l < "$OUT/inside.log" | tr -d ' ')" 0 "the internal listener was still never called"

say "upx.php: the universal pixel answers as before"
STATUS=$(curl -sS -o "$OUT/upx.out" -w '%{http_code}' "$BASE/tracking202/static/upx.php?subid=$CLICK_UPX&amount=3&txid=UPX-1")
eq "$STATUS" 200 "upx.php answers 200"
CONV_UPX=$(Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$CLICK_UPX AND deleted=0")
[ -n "$CONV_UPX" ] && ok "its conversion was recorded" || bad "no conversion for click $CLICK_UPX"
eq "$(Q "SELECT status FROM 202_notification_pending WHERE conv_id=$CONV_UPX AND pixel_id=$PIX_A")" failed "its loopback postback was refused"
eq "$(Q "SELECT status FROM 202_notification_pending WHERE conv_id=$CONV_UPX AND pixel_id=$PIX_PAGE")" sent "and the network's was sent"
eq "$(wc -l < "$OUT/inside.log" | tr -d ' ')" 0 "the internal listener was never called"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
