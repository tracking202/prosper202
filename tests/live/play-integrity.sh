#!/bin/bash
# Live pass for Play Integrity (PR 6), driven against a running instance as a
# device, an operator and the cron would, with a local TLS fake of Google's
# token and decodeIntegrityToken endpoints (tests/fixtures/play-integrity/
# fake_google.py) standing in for Google, and the database read back after
# every step:
#
#   - settings: the mode refused without a credential, a credential whose
#     token_uri points elsewhere refused, staging the credential refused, the
#     credential stored encrypted and never echoed (API, Go CLI, PHP CLI),
#     the schema document telling the SDK to request a standard token;
#   - observe: a passing and a failing verdict are recorded, and neither
#     moves the install's attribution, its conversion or its postback;
#   - require: an attributable install waits (pending_integrity: no
#     conversion, no postback, events 503) and is attributed, paid and its
#     postback sent by the same cron run once its verdict passes; each
#     failing verdict — another app's package, another install's request
#     hash, a stale token, an unrecognised app, no device integrity,
#     unlicensed, a token Google cannot decode — refutes it (integrity_failed:
#     nothing paid, events 409); a token already verified for another
#     install is refused without a decode; no token is integrity_unverified
#     at once; a token-endpoint refusal, Google 5xx and a timeout are retried
#     with backoff, a timeout then recovers, and a 5xx past the 24-hour
#     deadline ends integrity_unverified, never paid;
#   - the reads (GET /apps/{id}/integrity, installs by integrity_state, the
#     CLIs), and clearing the credential (refused while the mode needs it,
#     and while an install still waits for a verdict); observe refused
#     without a Cloud project number, which is never cleared;
#   - deleting the registration settles an install still waiting for its
#     verdict (integrity_unverified / error, never paid, never decoded).
#
# The worker is 202-cronjobs/app-installs.php from this checkout, run with
# P202_PLAY_INTEGRITY_ENDPOINT=https://127.0.0.1:<port> and the fake's CA;
# so the checkout's 202-config.php must name the instance's database. No
# request reaches Google. Truncates the app, goal and outbox tables, so it
# needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
FAKE_PORT=${P202_FAKE_GOOGLE_PORT:-8241}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass drives the REST API as the instance's admin." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2
for bin in python3 openssl curl; do
    command -v "$bin" > /dev/null || { echo "$bin is required" >&2; exit 2; }
done

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }

api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
api_file() { # METHOD PATH FILE
    curl -s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' --data-binary "@$3" "$BASE/api/v3$2"
}
device() {
    local args=(-s -o "$OUT/body" -D "$OUT/headers" -w '%{http_code}' -X "$1" -H "X-P202-App-Token: $3")
    [ -n "${4:-}" ] && args+=(-H 'Content-Type: application/json' --data-binary "@$4")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
uuid() { python3 -c 'import uuid; print(uuid.uuid4())'; }
ctime() { Q "SELECT click_time FROM 202_clicks WHERE click_id = $1"; }
UA='Mozilla/5.0 (Linux; Android 14; Pixel 8) play-integrity-pass'
click() {
    local before after
    before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    curl -s -o /dev/null -D "$2" -A "$UA" "$BASE/tracking202/redirect/dl.php?t202id=$1"
    after=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    [ "$after" != "$before" ] && echo "$after" || echo ""
}
referrer_of() {
    python3 -c "
import sys, urllib.parse
loc = [l.split(':', 1)[1].strip() for l in open(sys.argv[1]) if l.lower().startswith('location:')]
q = urllib.parse.urlparse(loc[0]).query if loc else ''
print(urllib.parse.parse_qs(q).get('referrer', [''])[0])" "$1"
}

# body FILE UUID REFERRER CLICK_TIME TOKEN — an SDK-shaped install carrying an
# integrity token ("-" for none); prints its request hash, computed here in
# Python (the canonical body without integrity_token, sorted keys, no
# whitespace, SHA-256 hex): what the SDK binds the token to.
body() {
    python3 - "$@" <<'PY'
import hashlib, json, sys
path, uid, referrer, t, token = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4]), sys.argv[5]
b = {"install_uuid": uid, "app_key": "com.p202.pi.summit", "store": "google_play",
     "referrer": {"status": "ok", "install_referrer": referrer,
                  "referrer_click_timestamp_seconds": t + 2, "install_begin_timestamp_seconds": t + 39,
                  "referrer_click_timestamp_server_seconds": t + 3, "install_begin_timestamp_server_seconds": t + 40,
                  "install_version": "3.2.0", "google_play_instant": False},
     "first_open_at": t + 60, "app_version": "3.2.0", "sdk_version": "1.0.0", "os_version": "15", "test": False,
     "integrity_token": None if token == "-" else token}
json.dump(b, open(path, "w"))
c = dict(b); c.pop("integrity_token")
print(hashlib.sha256(json.dumps(c, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest())
PY
}
# verdict HASH [KEY=JSON ...] — Google's tokenPayloadExternal for a token
# issued 5 s ago, with sections overridden (e.g. deviceIntegrity='{"deviceRecognitionVerdict":[]}').
verdict() {
    python3 - "$@" <<'PY'
import json, sys, time
h = sys.argv[1]
v = {"requestDetails": {"requestPackageName": "com.p202.pi.summit", "requestHash": h, "timestampMillis": str(int((time.time() - 5) * 1000))},
     "appIntegrity": {"appRecognitionVerdict": "PLAY_RECOGNIZED", "packageName": "com.p202.pi.summit", "versionCode": "42"},
     "deviceIntegrity": {"deviceRecognitionVerdict": ["MEETS_DEVICE_INTEGRITY"]},
     "accountDetails": {"appLicensingVerdict": "LICENSED"}}
for arg in sys.argv[2:]:
    k, val = arg.split("=", 1)
    v[k].update(json.loads(val))
print(json.dumps(v))
PY
}
TLS="$OUT/tls"
fake() { # PATH JSON — a control call to the fake Google
    curl -s -o /dev/null -w '%{http_code}' --noproxy '*' --cacert "$TLS/cert.pem" -X POST --data "$2" "https://127.0.0.1:$FAKE_PORT$1"
}
scenario() { # TOKEN RESPONSES-JSON
    eq "$(fake /control/scenario "{\"token\": \"$1\", \"responses\": $2}")" 200 "fake Google will answer $1"
}
fake_requests() { # python expression over the recorded requests list r
    curl -s --noproxy '*' --cacert "$TLS/cert.pem" "https://127.0.0.1:$FAKE_PORT/control/requests" > "$OUT/requests.json"
    python3 -c "import json,sys; r=json.load(open(sys.argv[1]))['requests']; print($1)" "$OUT/requests.json"
}
cron() {
    (cd "$ROOT" && P202_PLAY_INTEGRITY_ENDPOINT="https://127.0.0.1:$FAKE_PORT" P202_PLAY_INTEGRITY_CA_FILE="$TLS/cert.pem" \
        "$PHP" 202-cronjobs/app-installs.php) > "$OUT/cron.txt" 2>&1
}
row() { Q "SELECT CONCAT_WS('/', match_state, COALESCE(trusted, 'null'), integrity_state) FROM 202_app_installs WHERE install_uuid='$1'"; }
paid() { Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$1 AND dedupe_key='install'"; }

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }

FAKE_PID=
cleanup() {
  [ -n "$FAKE_PID" ] && kill "$FAKE_PID" 2> /dev/null
  mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%'));
DELETE FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%');
DELETE FROM 202_dataengine WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%');
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%');
DELETE FROM 202_clicks WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%');
DELETE FROM 202_trackers WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%');
DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN (SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name = 'pi-pass');
DELETE FROM 202_ppc_accounts WHERE ppc_account_name = 'pi-pass';
DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'pi-pass%';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
TRUNCATE 202_app_registrations; TRUNCATE 202_app_installs; TRUNCATE 202_notification_pending; TRUNCATE 202_app_integrity_credentials;
SQL
}
cleanup
trap cleanup EXIT

for _ in 1 2 3; do
    code=$(curl -s -o /dev/null -D "$OUT/probe" -w '%{http_code}' "$BASE/api/v3/apps/installs")
    [ "$code" = 429 ] || break
    wait_s=$(awk 'tolower($1) == "retry-after:" {print $2+0}' "$OUT/probe")
    sleep "${wait_s:-60}"
done

# ─────────────────────────────────────────────────────────────────────
say "a fake of Google's endpoints over TLS, and a service account it trusts"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$OUT/sa.pem" 2> /dev/null
openssl pkey -in "$OUT/sa.pem" -pubout -out "$OUT/sa-pub.pem" 2> /dev/null
python3 - "$OUT/sa.pem" "$OUT/key.json" "$OUT/key-evil.json" <<'PY'
import json, sys
pem = open(sys.argv[1]).read()
key = {"type": "service_account", "project_id": "p202-pi-pass", "private_key_id": "a1b2c3d4e5f60718", "private_key": pem,
       "client_email": "integrity@p202-pi-pass.iam.gserviceaccount.com", "client_id": "1",
       "token_uri": "https://oauth2.googleapis.com/token"}
json.dump({"credential": key}, open(sys.argv[2], "w"))
json.dump({"credential": dict(key, token_uri="https://evil.example/token")}, open(sys.argv[3], "w"))
PY
python3 "$ROOT/tests/fixtures/play-integrity/fake_google.py" --port "$FAKE_PORT" --tls-dir "$TLS" --sa-public-key "$OUT/sa-pub.pem" > "$OUT/fake.out" 2> "$OUT/fake.err" &
FAKE_PID=$!
for _ in $(seq 1 50); do grep -q READY "$OUT/fake.out" 2> /dev/null && break; sleep 0.2; done
has "$OUT/fake.out" "READY $FAKE_PORT" "the fake listens on $FAKE_PORT"

say "setup: an Android app, a campaign whose store link carries the token, a traffic source"
eq "$(api POST /apps '{"store_link":"com.p202.pi.summit","app_name":"PI Summit"}')" 201 "an Android app"
R=$(field "d['data']['registration_id']"); TOKEN=$(field "d['data']['app_token']")
eq "$(field "d['data']['integrity_mode']")" off "Play Integrity is off by default"
eq "$(api POST /aff-networks '{"aff_network_name":"pi-pass"}')" 201 "an affiliate network"
NET=$(field "d['data']['aff_network_id']")
STORE='https://play.google.com/store/apps/details?id=com.p202.pi.summit&referrer=p202%3D[[p202_install_token]]'
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"pi-pass A\",\"aff_campaign_url\":\"$STORE\",\"aff_campaign_payout\":\"2.50\",\"aff_network_id\":$NET,\"payout_mode\":\"accumulate\",\"app_registration_id\":$R}")" 201 \
   "a campaign paying \$2.50 an install, linked to the app"
CAMP=$(field "d['data']['aff_campaign_id']")
eq "$(api POST /ppc-networks '{"ppc_network_name":"pi-pass"}')" 201 "a traffic source"
PNET=$(field "d['data']['ppc_network_id']")
eq "$(api POST /ppc-accounts "{\"ppc_account_name\":\"pi-pass\",\"ppc_network_id\":$PNET}")" 201 "and its account"
PACC=$(field "d['data']['ppc_account_id']")
Q "INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('$BASE/api/v3/versions?pi=1&sub=[[subid]]&goal=[[p202_goal]]', 4, $PACC)"
eq "$(api POST /trackers "{\"aff_campaign_id\":$CAMP,\"ppc_account_id\":$PACC}")" 201 "a tracker"
TRK=$(field "d['data']['tracker_id_public']")

# ─────────────────────────────────────────────────────────────────────
say "settings: the credential first, stored encrypted and never shown"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"observe"}')" 422 "observe without a credential is refused"
has "$OUT/body" "integrity-credential" "naming the route that sets it"
eq "$(api_file PUT "/apps/$R/integrity-credential" "$OUT/key-evil.json")" 422 "a key file whose token_uri is not Google's is refused"
has "$OUT/body" "credential.token_uri" "by field"
hasnt "$OUT/body" "PRIVATE KEY" "without echoing the key"
eq "$(api_file PUT "/apps/$R/integrity-credential?staged=1" "$OUT/key.json")" 422 "the credential cannot be staged"
has "$OUT/body" "cannot be staged" "saying so"
eq "$(Q "SELECT COUNT(*) FROM 202_app_integrity_credentials")" 0 "nothing stored by the refusals"
eq "$(api_file PUT "/apps/$R/integrity-credential" "$OUT/key.json")" 200 "the service account is set"
eq "$(field "[d['data']['credential']['client_email'], d['data']['credential']['private_key_id']]")" '["integrity@p202-pi-pass.iam.gserviceaccount.com", "a1b2c3d4e5f60718"]' \
   "answered with the account and key id"
hasnt "$OUT/body" "PRIVATE KEY" "never the key"
eq "$(Q "SELECT LEFT(ciphertext, 3) FROM 202_app_integrity_credentials WHERE registration_id=$R")" "v1." "stored as a v1 ciphertext"
eq "$(Q "SELECT COUNT(*) FROM 202_app_integrity_credentials WHERE ciphertext LIKE '%PRIVATE%' OR ciphertext LIKE '%BEGIN%'")" 0 "the key is not in the row in the clear"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"Observe"}')" 422 "a mode is read exactly"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"observe"}')" 422 "observe without a Cloud project number is refused, credential or not"
eq "$(field "list(d['field_errors'].keys())")" '["integrity_cloud_project_number"]' "by field"
has "$OUT/body" "Cloud project number" "saying what is missing"
eq "$(Q "SELECT integrity_mode FROM 202_app_registrations WHERE registration_id=$R")" off "and the mode stays off"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"observe","integrity_cloud_project_number":"123456789012"}')" 200 "observe, with the Cloud project number"
eq "$(curl -s -o "$OUT/body" -w '%{http_code}' -H "X-P202-App-Token: $TOKEN" "$BASE/api/v3/apps/schema")" 200 "the schema document"
eq "$(field "[d['data']['integrity_mode'], d['data']['integrity']['request_token'], d['data']['integrity']['token_type'], d['data']['integrity']['cloud_project_number']]")" \
   '["observe", true, "standard", "123456789012"]' "tells the SDK to request a standard token for the project"
hasnt "$OUT/body" "integrity@" "and never names the service account"
eq "$(api PUT "/apps/$R" '{"integrity_cloud_project_number":null}')" 422 "the project number cannot be cleared"
has "$OUT/body" "cannot be cleared" "saying so, rather than dropping the null"
eq "$(Q "SELECT integrity_cloud_project_number FROM 202_app_registrations WHERE registration_id=$R")" 123456789012 "and it is kept"

# ─────────────────────────────────────────────────────────────────────
say "observe: verdicts are recorded and move nothing"
C1=$(click "$TRK" "$OUT/h1"); REF1=$(referrer_of "$OUT/h1")
U1=$(uuid); H1=$(body "$OUT/i1.json" "$U1" "$REF1" "$(ctime "$C1")" tok-observe-pass)
C2=$(click "$TRK" "$OUT/h2"); REF2=$(referrer_of "$OUT/h2")
U2=$(uuid); H2=$(body "$OUT/i2.json" "$U2" "$REF2" "$(ctime "$C2")" tok-observe-fail)
scenario tok-observe-pass "[{\"status\": 200, \"payload\": $(verdict "$H1")}]"
scenario tok-observe-fail "[{\"status\": 200, \"payload\": $(verdict "$H2" deviceIntegrity='{"deviceRecognitionVerdict":["MEETS_BASIC_INTEGRITY"]}')}]"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i1.json")" 200 "an install with a token"
eq "$(field "[d['data']['match'], d['data']['trusted'], d['data']['integrity']]")" '["attributed", 1, "pending"]' "is attributed at once, its verdict pending"
device POST /apps/installs "$TOKEN" "$OUT/i2.json" > /dev/null
eq "$(paid "$C1")$(paid "$C2")" 11 "both installs are paid under observe"
cron
has "$OUT/cron.txt" "play integrity: 2 examined, valid=1 invalid=1" "the worker decodes both"
eq "$(row "$U1")" "attributed/1/valid" "the passing verdict is recorded"
eq "$(row "$U2")" "attributed/1/invalid" "the failing one too"
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$U2'" | grep -c MEETS_BASIC_INTEGRITY)" 1 "with the reason"
eq "$(paid "$C2")" 1 "and nothing is un-paid"
eq "$(Q "SELECT GROUP_CONCAT(status) FROM 202_notification_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($C1, $C2))")" "sent,sent" \
   "and both postbacks went out"
eq "$(fake_requests "[x['jwt_problem'] for x in r if x['path'] == '/token']")" "[None]" "the fake verified the signed assertion (RS256 over openssl)"
eq "$(fake_requests "sorted({x['path'] for x in r if 'decode' in x['path']})")" "['/v1/com.p202.pi.summit:decodeIntegrityToken']" "and saw the decode for the app's package"
eq "$(fake_requests "all(x['authorization'].startswith('Bearer fake-access-') for x in r if 'decode' in x['path'])")" True "with the bearer the grant issued"

# ─────────────────────────────────────────────────────────────────────
say "require: an attributable install waits for its verdict, then is paid"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"require"}')" 200 "require"
C3=$(click "$TRK" "$OUT/h3"); REF3=$(referrer_of "$OUT/h3")
U3=$(uuid); H3=$(body "$OUT/i3.json" "$U3" "$REF3" "$(ctime "$C3")" tok-require-pass)
scenario tok-require-pass "[{\"status\": 200, \"payload\": $(verdict "$H3")}]"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i3.json")" 200 "the install is recorded"
eq "$(field "[d['data']['match'], d['data']['trusted'], d['data']['integrity']]")" '["pending_integrity", null, "pending"]' "and held: pending_integrity"
eq "$(paid "$C3")" 0 "nothing is paid while it waits"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_pending WHERE url LIKE '%sub=$C3&%'")" 0 "nobody is told"
printf '{"events": [{"event_id": "e1", "name": "level_reached", "occurred_at": %s}]}' "$(( $(ctime "$C3") + 100 ))" > "$OUT/e3.json"
eq "$(device POST "/apps/installs/$U3/events" "$TOKEN" "$OUT/e3.json")" 503 "its events wait"
has "$OUT/headers" "Retry-After" "with Retry-After"
cron
has "$OUT/cron.txt" "valid=1" "the worker decodes it"
eq "$(row "$U3")" "attributed/1/valid" "attributed once its verdict passes"
eq "$(paid "$C3")" 1 "paid"
eq "$(Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$C3")" "1/2.50000" "the click is worth \$2.50"
eq "$(Q "SELECT status FROM 202_notification_pending WHERE url LIKE '%sub=$C3&%'")" sent "its postback sent by the same cron run"
eq "$(Q "SELECT conversion_id IS NOT NULL FROM 202_app_installs WHERE install_uuid='$U3'")" 1 "the install names its conversion"
eq "$(device POST "/apps/installs/$U3/events" "$TOKEN" "$OUT/e3.json")" 200 "its events are accepted now"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i3.json")" 200 "a replay"
eq "$(field "[d['data']['match'], d['data']['duplicate']]")" '["attributed", true]' "is its duplicate"

say "require: each failing verdict refutes the install"
n=0
for case in \
  "wrong package|requestDetails={\"requestPackageName\":\"com.p202.pi.other\"}|requested by \"com.p202.pi.other\"" \
  "another install's hash|requestDetails={\"requestHash\":\"$(printf '%064d' 0)\"}|another install body" \
  "stale|requestDetails={\"timestampMillis\":\"1000000000000\"}|before the install arrived" \
  "unrecognized app|appIntegrity={\"appRecognitionVerdict\":\"UNRECOGNIZED_VERSION\"}|UNRECOGNIZED_VERSION" \
  "no device integrity|deviceIntegrity={\"deviceRecognitionVerdict\":[\"MEETS_VIRTUAL_INTEGRITY\"]}|MEETS_VIRTUAL_INTEGRITY" \
  "unlicensed|accountDetails={\"appLicensingVerdict\":\"UNLICENSED\"}|UNLICENSED"; do
    n=$((n+1))
    name=${case%%|*}; rest=${case#*|}; override=${rest%%|*}; says=${rest#*|}
    C=$(click "$TRK" "$OUT/hf$n"); REF=$(referrer_of "$OUT/hf$n")
    U=$(uuid); H=$(body "$OUT/if$n.json" "$U" "$REF" "$(ctime "$C")" "tok-fail-$n")
    scenario "tok-fail-$n" "[{\"status\": 200, \"payload\": $(verdict "$H" "$override")}]"
    device POST /apps/installs "$TOKEN" "$OUT/if$n.json" > /dev/null
    cron
    eq "$(row "$U")" "integrity_failed/0/invalid" "$name: integrity_failed, refuted"
    eq "$(Q "SELECT match_reason FROM 202_app_installs WHERE install_uuid='$U'" | grep -cF -- "$says")" 1 "$name: the reason says why"
    eq "$(paid "$C")" 0 "$name: nothing paid"
    printf '{"events": [{"event_id": "x", "name": "x", "occurred_at": %s}]}' "$(( $(ctime "$C") + 100 ))" > "$OUT/ef.json"
    eq "$(device POST "/apps/installs/$U/events" "$TOKEN" "$OUT/ef.json")" 409 "$name: its events are refused"
done

C=$(click "$TRK" "$OUT/hu"); REF=$(referrer_of "$OUT/hu")
UU=$(uuid); body "$OUT/iu.json" "$UU" "$REF" "$(ctime "$C")" tok-google-cannot-decode > /dev/null
device POST /apps/installs "$TOKEN" "$OUT/iu.json" > /dev/null
cron
eq "$(row "$UU")" "integrity_failed/0/invalid" "a token Google cannot decode (400) is refuted"
has "$OUT/cron.txt" "invalid=1" "the worker says so"

say "require: a replayed token, and no token at all"
C=$(click "$TRK" "$OUT/hr"); REF=$(referrer_of "$OUT/hr")
UR=$(uuid); body "$OUT/ir.json" "$UR" "$REF" "$(ctime "$C")" tok-require-pass > /dev/null
DECODES_BEFORE=$(fake_requests "len([x for x in r if x.get('integrity_token') == 'tok-require-pass'])")
eq "$(device POST /apps/installs "$TOKEN" "$OUT/ir.json")" 200 "another install presents the token already verified for $U3"
cron
eq "$(row "$UR")" "integrity_failed/0/invalid" "it is refused as a replay"
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$UR'" | grep -c "already verified for install $U3")" 1 "naming the install that owns the token"
eq "$(fake_requests "len([x for x in r if x.get('integrity_token') == 'tok-require-pass'])")" "$DECODES_BEFORE" "without spending a decode"
eq "$(paid "$C")" 0 "unpaid"
C=$(click "$TRK" "$OUT/hn"); REF=$(referrer_of "$OUT/hn")
UN=$(uuid); body "$OUT/in.json" "$UN" "$REF" "$(ctime "$C")" - > /dev/null
eq "$(device POST /apps/installs "$TOKEN" "$OUT/in.json")" 200 "an install with no token"
eq "$(field "[d['data']['match'], d['data']['trusted'], d['data']['integrity']]")" '["integrity_unverified", null, "missing"]' "is unverified at once, unvouched"
eq "$(paid "$C")" 0 "and never paid"

say "require: a verdict past the 24-hour deadline is never accepted"
# A worker stopped for a day: the install is retired unverified before its
# token is decoded, never attributed or paid. (A passing verdict that comes
# back late is refused too: IntegrityIntegrationTest drives that one, since
# a live verdict for a backdated install reads as issued in the future.)
CL=$(click "$TRK" "$OUT/hl"); REFL=$(referrer_of "$OUT/hl")
UL=$(uuid); HL=$(body "$OUT/il.json" "$UL" "$REFL" "$(ctime "$CL")" tok-late)
scenario tok-late "[{\"status\": 200, \"payload\": $(verdict "$HL")}]"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/il.json")" 200 "an install held for its verdict"
Q "UPDATE 202_app_installs SET received_at = received_at - 90000 WHERE install_uuid='$UL'"
cron
has "$OUT/cron.txt" "error=1" "the backlogged install is retired"
eq "$(row "$UL")" "integrity_unverified/null/error" "unverified, without a verdict being judged"
eq "$(fake_requests "len([x for x in r if x.get('integrity_token') == 'tok-late'])")" 0 "its token is never decoded"
eq "$(paid "$CL")" 0 "and it is never paid"

say "require: Google failing is retried, then recorded unverified"
C5=$(click "$TRK" "$OUT/h5"); REF5=$(referrer_of "$OUT/h5")
U5=$(uuid); body "$OUT/i5.json" "$U5" "$REF5" "$(ctime "$C5")" tok-5xx > /dev/null
scenario tok-5xx '[{"status": 503, "json": {"error": {"code": 503, "message": "The service is currently unavailable.", "status": "UNAVAILABLE"}}}]'
device POST /apps/installs "$TOKEN" "$OUT/i5.json" > /dev/null
cron
has "$OUT/cron.txt" "1 retrying" "a 503 is a retry"
eq "$(Q "SELECT CONCAT_WS('/', match_state, integrity_state, integrity_attempts, integrity_next_at - UNIX_TIMESTAMP() BETWEEN 50 AND 61) FROM 202_app_installs WHERE install_uuid='$U5'")" \
   "pending_integrity/pending/1/1" "still waiting, attempt 1, next in a minute"
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$U5'" | grep -c 'Attempt 1: Play Integrity answered 503')" 1 "with Google's answer as the reason"
cron
eq "$(Q "SELECT integrity_attempts FROM 202_app_installs WHERE install_uuid='$U5'")" 1 "not retried before its backoff"
Q "UPDATE 202_app_installs SET integrity_next_at = 0 WHERE install_uuid='$U5'"
cron
eq "$(Q "SELECT CONCAT_WS('/', integrity_attempts, integrity_next_at - UNIX_TIMESTAMP() BETWEEN 110 AND 121) FROM 202_app_installs WHERE install_uuid='$U5'")" "2/1" "the backoff doubles"
Q "UPDATE 202_app_installs SET integrity_next_at = 0, received_at = received_at - 90000 WHERE install_uuid='$U5'"
cron
has "$OUT/cron.txt" "error=1" "past the 24-hour deadline the worker stops"
eq "$(row "$U5")" "integrity_unverified/null/error" "integrity_unverified, unvouched"
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$U5'" | grep -c 'No verdict within 24 hours of receipt; the token was not decoded')" 1 \
   "retired before another decode, saying the deadline passed"
eq "$(Q "SELECT integrity_attempts FROM 202_app_installs WHERE install_uuid='$U5'")" 3 "the attempt that found it expired is counted"
eq "$(paid "$C5")" 0 "never waved through"

C6=$(click "$TRK" "$OUT/h6"); REF6=$(referrer_of "$OUT/h6")
U6=$(uuid); H6=$(body "$OUT/i6.json" "$U6" "$REF6" "$(ctime "$C6")" tok-slow)
scenario tok-slow "[{\"status\": 200, \"delay\": 12, \"payload\": $(verdict "$H6")}, {\"status\": 200, \"payload\": $(verdict "$H6")}]"
device POST /apps/installs "$TOKEN" "$OUT/i6.json" > /dev/null
cron
eq "$(Q "SELECT CONCAT_WS('/', match_state, integrity_state) FROM 202_app_installs WHERE install_uuid='$U6'")" "pending_integrity/pending" "a timeout is a retry"
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$U6'" | grep -ci 'timed out')" 1 "named as one"
Q "UPDATE 202_app_installs SET integrity_next_at = 0 WHERE install_uuid='$U6'"
cron
eq "$(row "$U6")" "attributed/1/valid" "and the next attempt succeeds"
eq "$(paid "$C6")" 1 "paid once"

C7=$(click "$TRK" "$OUT/h7"); REF7=$(referrer_of "$OUT/h7")
U7=$(uuid); H7=$(body "$OUT/i7.json" "$U7" "$REF7" "$(ctime "$C7")" tok-grant)
scenario tok-grant "[{\"status\": 200, \"payload\": $(verdict "$H7")}]"
eq "$(fake /control/token-endpoint '{"responses": [{"status": 400, "json": {"error": "invalid_grant", "error_description": "Invalid JWT Signature."}}]}')" 200 "Google's token endpoint refuses the account"
device POST /apps/installs "$TOKEN" "$OUT/i7.json" > /dev/null
cron
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$U7'" | grep -c 'Google refused the service account integrity@p202-pi-pass')" 1 "a refused credential is a retry naming the account"
eq "$(fake_requests "len([x for x in r if x.get('integrity_token') == 'tok-grant'])")" 0 "and no decode is attempted without an access token"

# ─────────────────────────────────────────────────────────────────────
say "reads, the CLIs, and clearing the credential"
eq "$(api GET "/apps/$R/integrity")" 200 "the integrity status"
eq "$(field "[d['data']['integrity_mode'], d['data']['installs']['by_integrity_state']['valid'], d['data']['installs']['by_integrity_state']['invalid'], d['data']['installs']['by_match_state']['integrity_failed']]")" \
   '["require", 3, 9, 8]' "counts every verdict and the installs require refused"
hasnt "$OUT/body" "PRIVATE KEY" "never the key"
eq "$(api GET "/apps/$R/installs?integrity_state=error")" 200 "installs by integrity state"
eq "$(field "sorted(i['install_uuid'] for i in d['data'])")" "$(python3 -c 'import json,sys; print(json.dumps(sorted(sys.argv[1:])))' "$U5" "$UL")" \
   "the ones with no verdict: the one Google never answered, and the backlogged one"
eq "$(field "d['data'][0]['integrity_attempts']")" 3 "with its attempts"
eq "$(api GET "/apps/$R/installs/$U2")" 200 "one install"
eq "$(field "d['data']['integrity_verdict']['code']")" device_integrity "shows the verdict's summary"
hasnt "$OUT/body" "tok-observe-fail" "never the token"
eq "$(api DELETE "/apps/$R/integrity-credential")" 409 "the credential cannot be cleared while require needs it"
WAITING=$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE registration_id=$R AND integrity_state='pending'")
eq "$([ "$WAITING" -gt 0 ] && echo yes)" yes "an install is still waiting for its verdict (the one whose account Google refused)"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"off"}')" 200 "require switched off"
eq "$(api DELETE "/apps/$R/integrity-credential")" 409 "clearing is still refused: the waiting install keeps the mode it arrived under"
has "$OUT/body" "still waiting for a Play Integrity verdict" "saying why"
has "$OUT/body" "GET /apps/$R/integrity" "and where to watch them"
eq "$(Q "SELECT COUNT(*) FROM 202_app_integrity_credentials WHERE registration_id=$R")" 1 "the credential is still there"
Q "UPDATE 202_app_installs SET integrity_next_at = 0, received_at = received_at - 90000 WHERE registration_id=$R AND integrity_state='pending'"
cron
eq "$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE registration_id=$R AND integrity_state='pending'")" 0 "the worker settles it at its deadline"
eq "$(row "$U7")" "integrity_unverified/null/error" "as the deadline decides, with the credential still there to try"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"require"}')" 200 "back to require for the CLIs"
CLI="$OUT/p202"
if (cd "$ROOT/go-cli" && go build -o "$CLI" .) 2> "$OUT/build.err"; then
  mkdir -p "$OUT/home/.p202"
  printf '{"url": "%s", "api_key": "%s"}' "$BASE" "$P202_API_KEY" > "$OUT/home/.p202/config.json"
  p202() { HOME="$OUT/home" "$CLI" "$@"; }
  p202 app integrity status "$R" --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1]))['data']; print(d['integrity_mode'], d['credential']['client_email'])" "$OUT/cli.json" 2>/dev/null)" \
     "require integrity@p202-pi-pass.iam.gserviceaccount.com" "p202 app integrity status"
  p202 app install list "$R" --integrity-state invalid --json > "$OUT/cli.json" 2> /dev/null
  eq "$(python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))['data']))" "$OUT/cli.json" 2>/dev/null)" 9 "p202 app install list --integrity-state invalid"
  p202 app integrity credential clear "$R" --force --json > /dev/null 2> "$OUT/cli.err"
  has "$OUT/cli.err" "--integrity-mode off" "clearing under require: the CLI's hint says to switch the mode off"
  p202 app update "$R" --integrity-mode off --json > /dev/null 2>&1
  eq "$(Q "SELECT integrity_mode FROM 202_app_registrations WHERE registration_id=$R")" off "p202 app update --integrity-mode off"
  p202 app integrity credential set "$R" --file "$OUT/key.json" --json > /dev/null 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; e=json.load(open(sys.argv[1]))['error']; print(e['category'], 'service_account' in e['message'])" "$OUT/cli.err" 2>/dev/null)" "validation True" \
     "an API request body is not a key file: the CLI says so before sending"
  python3 -c "import json,sys; json.dump(json.load(open(sys.argv[1]))['credential'], open(sys.argv[2], 'w'))" "$OUT/key.json" "$OUT/key-file.json"
  p202 app integrity credential set "$R" --file "$OUT/key-file.json" --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['credential']['private_key_id'])" "$OUT/cli.json" 2>/dev/null)" a1b2c3d4e5f60718 "p202 app integrity credential set (rotation)"
  hasnt "$OUT/cli.json" "PRIVATE KEY" "the CLI prints no key"
  p202 app integrity credential set "$R" --file "$OUT/key-file.json" --staged --json > /dev/null 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['error']['category'])" "$OUT/cli.err" 2>/dev/null)" validation "credential set refuses --staged"
  p202 app integrity credential clear "$R" --force --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['message'])" "$OUT/cli.json" 2>/dev/null)" "The Play Integrity credential was deleted." "p202 app integrity credential clear, with its message"
  eq "$(Q "SELECT COUNT(*) FROM 202_app_integrity_credentials")" 0 "the credential is gone"
else
  bad "the CLI builds ($(head -3 "$OUT/build.err"))"
fi
if [ -f "$ROOT/vendor/symfony/console/Application.php" ]; then
  PHPCLI=("$PHP" -d "auto_prepend_file=$ROOT/vendor/symfony/deprecation-contracts/function.php" "$ROOT/bin/p202")
  mkdir -p "$OUT/phphome"
  HOME="$OUT/phphome" "${PHPCLI[@]}" config:set-url "$BASE" > /dev/null 2>&1
  HOME="$OUT/phphome" "${PHPCLI[@]}" config:set-key "$P202_API_KEY" > /dev/null 2>&1
  HOME="$OUT/phphome" "${PHPCLI[@]}" app:integrity:credential:set "$R" --file "$OUT/key-file.json" --json > "$OUT/php.json" 2>&1
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['credential']['client_email'])" "$OUT/php.json" 2>/dev/null)" \
     "integrity@p202-pi-pass.iam.gserviceaccount.com" "PHP CLI app:integrity:credential:set"
  HOME="$OUT/phphome" "${PHPCLI[@]}" app:integrity:mode "$R" observe --json > /dev/null 2>&1
  eq "$(Q "SELECT integrity_mode FROM 202_app_registrations WHERE registration_id=$R")" observe "PHP CLI app:integrity:mode"
  HOME="$OUT/phphome" "${PHPCLI[@]}" app:integrity:status "$R" --json > "$OUT/php.json" 2>&1
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['integrity_mode'])" "$OUT/php.json" 2>/dev/null)" observe "PHP CLI app:integrity:status"
  HOME="$OUT/phphome" "${PHPCLI[@]}" app:integrity:credential:clear "$R" --force > "$OUT/php.txt" 2>&1
  has "$OUT/php.txt" "409" "PHP CLI app:integrity:credential:clear is refused under observe"
  HOME="$OUT/phphome" "${PHPCLI[@]}" app:integrity:mode "$R" off > /dev/null 2>&1
  HOME="$OUT/phphome" "${PHPCLI[@]}" app:integrity:credential:clear "$R" --force > "$OUT/php.txt" 2>&1
  has "$OUT/php.txt" "The Play Integrity credential was deleted." "and clears once the mode is off, saying so"
else
  echo "  (PHP CLI not exercised: vendor/symfony/console is missing)"
fi

# ─────────────────────────────────────────────────────────────────────
say "deleting the registration settles what its installs were waiting for"
eq "$(api_file PUT "/apps/$R/integrity-credential" "$OUT/key.json")" 200 "the service account again"
eq "$(api PUT "/apps/$R" '{"integrity_mode":"require"}')" 200 "require (the project number is still set)"
C8=$(click "$TRK" "$OUT/h8"); REF8=$(referrer_of "$OUT/h8")
U8=$(uuid); body "$OUT/i8.json" "$U8" "$REF8" "$(ctime "$C8")" tok-orphan > /dev/null
device POST /apps/installs "$TOKEN" "$OUT/i8.json" > /dev/null
eq "$(field "[d['data']['match'], d['data']['integrity']]")" '["pending_integrity", "pending"]' "an install waits for its verdict"
eq "$(api DELETE "/apps/$R?dry_run=1")" 200 "the delete's preview"
has "$OUT/body" "settle the Play Integrity queue" "names the settlement in its cascade"
eq "$(row "$U8")" "pending_integrity/null/pending" "(the preview changed nothing)"
eq "$(api DELETE "/apps/$R")" 204 "the registration is deleted"
eq "$(row "$U8")" "integrity_unverified/null/error" "the waiting install is settled unverified, in the same delete"
eq "$(Q "SELECT integrity_reason FROM 202_app_installs WHERE install_uuid='$U8'" | grep -c 'registration was deleted')" 1 "saying why"
eq "$(Q "SELECT integrity_next_at IS NULL AND settled_at IS NOT NULL FROM 202_app_installs WHERE install_uuid='$U8'")" 1 "no longer due, and settled"
eq "$(paid "$C8")" 0 "never paid"
eq "$(Q "SELECT COUNT(*) FROM 202_app_integrity_credentials")" 0 "the credential went with the registration"
cron
has "$OUT/cron.txt" "play integrity: 0 examined" "nothing is left for the worker"
eq "$(fake_requests "len([x for x in r if x.get('integrity_token') == 'tok-orphan'])")" 0 "and its token was never sent to Google"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
