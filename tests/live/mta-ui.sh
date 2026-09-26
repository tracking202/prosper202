#!/bin/bash
# Live pass for the attribution dashboard and exports (PR 10), over HTTP
# against a running instance, the database read back after every step:
#
#   - journeys driven through dl.php with a cookie jar per browser and
#     converted by gpb.php; the worker run the way cron runs it;
#   - every dashboard view renders on the v2 shell with no PHP noise; the
#     report's numbers are the database's (per row, the totals row, the
#     comparison columns, the CSV of what is shown); the journey drill-down's
#     per-model sums are the stored credits' sums; a partially reversed sale
#     reads its net amount wherever it is shown, and that amount is what its
#     credits sum to on the page, in the CSV and in an export;
#   - model management through the page's own forms, each one submitted with
#     the fields the page rendered (form-body.py): add, refuse, edit, make
#     default, switch off, delete — and what the API refuses the page refuses
#     in the API's sentence; a role without manage_attribution_models is
#     refused; a forged token writes nothing and says so;
#   - exports: created from the page, run by the export runner, downloaded
#     through the page and the API, delivered to a local HTTPS capture server
#     with a signature the pass verifies; a redirecting receiver is not
#     followed; a failing one is retried; a scheduled one waits; webhooks
#     aimed at 127.0.0.1, 169.254.169.254, 10.x, a decimal-spelled loopback
#     and plain http are refused at save time with nothing written;
#   - a staged or dry-run export DELETE runs the route's role checks: a key
#     whose role has no view_attribution_reports is refused 403 and reads
#     nothing of the export back, and the
#     admin's key still stages one with its preview.
#
# The capture server listens on 127.0.0.2 over TLS with a CA this pass makes;
# for the delivery to be allowed at all, the pass adds
#   define('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.2/32');
# to the instance's 202-config.php for its duration (the operator's
# allowlist; 127.0.0.1 stays refused) and runs the export runner with
# -d curl.cainfo pointing at its CA. Both are put back on exit.
#
# Needs a scratch database (it seeds campaigns 960001-960004 and removes
# them), the admin's password (P202_PASS) and an API key (P202_API_KEY). Run
# from the repository root of the instance being served. With P202_BIN (the
# Go CLI, configured against the instance under P202_CLI_HOME) it drives the
# CLI's export commands too.

BASE=${P202_BASE:-http://127.0.0.1:8150}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
HOOK_PORT=${P202_HOOK_PORT:-8158}
SERVER_LOG=${P202_SERVER_LOG:-}

if [ -z "$P202_PASS" ] || [ -z "$P202_API_KEY" ]; then
    echo "P202_PASS and P202_API_KEY must be set: the pass signs in as $P202_USER and reads over the API." >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
[ -f 202-cronjobs/attribution-exports.php ] && [ -f 202-config.php ] || { echo "run from the repository root of the served instance" >&2; exit 2; }

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
JAR="$OUT/jar"
PASS=0; FAIL=0
LOG_START=0
if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then LOG_START=$(wc -l < "$SERVER_LOG"); fi
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
msgs() { grep -oE '<div class="p202-flash__body">[^<]*|<div class="invalid-feedback[^"]*">[^<]*' "$1" | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
js()   { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
runner() { "$PHP" -d curl.cainfo="$OUT/ca.pem" 202-cronjobs/attribution-exports.php > "$OUT/runner.out" 2>&1; echo $?; }
worker() { "$PHP" 202-cronjobs/attribution-worker.php > "$OUT/worker.out" 2>&1; echo $?; }
REFUSED='This form expired or did not come from this page, so nothing was saved.'
PAGE="202-account/attribution.php"

get() { curl -sS -b "$JAR" -c "$JAR" "$BASE/$1" -o "$2" -w '%{http_code}' > "$OUT/.code"; }
# One POST of a form the page rendered, with overrides; a 303 is followed.
submit() {
  local page="$1" marker="$2" url="$3" out="$4"; shift 4
  if ! python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body"; then
    bad "the form carrying '$marker' is on the page"; : > "$out"; FIRST_STATUS=missing; return
  fi
  FIRST_STATUS=$(curl -sS -b "$JAR" -c "$JAR" -o "$out" -D "$OUT/.headers" -w '%{http_code}' \
    --data-binary @"$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url")
  if [ "$FIRST_STATUS" = "303" ]; then
    local location
    location=$(grep -i '^location:' "$OUT/.headers" | head -1 | sed 's/^[Ll]ocation: *//' | tr -d '\r')
    case "$location" in http*) ;; /*) location="${BASE%/}$location" ;; *) location="$BASE/$location" ;; esac
    curl -sS -b "$JAR" -c "$JAR" "$location" -o "$out"
  fi
}
# Text of the elements a CSS-ish probe names, from a saved page.
probe() { python3 "$OUT/probe.py" "$@"; }

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
[ -n "$USER_ID" ] || { echo "no user $P202_USER in $DB" >&2; exit 2; }
NOW=$(date +%s)
CAMP1=960001; CAMP2=960002; CAMP3=960003; CAMP4=960004
T1=960101; T2=960102; T3=960103; T4=960104
CAMPS="$CAMP1,$CAMP2,$CAMP3,$CAMP4"
MANAGER=mta_ui_manager
LIMITED=mta_ui_limited
CONFIG_BACKUP="$OUT/202-config.php.orig"
cp 202-config.php "$CONFIG_BACKUP"
HOOK_PID=""

cleanup() {
  [ -n "$HOOK_PID" ] && kill "$HOOK_PID" 2>/dev/null
  cp "$CONFIG_BACKUP" 202-config.php
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS));
DELETE FROM 202_attribution_credits WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS));
DELETE FROM 202_attribution_journeys WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS));
DELETE FROM 202_attribution_journey_meta WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS));
DELETE FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS);
DELETE FROM 202_clicks_visitor WHERE click_id IN (SELECT click_id FROM 202_clicks WHERE aff_campaign_id IN ($CAMPS));
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_clicks WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_trackers WHERE tracker_id_public IN ($T1, $T2, $T3, $T4);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_attribution_exports WHERE user_id = $USER_ID;
DELETE FROM 202_last_ips WHERE ip_id IN (SELECT ip_id FROM 202_ips WHERE ip_address LIKE '198.51.100.%');
SQL
  # Models this pass made; the default goes back to the one it found.
  [ -n "${ORIG_DEFAULT:-}" ] && Q "UPDATE 202_attribution_models SET is_default = NULL WHERE user_id=$USER_ID; UPDATE 202_attribution_models SET is_default = 1, status='active' WHERE model_id=$ORIG_DEFAULT"
  Q "DELETE cr FROM 202_attribution_credits cr JOIN 202_attribution_models m ON m.model_id=cr.model_id WHERE m.user_id=$USER_ID AND m.model_name LIKE 'UI %'"
  Q "DELETE FROM 202_attribution_models WHERE user_id=$USER_ID AND model_name LIKE 'UI %'"
  local mid who
  for who in "$MANAGER" "$LIMITED"; do
    mid=$(Q "SELECT user_id FROM 202_users WHERE user_name='$who'")
    if [ -n "$mid" ]; then
      Q "DELETE FROM 202_attribution_exports WHERE user_id=$mid; DELETE FROM 202_attribution_models WHERE user_id=$mid; DELETE FROM 202_user_role WHERE user_id=$mid; DELETE FROM 202_users_pref WHERE user_id=$mid; DELETE FROM 202_api_keys WHERE user_id=$mid; DELETE FROM 202_users WHERE user_id=$mid"
    fi
  done
}
trap cleanup EXIT
cleanup
ORIG_DEFAULT=$(Q "SELECT model_id FROM 202_attribution_models WHERE user_id=$USER_ID AND is_default=1")

cat > "$OUT/probe.py" <<'PY'
"""probe.py PAGE what [arg] -- read numbers and cells out of a saved dashboard page."""
import sys
from html.parser import HTMLParser

class Tables(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tables = {}; self.stack = []; self.row = None; self.cell = None; self.rowclass = ''
        self.attr_text = {}; self.capture = None
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'table':
            self.stack.append(a.get('id') or 'table%d' % len(self.tables)); self.tables[self.stack[-1]] = []
        elif tag == 'tr' and self.stack:
            self.row = []; self.rowclass = a.get('class') or ''
        elif tag in ('td', 'th') and self.row is not None:
            self.cell = ''
        for k, v in a.items():
            if k.startswith('data-p202-') and k not in ('data-p202-remember', 'data-p202-copy', 'data-p202-confirm', 'data-p202-sort', 'data-p202-range', 'data-p202-range-field', 'data-p202-show-when', 'data-p202-disable-hidden'):
                self.capture = (k + '=' + (v or ''), '');
    def handle_data(self, data):
        if self.cell is not None: self.cell += data
        if self.capture is not None: self.capture = (self.capture[0], self.capture[1] + data)
    def handle_endtag(self, tag):
        if tag in ('td', 'th') and self.cell is not None and self.row is not None:
            self.row.append(' '.join(self.cell.split())); self.cell = None
        elif tag == 'tr' and self.row is not None and self.stack:
            self.tables[self.stack[-1]].append((self.rowclass, self.row)); self.row = None
        elif tag == 'table' and self.stack:
            self.stack.pop()
        if self.capture is not None and tag in ('span', 'div'):
            self.attr_text.setdefault(self.capture[0], ' '.join(self.capture[1].split())); self.capture = None

p = Tables(); p.feed(open(sys.argv[1], encoding='utf-8', errors='replace').read())
what = sys.argv[2]
if what == 'attr':          # text of the element carrying data-p202-<name>=<value>
    print(p.attr_text.get('data-p202-' + sys.argv[3], 'none'))
elif what == 'cell':        # table id, first-cell text, column index
    for cls, row in p.tables.get(sys.argv[3], []):
        if row and row[0] == sys.argv[4]:
            print(row[int(sys.argv[5])]); break
    else:
        print('none')
elif what == 'rows':        # table id: body rows, first cell each (header and totals skipped)
    print(','.join(row[0] for cls, row in p.tables.get(sys.argv[3], [])[1:] if 'p202-table__totals' not in cls))
elif what == 'count':       # table id: how many body rows
    print(sum(1 for cls, row in p.tables.get(sys.argv[3], [])[1:] if 'p202-table__totals' not in cls))
elif what == 'totals':      # table id, column index
    print(next((row[int(sys.argv[4])] for cls, row in p.tables.get(sys.argv[3], []) if 'p202-table__totals' in cls), 'none'))
PY

# ─────────────────────────────────────────────────────────────────────
say "a local HTTPS receiver on 127.0.0.2:$HOOK_PORT, and the operator's allowlist for it"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=p202 live pass CA' -keyout "$OUT/ca.key" -out "$OUT/ca.pem" \
  -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign' 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj '/CN=127.0.0.2' -keyout "$OUT/hook.key" -out "$OUT/hook.csr" 2>/dev/null
printf 'subjectAltName=IP:127.0.0.2\nbasicConstraints=CA:FALSE\n' > "$OUT/hook.ext"
openssl x509 -req -in "$OUT/hook.csr" -CA "$OUT/ca.pem" -CAkey "$OUT/ca.key" -CAcreateserial -days 1 -extfile "$OUT/hook.ext" -out "$OUT/hook.pem" 2>/dev/null
mkdir -p "$OUT/hooks"
cat > "$OUT/capture.py" <<'PY'
import http.server, json, os, ssl, sys, time
port, cert, key, outdir = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def record(self):
        n = len(os.listdir(outdir)) + 1
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        with open(os.path.join(outdir, '%03d.json' % n), 'w') as f:
            json.dump({'path': self.path, 'method': self.command, 'headers': dict(self.headers), 'body': body.decode('utf-8', 'replace')}, f)
    def do_POST(self):
        self.record()
        if self.path.startswith('/redirect'):
            self.send_response(302); self.send_header('Location', 'https://127.0.0.2:%d/followed' % port); self.end_headers(); return
        if self.path.startswith('/fail'):
            self.send_response(503); self.end_headers(); self.wfile.write(b'receiver down for maintenance'); return
        self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
    do_GET = do_POST
srv = http.server.ThreadingHTTPServer(('127.0.0.2', port), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert, key)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
PY
python3 "$OUT/capture.py" "$HOOK_PORT" "$OUT/hook.pem" "$OUT/hook.key" "$OUT/hooks" > "$OUT/capture.log" 2>&1 &
HOOK_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null --cacert "$OUT/ca.pem" "https://127.0.0.2:$HOOK_PORT/probe" && break; sleep 0.3; done
eq "$(curl -s --cacert "$OUT/ca.pem" -X POST "https://127.0.0.2:$HOOK_PORT/probe")" ok "the receiver answers over TLS with the pass's CA"
rm -f "$OUT/hooks/"*
printf "\n// tests/live/mta-ui.sh: the pass's receiver (removed on exit)\nif (!defined('P202_WEBHOOK_ALLOW_NETWORKS')) { define('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.2/32'); }\n" >> 202-config.php
"$PHP" -l 202-config.php > /dev/null && ok "202-config.php allows 127.0.0.2/32 for the pass" || bad "202-config.php no longer parses"
"$PHP" -r 'usleep(3500000);' # the web server revalidates opcache every 2 s

# ─────────────────────────────────────────────────────────────────────
say "journeys: one browser over two campaigns, and a stranger"
mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP1, aff_campaign_id_public=$CAMP1, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='mta-ui-one', aff_campaign_url='http://offer.example/one', aff_campaign_payout=1, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP2, aff_campaign_id_public=$CAMP2, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='mta-ui-two', aff_campaign_url='http://offer.example/two', aff_campaign_payout=1, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP3, aff_campaign_id_public=$CAMP3, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='=HYPERLINK("http://evil.example","x")', aff_campaign_url='http://offer.example/three', aff_campaign_payout=1, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP4, aff_campaign_id_public=$CAMP4, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='mta-ui-four', aff_campaign_url='http://offer.example/four', aff_campaign_payout=1, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T4, aff_campaign_id=$CAMP4, click_cpc=0.10, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T1, aff_campaign_id=$CAMP1, click_cpc=0.20, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T2, aff_campaign_id=$CAMP2, click_cpc=0.30, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T3, aff_campaign_id=$CAMP3, click_cpc=0.10, click_cloaking=0, tracker_time=$NOW;
SQL
UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 mta-ui'
click() { # $1 jar, $2 query, $3 client IP; prints the click id the request recorded
  local before after
  before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
  curl -s -o /dev/null -A "$UA" -H "X-Forwarded-For: $3" -b "$1" -c "$1" "$BASE/tracking202/redirect/dl.php?$2"
  after=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
  [ "$after" != "$before" ] && echo "$after" || echo ""
}
gpb() { curl -s -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/gpb.php?$1"; }
convid() { Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$1 AND transaction_id='$2'"; }
A1=$(click "$OUT/a" "t202id=$T1" 198.51.100.110); sleep 1
A2=$(click "$OUT/a" "t202id=$T2" 198.51.100.110); sleep 1
A3=$(click "$OUT/a" "t202id=$T1" 198.51.100.110); sleep 1
S1=$(click "$OUT/s" "t202id=$T2" 198.51.100.120)
X1=$(click "$OUT/x" "t202id=$T3" 198.51.100.130)
R1=$(click "$OUT/r" "t202id=$T4" 198.51.100.140); sleep 1
R2=$(click "$OUT/r" "t202id=$T4" 198.51.100.140)
for c in A1 A2 A3 S1 X1 R1 R2; do [ -n "${!c}" ] && ok "click $c = ${!c}" || bad "click $c not recorded"; done
eq "$(gpb "subid=$A3&amount=12&txid=UI-A")" 200 "the first browser converts for \$12 on its third click"
eq "$(gpb "subid=$S1&amount=3&txid=UI-S")" 200 "the stranger for \$3"
eq "$(gpb "subid=$X1&amount=1&txid=UI-X")" 200 "and a click on the campaign with a formula for a name for \$1"
eq "$(gpb "subid=$R2&amount=10&txid=UI-R")" 200 "a third browser converts for \$10 on its second click"
CONV_A=$(convid "$A3" UI-A); CONV_S=$(convid "$S1" UI-S); CONV_R=$(convid "$R2" UI-R)
# A partial reversal: the sale counts \$6 from here on, in the engine and on
# every surface that shows the conversion's amount beside its credits.
eq "$(api -o "$OUT/reversal.json" -w '%{http_code}' -X POST -d "{\"click_id\": $R2, \"transaction_id\": \"UI-R\", \"status\": \"reversed\", \"payout\": 4}" "$BASE/api/v3/conversions")" 201 "the network reverses \$4 of it"
eq "$(Q "SELECT click_payout FROM 202_conversion_logs WHERE reverses_conv_id=$CONV_R")" "-4.00000" "recorded as a -\$4 row naming the sale"

LINEAR=$(api -X POST -d '{"model_name":"UI Linear","model_type":"linear"}' "$BASE/api/v3/attribution/models" | js 'd["data"]["model_id"]')
[[ "$LINEAR" =~ ^[0-9]+$ ]] && ok "a linear model to compare with ($LINEAR)" || bad "no linear model: $LINEAR"
eq "$(worker)" 0 "the attribution worker runs"
eq "$(Q "SELECT GROUP_CONCAT(click_id ORDER BY position) FROM 202_attribution_journeys WHERE conv_id=$CONV_A")" "$A1,$A2,$A3" "the journey is the browser's three clicks"
eq "$(Q "SELECT GROUP_CONCAT(click_id ORDER BY position) FROM 202_attribution_journeys WHERE conv_id=$CONV_S")" "$S1" "the stranger's is their one click"
eq "$(Q "SELECT GROUP_CONCAT(click_id ORDER BY position) FROM 202_attribution_journeys WHERE conv_id=$CONV_R")" "$R1,$R2" "the reversed sale's journey is its browser's two clicks"
eq "$(Q "SELECT GROUP_CONCAT(DISTINCT s ORDER BY s) FROM (SELECT SUM(revenue) AS s FROM 202_attribution_credits WHERE conv_id=$CONV_R GROUP BY model_id) x")" "6.00000" "the reversed sale's credits sum to \$6 under every model"

# ─────────────────────────────────────────────────────────────────────
say "sign in; every view renders on the v2 shell, with no PHP noise"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o /dev/null
for view in report journeys models exports "journey&conv_id=$CONV_A" "report&group_by=day&compare_model_id=$LINEAR" "report&group_by=keyword" "journeys&range=today"; do
  f="$OUT/render-$(echo "$view" | tr '&=' '__').html"
  get "$PAGE?view=$view" "$f"
  code=$(cat "$OUT/.code")
  if [ "$code" = 200 ] && grep -q 'p202-shell-v2' "$f" && ! grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$f"; then
    ok "?view=$view: 200, v2 shell, clean"
  else
    bad "?view=$view: status $code, v2=$(grep -c p202-shell-v2 "$f"), noise=$(grep -cE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$f")"
  fi
done

# ─────────────────────────────────────────────────────────────────────
say "the report is the database's numbers"
DEFAULT=$ORIG_DEFAULT
FROM=$(( $(date -d "$(date +%F) -30 days" +%s) )); TO=$(date -d "$(date +%F) 23:59:59" +%s)
get "$PAGE?view=report&group_by=campaign&model_id=$LINEAR&compare_model_id=$DEFAULT" "$OUT/report.html"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-one 4)" '$8.00' "linear gives campaign one two thirds of \$12"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-two 4)" '$7.00' "and campaign two a third of \$12 plus the stranger's \$3"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-one 7)" '1.00' "last touch, side by side, gives campaign one the whole conversion"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-one 8)" '$12.00' "and its \$12"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-one 1)" "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE aff_campaign_id=$CAMP1 AND click_bot=0")" "campaign one's clicks are its own"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-one 2)" '$0.40' "and so is its cost"
eq "$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-four 4)|$(probe "$OUT/report.html" cell attribution-breakdown mta-ui-four 8)" '$6.00|$6.00' "the partially reversed \$10 sale is \$6 under both models"
DBREV=$(Q "SELECT COALESCE(SUM(revenue),0) FROM 202_attribution_credits WHERE model_id=$LINEAR AND conv_time BETWEEN $FROM AND $TO")
eq "$(probe "$OUT/report.html" totals attribution-breakdown 4)" "\$$(printf '%.2f' "$DBREV")" "the totals row is the stored credits' revenue under the model (\$$DBREV)"
DBCOST=$(Q "SELECT COALESCE(SUM(click_cpc),0) FROM 202_clicks WHERE user_id=$USER_ID AND click_bot=0 AND click_time BETWEEN $FROM AND $TO")
eq "$(probe "$OUT/report.html" totals attribution-breakdown 2)" "\$$(printf '%.2f' "$DBCOST")" "the totals row's cost is every click's in the range (\$$DBCOST), every group being on the page"
eq "$(probe "$OUT/report.html" attr total=revenue)" "\$$(printf '%.2f' "$DBREV")" "and so is the tile"
DEFAULT_NAME=$(Q "SELECT model_name FROM 202_attribution_models WHERE model_id=$DEFAULT")
has "$OUT/report.html" "$DEFAULT_NAME revenue" "the comparison columns are named for the compared model"
get "$PAGE?view=report&group_by=campaign" "$OUT/effective.html"
has "$OUT/effective.html" 'id="attribution-decided"' "without a model the page says the effective model decided"

say "the CSV is what is shown, exactly, and safe to open"
curl -sS -b "$JAR" -D "$OUT/csv.h" -o "$OUT/report.csv" "$BASE/$PAGE?view=report&group_by=campaign&model_id=$LINEAR&compare_model_id=$DEFAULT&format=csv"
grep -qi '^content-type: text/csv' "$OUT/csv.h" && ok "served as text/csv" || bad "content type: $(grep -i content-type "$OUT/csv.h")"
eq "$(python3 -c "import csv,sys; r={x['name']:x for x in csv.DictReader(open('$OUT/report.csv'))}; print(r['mta-ui-one']['attributed_revenue'], r['mta-ui-one']['compare_attributed_revenue'])")" "8.00000 12.00000" "exact decimals, the comparison included"
eq "$(python3 -c "import csv,sys; r={x['name']:x for x in csv.DictReader(open('$OUT/report.csv'))}; print(r['mta-ui-four']['attributed_revenue'], r['mta-ui-four']['compare_attributed_revenue'])")" "6.00000 6.00000" "the partially reversed sale is \$6 in the CSV too"
grep -qF "\"'=HYPERLINK(" "$OUT/report.csv" && ok "a formula in a campaign name is written as text" || bad "formula cell: $(grep -F HYPERLINK "$OUT/report.csv")"
eq "$(( $(wc -l < "$OUT/report.csv") - 1 ))" "$(probe "$OUT/report.html" count attribution-breakdown)" "one CSV row per table row"

say "journeys and one conversion's journey"
get "$PAGE?view=journeys" "$OUT/journeys.html"
eq "$(probe "$OUT/journeys.html" cell browser-table Chrome 1 | tr -d ,)" "$(Q "SELECT COUNT(*) FROM 202_attribution_journey_meta WHERE user_id=$USER_ID AND conv_time BETWEEN $FROM AND $TO AND conv_id IN (SELECT j.conv_id FROM 202_attribution_journeys j JOIN 202_attribution_journey_meta m ON m.conv_id=j.conv_id AND j.position+1=m.touches JOIN 202_clicks_advance ca ON ca.click_id=j.click_id JOIN 202_browsers b ON b.browser_id=ca.browser_id WHERE b.browser_name='Chrome')")" "the one-touch table counts the Chrome conversions the database has"
has "$OUT/journeys.html" "view=journey&amp;conv_id=$CONV_A" "the recent list links to the conversion's journey"
get "$PAGE?view=journey&conv_id=$CONV_A" "$OUT/journey.html"
eq "$(probe "$OUT/journey.html" rows touches-table)" "1,2,3" "three touches, oldest first"
for m in "$DEFAULT" "$LINEAR"; do
  eq "$(probe "$OUT/journey.html" attr "credit-sum=$m")" "$(Q "SELECT CONCAT(FORMAT(SUM(credit)*100, 2), '%') FROM 202_attribution_credits WHERE conv_id=$CONV_A AND model_id=$m")" "model $m: the page's credit sum is the stored one"
  eq "$(probe "$OUT/journey.html" attr "revenue-sum=$m")" "\$$(Q "SELECT FORMAT(SUM(revenue), 2) FROM 202_attribution_credits WHERE conv_id=$CONV_A AND model_id=$m")" "model $m: and its revenue sum, \$12.00"
done
eq "$(probe "$OUT/journey.html" attr "credit-sum=$LINEAR")" "100.00%" "each column sums to the whole conversion"
hasnt "$OUT/journey.html" 'data-p202-total="recorded"' "a sale nothing reversed shows no recorded amount beside its own"

say "a partially reversed sale: the amount shown is the amount its credits sum to"
eq "$(api "$BASE/api/v3/attribution/conversions/$CONV_R/journey" | js 'd["data"]["amount"] + "|" + d["data"]["recorded_amount"] + "|" + str(d["data"]["counted"])')" "6.00000|10.00000|True" "the API's journey: amount \$6 net of the reversal, \$10 recorded"
get "$PAGE?view=journey&conv_id=$CONV_R" "$OUT/journey-r.html"
eq "$(probe "$OUT/journey-r.html" attr total=amount)" '$6.00' "the drill-down's amount is \$6"
for m in "$DEFAULT" "$LINEAR"; do
  eq "$(probe "$OUT/journey-r.html" attr "revenue-sum=$m")" '$6.00' "model $m: its revenue column sums to that amount"
  eq "$(probe "$OUT/journey-r.html" attr "credit-sum=$m")" '100.00%' "model $m: and its credit to the whole conversion"
done
probe "$OUT/journey-r.html" attr total=recorded | grep -qF '$10.00' && ok "the recorded \$10 is named beside it" || bad "recorded amount: $(probe "$OUT/journey-r.html" attr total=recorded)"
eq "$(probe "$OUT/journeys.html" cell recent-table "Conversion $CONV_R" 3)" '$6.00' "the recent conversions list it at \$6"
eq "$(probe "$OUT/journeys.html" cell recent-table "Conversion $CONV_A" 3)" '$12.00' "and the unreversed sale at its \$12"
curl -sS -L -b "$JAR" -c "$JAR" -o "$OUT/nojourney.html" "$BASE/$PAGE?view=journey&conv_id=999999999"
has "$OUT/nojourney.html" 'Conversion 999999999 was not found in this account.' "another account's or a missing conversion is not shown"

# ─────────────────────────────────────────────────────────────────────
say "models: a forged token writes nothing and says so"
get "$PAGE?view=models" "$OUT/models.html"
submit "$OUT/models.html" action=create_model "$PAGE?view=models" "$OUT/m-forged.html" token=forged model_name="UI Forged" model_type=linear
eq "$FIRST_STATUS" 303 "refused with a redirect"
has "$OUT/m-forged.html" "$REFUSED" "in the guard's own sentence"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_models WHERE model_name='UI Forged'")" 0 "no model was written"

say "models: what the API refuses, the page refuses in its sentence"
get "$PAGE?view=models" "$OUT/models.html"
submit "$OUT/models.html" action=create_model "$PAGE?view=models" "$OUT/m-bad.html" model_name="UI Decay" model_type=time_decay lookback_days=400 half_life_hours=24
msgs "$OUT/m-bad.html"
eq "$FIRST_STATUS" 200 "a refused submit re-renders in place"
has "$OUT/m-bad.html" 'value="UI Decay"' "what was typed is kept"
grep -qE 'invalid-feedback[^>]*>[^<]*(365|lookback)' "$OUT/m-bad.html" && ok "the lookback's sentence is under its field" || bad "no lookback sentence"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_models WHERE model_name='UI Decay'")" 0 "and nothing was written"
submit "$OUT/models.html" action=create_model "$PAGE?view=models" "$OUT/m-bad2.html" model_name="UI Decay" model_type=time_decay half_life_hours=forty
has "$OUT/m-bad2.html" 'A number, such as 48.' "a half-life that is not a number is refused by field"
submit "$OUT/models.html" action=create_model "$PAGE?view=models" "$OUT/m-bad3.html" model_name="UI Decay" model_type=algorithmic
grep -qE 'invalid-feedback[^>]*>[^<]*last_touch, first_touch' "$OUT/m-bad3.html" && ok "a type the engine cannot compute is refused with the list" || bad "no type sentence"

say "models: add, edit, make default, switch off, delete"
submit "$OUT/models.html" action=create_model "$PAGE?view=models" "$OUT/m-ok.html" model_name="UI Decay" model_type=time_decay lookback_days=45 half_life_hours=24
eq "$FIRST_STATUS" 303 "a saved model redirects"
has "$OUT/m-ok.html" 'UI Decay is added.' "and says so"
DECAY=$(Q "SELECT model_id FROM 202_attribution_models WHERE user_id=$USER_ID AND model_name='UI Decay'")
eq "$(Q "SELECT CONCAT(model_type, '|', weighting_config, '|', lookback_days, '|', status) FROM 202_attribution_models WHERE model_id=$DECAY")" 'time_decay|{"half_life_hours":24.0}|45|active' "stored as the API stores it"
submit "$OUT/m-ok.html" action=create_model "$PAGE?view=models" "$OUT/m-dup.html" model_name="ui decay" model_type=linear
grep -qE 'invalid-feedback[^>]*>[^<]*already exists' "$OUT/m-dup.html" && ok "a name like an existing one is refused under the name" || bad "no duplicate sentence"
get "$PAGE?view=models&edit=$DECAY" "$OUT/m-edit.html"
has "$OUT/m-edit.html" 'value="update_model"' "the edit form opens with the model"
submit "$OUT/m-edit.html" action=update_model "$PAGE?view=models" "$OUT/m-edited.html" model_type=position_based first_weight=0.3 last_weight=0.5
has "$OUT/m-edited.html" 'UI Decay is saved.' "an edit saves"
eq "$(Q "SELECT CONCAT(model_type, '|', weighting_config) FROM 202_attribution_models WHERE model_id=$DECAY")" 'position_based|{"first_weight":0.3,"last_weight":0.5}' "the type and its weights changed"
submit "$OUT/m-edited.html" action=set_default "$PAGE?view=models" "$OUT/m-def.html" id="$DECAY"
has "$OUT/m-def.html" 'is now the default' "make default"
eq "$(Q "SELECT model_id FROM 202_attribution_models WHERE user_id=$USER_ID AND is_default=1")" "$DECAY" "the default moved"
submit "$OUT/m-def.html" action=set_status "$PAGE?view=models" "$OUT/m-off-default.html" id="$DECAY" status=inactive
has "$OUT/m-off-default.html" 'The default model must be active' "the default cannot be switched off (the API's rule)"
eq "$(Q "SELECT status FROM 202_attribution_models WHERE model_id=$DECAY")" active "and it was not"
submit "$OUT/m-def.html" action=set_default "$PAGE?view=models" "$OUT/m-back.html" id="$DEFAULT"
submit "$OUT/m-back.html" action=set_status "$PAGE?view=models" "$OUT/m-off.html" id="$DECAY" status=inactive
has "$OUT/m-off.html" 'UI Decay is off' "a model is switched off"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_credits WHERE model_id=$DECAY")" 0 "and its credits go"
submit "$OUT/m-off.html" action=delete_model "$PAGE?view=models" "$OUT/m-del-default.html" id="$DEFAULT"
has "$OUT/m-del-default.html" 'is the default model and cannot be deleted' "the default cannot be deleted"
submit "$OUT/m-off.html" action=delete_model "$PAGE?view=models" "$OUT/m-del.html" id="$DECAY"
has "$OUT/m-del.html" 'UI Decay is deleted' "a model is deleted"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_models WHERE model_id=$DECAY")" 0 "and gone"

say "models: a role without manage_attribution_models is refused"
MID=$(api -X POST -d "{\"user_name\":\"$MANAGER\",\"user_email\":\"mta-ui-manager@example.test\",\"user_pass\":\"manager-pass-1\"}" "$BASE/api/v3/users" | js 'd["data"]["user_id"]')
api -X POST -d '{"role_id":3}' "$BASE/api/v3/users/$MID/roles" > /dev/null
MJAR="$OUT/mjar"
curl -sS -c "$MJAR" -b "$MJAR" "$BASE/202-login.php" -o "$OUT/mlogin.html"
MLT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/mlogin.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$MJAR" -b "$MJAR" -L "$BASE/202-login.php" --data-urlencode "token=$MLT" --data-urlencode "user_name=$MANAGER" --data-urlencode "user_pass=manager-pass-1" -o /dev/null
curl -sS -b "$MJAR" -c "$MJAR" "$BASE/$PAGE?view=models" -o "$OUT/mm.html"
has "$OUT/mm.html" 'Your role can read these models but not change them.' "a campaign manager reads the models"
hasnt "$OUT/mm.html" 'value="create_model"' "and is offered no form"
# The role has no model form, so the POST is built from a form it does
# have (the export form: its own token) with the model action on top.
ADMIN_JAR=$JAR; JAR=$MJAR
get "$PAGE?view=exports" "$OUT/mm-exports.html"
submit "$OUT/mm-exports.html" action=create_export "$PAGE?view=models" "$OUT/mm-post.html" action=create_model model_name="UI Manager" model_type=linear
JAR=$ADMIN_JAR
eq "$FIRST_STATUS" 303 "a model POST from that role is refused"
has "$OUT/mm-post.html" 'Your role cannot change attribution models' "in words"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_models WHERE model_name='UI Manager'")" 0 "and writes nothing"

# ─────────────────────────────────────────────────────────────────────
say "exports: webhooks aimed inside the network are refused at save time, and nothing is written"
get "$PAGE?view=exports" "$OUT/exports.html"
BEFORE=$(Q "SELECT COUNT(*) FROM 202_attribution_exports WHERE user_id=$USER_ID")
for target in "https://127.0.0.1:$HOOK_PORT/hook|loopback" "https://169.254.169.254/latest/meta-data/|link-local" "https://10.1.2.3/hook|private" \
              "https://2130706433/hook|dotted form" "https://[::ffff:127.0.0.1]/hook|loopback" "http://127.0.0.2:$HOOK_PORT/hook|https://"; do
  url=${target%%|*}; why=${target#*|}
  submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-refused.html" webhook_url="$url"
  if [ "$FIRST_STATUS" = 200 ] && grep -oE '<div class="invalid-feedback[^"]*">[^<]*' "$OUT/x-refused.html" | grep -qF -- "$why"; then
    ok "$url: refused under the field ($why)"
  else
    bad "$url: status $FIRST_STATUS, $(msgs "$OUT/x-refused.html" | tr '\n' ' ')"
  fi
done
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_exports WHERE user_id=$USER_ID")" "$BEFORE" "no export row was written"
eq "$(api -o /dev/null -w '%{http_code}' -X POST -d '{"webhook_url":"https://169.254.169.254/latest/"}' "$BASE/api/v3/attribution/exports")" 422 "the API refuses it too"
submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-forged.html" token=forged
has "$OUT/x-forged.html" "$REFUSED" "a forged token on an export is refused in words"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_exports WHERE user_id=$USER_ID")" "$BEFORE" "and writes nothing"

say "exports: now, to the receiver; the runner writes, signs and sends"
submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-hook.html" group_by=campaign model_id="$LINEAR" compare_model_id="$DEFAULT" webhook_url="https://127.0.0.2:$HOOK_PORT/hook"
eq "$FIRST_STATUS" 303 "an export with a webhook is queued"
HOOK_EXPORT=$(Q "SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=$USER_ID")
SECRET=$(grep -oE '<pre class="p202-code__value" id="export-secret-value">[^<]*' "$OUT/x-hook.html" | sed 's/.*>//')
eq "${#SECRET}" 64 "its generated secret is shown once"
eq "$(Q "SELECT webhook_secret FROM 202_attribution_exports WHERE export_id=$HOOK_EXPORT")" "$SECRET" "and is the stored one"
get "$PAGE?view=exports" "$OUT/exports-again.html"
hasnt "$OUT/exports-again.html" "$SECRET" "a reload does not show it again"
submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-plain.html" group_by=day
PLAIN_EXPORT=$(Q "SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=$USER_ID")
eq "$(runner)" 0 "the export runner exits 0: $(tr -d '\n' < "$OUT/runner.out")"
eq "$(Q "SELECT CONCAT(status, '/', webhook_status_code) FROM 202_attribution_exports WHERE export_id=$HOOK_EXPORT")" "completed/200" "the webhook export completed and the receiver answered 200"
eq "$(Q "SELECT status FROM 202_attribution_exports WHERE export_id=$PLAIN_EXPORT")" completed "the file-only export completed"
HOOKFILE=$(grep -l '"/hook"' "$OUT/hooks/"*.json | head -1)
[ -n "$HOOKFILE" ] && ok "the receiver got the POST" || bad "nothing reached /hook: $(ls "$OUT/hooks")"
curl -sS -b "$JAR" -D "$OUT/dl.h" -o "$OUT/download.csv" "$BASE/$PAGE?view=exports&download=$HOOK_EXPORT"
grep -qi '^content-disposition: attachment' "$OUT/dl.h" && ok "the page's Download sends the file" || bad "download headers: $(head -5 "$OUT/dl.h")"
eq "$(python3 -c "import json; print(json.load(open('$HOOKFILE'))['body'] == open('$OUT/download.csv').read())")" True "the webhook carried exactly the file the page downloads"
eq "$(python3 - "$HOOKFILE" "$SECRET" <<'PY'
import hashlib, hmac, json, sys
d = json.load(open(sys.argv[1])); h = {k.lower(): v for k, v in d['headers'].items()}
want = 'sha256=' + hmac.new(sys.argv[2].encode(), (h['x-p202-timestamp'] + '.' + d['body']).encode(), hashlib.sha256).hexdigest()
print('valid' if hmac.compare_digest(want, h['x-p202-signature']) else 'invalid ' + h['x-p202-signature'])
PY
)" valid "its X-P202-Signature verifies with the secret the page showed"
api -o "$OUT/api-download.csv" "$BASE/api/v3/attribution/exports/$HOOK_EXPORT/download"
cmp -s "$OUT/api-download.csv" "$OUT/download.csv" && ok "the API download is the same file" || bad "API and page downloads differ"
eq "$(python3 -c "import csv; r={x['name']:x for x in csv.DictReader(open('$OUT/download.csv'))}; print(r['mta-ui-one']['attributed_revenue'], r['mta-ui-one']['compare_attributed_revenue'])")" "8.00000 12.00000" "the export's numbers are the report's"
eq "$(python3 -c "import csv; r={x['name']:x for x in csv.DictReader(open('$OUT/download.csv'))}; print(r['mta-ui-four']['attributed_revenue'], r['mta-ui-four']['compare_attributed_revenue'])")" "6.00000 6.00000" "the export (and so the webhook's body) has the reversed sale at \$6"
eq "$(( $(wc -l < "$OUT/download.csv") - 1 ))" "$(Q "SELECT rows_exported FROM 202_attribution_exports WHERE export_id=$HOOK_EXPORT")" "rows_exported counts the file's rows"
eq "$(( $(wc -l < "$OUT/download.csv") - 1 ))" "$(api "$BASE/api/v3/attribution/reports/breakdown?group_by=campaign&model_id=$LINEAR&compare_model_id=$DEFAULT&limit=1000" | js 'd["meta"]["groups"]')" "every group of the breakdown, not a page of it"

say "exports: a redirecting receiver is not followed; a failing one is retried; a scheduled one waits"
submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-redir.html" webhook_url="https://127.0.0.2:$HOOK_PORT/redirect"
REDIR_EXPORT=$(Q "SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=$USER_ID")
submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-fail.html" webhook_url="https://127.0.0.2:$HOOK_PORT/fail"
FAIL_EXPORT=$(Q "SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=$USER_ID")
LATER=$(date -d '+2 hours' +%Y-%m-%dT%H:%M)
submit "$OUT/exports.html" action=create_export "$PAGE?view=exports" "$OUT/x-later.html" when=later run_at="$LATER"
has "$OUT/x-later.html" 'is scheduled for' "a later export says when"
LATER_EXPORT=$(Q "SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=$USER_ID")
runner >/dev/null
eq "$(Q "SELECT CONCAT(status, '/', webhook_status_code) FROM 202_attribution_exports WHERE export_id=$REDIR_EXPORT")" "failed/302" "the redirect failed the export at once"
Q "SELECT last_error FROM 202_attribution_exports WHERE export_id=$REDIR_EXPORT" | grep -q 'Redirects are not followed' && ok "saying redirects are not followed" || bad "last_error: $(Q "SELECT last_error FROM 202_attribution_exports WHERE export_id=$REDIR_EXPORT")"
grep -l '"/followed"' "$OUT/hooks/"*.json >/dev/null 2>&1 && bad "the Location was requested" || ok "and the Location was never requested"
eq "$(Q "SELECT CONCAT(status, '/', attempts, '/', webhook_status_code) FROM 202_attribution_exports WHERE export_id=$FAIL_EXPORT")" "pending/1/503" "a 503 is queued to retry"
[ "$(Q "SELECT queued_at - UNIX_TIMESTAMP() > 30 FROM 202_attribution_exports WHERE export_id=$FAIL_EXPORT")" = 1 ] && ok "a minute from now" || bad "retry time not in the future"
eq "$(Q "SELECT status FROM 202_attribution_exports WHERE export_id=$LATER_EXPORT")" pending "the scheduled export has not run"
get "$PAGE?view=exports" "$OUT/exports-list.html"
grep -q "data-export-id=\"$LATER_EXPORT\"" "$OUT/exports-list.html" && grep -A4 "data-export-id=\"$LATER_EXPORT\"" "$OUT/exports-list.html" | grep -q '>scheduled<' && ok "and the list says scheduled" || bad "no scheduled pill"
grep -A6 "data-export-id=\"$REDIR_EXPORT\"" "$OUT/exports-list.html" | grep -q 'Redirects are not followed' && ok "the list shows the failed export's reason" || bad "no reason on the failed row"

say "exports: retry and delete through the page"
submit "$OUT/exports-list.html" action=retry_export "$PAGE?view=exports" "$OUT/x-retry.html" id="$REDIR_EXPORT"
has "$OUT/x-retry.html" "Export $REDIR_EXPORT is queued again" "retry queues a failed export"
eq "$(Q "SELECT CONCAT(status, '/', attempts) FROM 202_attribution_exports WHERE export_id=$REDIR_EXPORT")" "pending/0" "with its attempts reset"
# No retry button on a completed export; the POST is the delete form's
# own fields with the retry action on top, as a hand-made request would be.
submit "$OUT/x-retry.html" action=delete_export "$PAGE?view=exports" "$OUT/x-retry2.html" action=retry_export id="$PLAIN_EXPORT"
has "$OUT/x-retry2.html" 'Only a failed export can be retried' "a completed one cannot be retried (the API's rule)"
FILE=$(Q "SELECT file_path FROM 202_attribution_exports WHERE export_id=$PLAIN_EXPORT")
DIR=$("$PHP" -r 'require "vendor/autoload.php"; echo (new Prosper202\Attribution\ExportFiles())->directory();')
[ -f "$DIR/$FILE" ] && ok "the export's file is on disk" || bad "no file $DIR/$FILE"
submit "$OUT/x-retry.html" action=delete_export "$PAGE?view=exports" "$OUT/x-del.html" id="$PLAIN_EXPORT"
has "$OUT/x-del.html" "Export $PLAIN_EXPORT is deleted, with its file." "delete says what went"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_exports WHERE export_id=$PLAIN_EXPORT")" 0 "the row is gone"
[ -f "$DIR/$FILE" ] && bad "the file is still there" || ok "and the file"
eq "$(curl -sS "$BASE/202-config/temp/attribution-exports/" | wc -c)" 0 "the export directory lists nothing (its index.html is empty)"
has "$DIR/.htaccess" 'Require all denied' "and denies every request on Apache"
[[ "$(Q "SELECT file_path FROM 202_attribution_exports WHERE export_id=$HOOK_EXPORT")" =~ ^u[0-9]+-e[0-9]+-[0-9a-f]{32}\.csv$ ]] && ok "file names carry 128 random bits" || bad "file name is guessable"

say "exports: a staged or dry-run delete runs the route's role checks first"
# Role 4 (campaign optimizer) has no attribution permission. Its account
# gets an export with a webhook URL worth not leaking.
LID=$(api -X POST -d "{\"user_name\":\"$LIMITED\",\"user_email\":\"mta-ui-limited@example.test\",\"user_pass\":\"limited-pass-1\"}" "$BASE/api/v3/users" | js 'd["data"]["user_id"]')
api -X POST -d '{"role_id":4}' "$BASE/api/v3/users/$LID/roles" > /dev/null
LKEY=$(api -X POST -d '{}' "$BASE/api/v3/users/$LID/api-keys" | js 'd["data"]["api_key"]')
LMODEL=$(Q "SELECT model_id FROM 202_attribution_models WHERE user_id=$LID AND is_default=1")
Q "INSERT INTO 202_attribution_exports (user_id, model_id, group_by, range_start, range_end, status, webhook_url, webhook_secret, attempts, queued_at, created_at, updated_at)
   VALUES ($LID, $LMODEL, 'campaign', $((NOW - 86400)), $NOW, 'failed', 'https://hooks.example.test/private-token-path', 'limited-secret-value', 0, $NOW, $NOW, $NOW)"
LEXPORT=$(Q "SELECT MAX(export_id) FROM 202_attribution_exports WHERE user_id=$LID")
[[ "$LKEY" =~ ^[0-9a-f]{16,}$ ]] && [[ "$LEXPORT" =~ ^[0-9]+$ ]] && ok "a role-4 account with a key and an export" || bad "setup: key '$LKEY' export '$LEXPORT'"
as() { local k=$1; shift; curl -s -H "Authorization: Bearer $k" -H 'Content-Type: application/json' "$@"; }
eq "$(as "$LKEY" -o /dev/null -w '%{http_code}' "$BASE/api/v3/attribution/exports/$LEXPORT")" 403 "its own export is refused to a GET"
code=$(as "$LKEY" -o "$OUT/lx-staged.json" -w '%{http_code}' -X DELETE "$BASE/api/v3/attribution/exports/$LEXPORT?staged=1")
eq "$code" 403 "and to a staged DELETE"
has "$OUT/lx-staged.json" "does not have the 'view_attribution_reports' permission" "refused by the route's own role check"
# (A staged change's presentation redacts webhook_* fields; the rest of the
# record — model, range, status, last error — is what staging leaked.)
grep -q '"record"' "$OUT/lx-staged.json" && bad "the refusal carries the export's record" || ok "and nothing of the export comes back"
eq "$(as "$LKEY" -o /dev/null -w '%{http_code}' -X DELETE "$BASE/api/v3/attribution/exports/$LEXPORT?dry_run=1")" 403 "a dry run is refused the same way"
eq "$(as "$LKEY" -o /dev/null -w '%{http_code}' -X POST -d '{}' "$BASE/api/v3/attribution/exports?staged=1")" 403 "and a staged create"
eq "$(as "$LKEY" "$BASE/api/v3/staged-changes" | js 'len(d["data"])')" 0 "no proposal was recorded"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_exports WHERE export_id=$LEXPORT")" 1 "the export is untouched"
code=$(api -o "$OUT/ax-staged.json" -w '%{http_code}' -X DELETE "$BASE/api/v3/attribution/exports/$HOOK_EXPORT?staged=1")
eq "$code" 202 "the admin's key still stages an export delete"
eq "$(js 'str(d["data"]["preview"]["record"]["export_id"])' < "$OUT/ax-staged.json" 2>/dev/null)" "$HOOK_EXPORT" "with the preview of the export it would remove"
CHG=$(js 'd["data"]["change_id"]' < "$OUT/ax-staged.json" 2>/dev/null)
eq "$(api -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v3/staged-changes/$CHG/discard")" 200 "and the proposal is discarded"

# ─────────────────────────────────────────────────────────────────────
if [ -n "${P202_BIN:-}" ]; then
  # The Go CLI, configured against this instance (P202_CLI_HOME is the HOME
  # its config lives in), over the same endpoints.
  say "the Go CLI: create, run, download, and a refused webhook"
  p202() { HOME="${P202_CLI_HOME:-$HOME}" "$P202_BIN" "$@"; }
  CLI_EXPORT=$(p202 attribution export create --group-by campaign --model "$LINEAR" --period last30 --json 2> "$OUT/cli.err" | js 'd["data"]["export_id"]')
  [[ "$CLI_EXPORT" =~ ^[0-9]+$ ]] && ok "p202 attribution export create queued export $CLI_EXPORT" || bad "create: $(cat "$OUT/cli.err")"
  runner >/dev/null
  eq "$(p202 attribution export get "$CLI_EXPORT" --json | js 'd["data"]["status"]')" completed "p202 attribution export get reads it completed"
  p202 attribution export download "$CLI_EXPORT" --output "$OUT/cli.csv" 2>/dev/null
  api -o "$OUT/cli-api.csv" "$BASE/api/v3/attribution/exports/$CLI_EXPORT/download"
  cmp -s "$OUT/cli.csv" "$OUT/cli-api.csv" && ok "p202 attribution export download writes the file the API serves" || bad "CLI download differs"
  p202 attribution export create --webhook-url "https://169.254.169.254/latest/" --json > "$OUT/cli-refused.out" 2> "$OUT/cli-refused.err"
  eq "$?" 1 "a metadata webhook exits 1 (validation)"
  eq "$(js 'd["error"]["field_errors"]["webhook_url"].split(",")[0]' < "$OUT/cli-refused.err")" "169.254.169.254" "the envelope names the address"
  grep -q 'P202_WEBHOOK_ALLOW_NETWORKS' "$OUT/cli-refused.err" && ok "and its hint says what to do" || bad "no hint: $(cat "$OUT/cli-refused.err")"
  eq "$(wc -c < "$OUT/cli-refused.out")" 0 "stdout stays empty on failure"
fi

# ─────────────────────────────────────────────────────────────────────
if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then
  if tail -n +"$((LOG_START + 1))" "$SERVER_LOG" | grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' > "$OUT/warn"; then
    bad "PHP warnings in the server log: $(head -3 "$OUT/warn")"
  else
    ok "no PHP warnings, notices or fatals in the server log"
  fi
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
