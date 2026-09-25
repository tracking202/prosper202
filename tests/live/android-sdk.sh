#!/bin/bash
# Live pass for the Android SDK (PR 7): the SDK's own engine — its file
# store, HttpURLConnection transport, worker thread and retry rules — driven
# against a running instance by sdk/android-attribution's LiveServerTest,
# with the setup done here as an operator would and the database read back
# afterwards:
#
#   - a web click carrying a signed customer id (cust + cust_sig), and a
#     phone's click through the same store link, whose Location referrer the
#     SDK is handed as Play would hand it;
#   - device A: the install (attributed, the install conversion on the
#     click), three events through the goal engine (level 3 pays $4 on top
#     of the install's $2.50), then setCustomerId — the phone's click joins
#     the web click's visitor; a relaunch sends nothing;
#   - device B: an organic install whose customer rode the install body
#     (stored with it, links nothing: no click);
#   - device C: a forged token (bad_token), whose events are refused and
#     dropped;
#   - the SDK's persisted install body, replayed byte for byte, is a
#     duplicate, and with one field changed it is a 409.
#
# Needs a JDK and Gradle (P202_GRADLE, default `gradle`); the SDK's
# dependencies come from Maven Central on the first run. Truncates the app,
# goal and outbox tables, so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
GRADLE=${P202_GRADLE:-gradle}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass drives the REST API as the instance's admin." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

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

api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
device() { # METHOD PATH TOKEN BODY-FILE
    curl -s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "X-P202-App-Token: $3" -H 'Content-Type: application/json' --data-binary "@$4" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
live() { python3 -c "import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])" "$OUT/live.json" "$1" 2>/dev/null; }
UA='Mozilla/5.0 (Linux; Android 14; Pixel 8) android-sdk-pass'
click() { # TRACKER HEADERS [EXTRA-QUERY] — one click through the redirect; prints the click id.
    local before after
    before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    curl -s -o /dev/null -D "$2" -A "$UA" "$BASE/tracking202/redirect/dl.php?t202id=$1${3:-}"
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

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }

cleanup() {
  mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%'));
DELETE FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%');
DELETE FROM 202_dataengine WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%');
DELETE FROM 202_clicks_visitor WHERE click_id IN (SELECT click_id FROM 202_clicks WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%'));
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%');
DELETE FROM 202_clicks WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%');
DELETE FROM 202_trackers WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%');
DELETE FROM 202_ppc_accounts WHERE ppc_account_name = 'android-sdk-pass';
DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-sdk-pass%';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
TRUNCATE 202_app_registrations; TRUNCATE 202_app_installs; TRUNCATE 202_notification_pending;
SQL
}
cleanup
trap cleanup EXIT

# A previous pass may have spent this peer's intake bucket: wait it out.
for _ in 1 2 3; do
    code=$(curl -s -o /dev/null -D "$OUT/probe" -w '%{http_code}' "$BASE/api/v3/apps/installs")
    [ "$code" = 429 ] || break
    wait_s=$(awk 'tolower($1) == "retry-after:" {print $2+0}' "$OUT/probe")
    sleep "${wait_s:-60}"
done

# ─────────────────────────────────────────────────────────────────────
say "setup: an Android app, a campaign whose store link carries the install token, goals"
eq "$(api POST /apps '{"store_link":"https://play.google.com/store/apps/details?id=com.p202.sdk.summit","app_name":"Summit"}')" 201 "the app, from its Play link"
R=$(field "d['data']['registration_id']"); TOKEN=$(field "d['data']['app_token']")
eq "$(api POST /aff-networks '{"aff_network_name":"android-sdk-pass"}')" 201 "an affiliate network"
NET=$(field "d['data']['aff_network_id']")
STORE='https://play.google.com/store/apps/details?id=com.p202.sdk.summit&referrer=p202%3D[[p202_install_token]]'
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"android-sdk-pass\",\"aff_campaign_url\":\"$STORE\",\"aff_campaign_payout\":\"2.50\",\"aff_network_id\":$NET,\"payout_mode\":\"accumulate\",\"app_registration_id\":$R}")" 201 \
   "a campaign on the store link, linked to the app"
CAMP=$(field "d['data']['aff_campaign_id']")
eq "$(api POST /ppc-networks '{"ppc_network_name":"android-sdk-pass"}')" 201 "a traffic source"
PNET=$(field "d['data']['ppc_network_id']")
eq "$(api POST /ppc-accounts "{\"ppc_account_name\":\"android-sdk-pass\",\"ppc_network_id\":$PNET}")" 201 "and its account"
PACC=$(field "d['data']['ppc_account_id']")
eq "$(api POST /trackers "{\"aff_campaign_id\":$CAMP,\"ppc_account_id\":$PACC}")" 201 "a tracker"
TRK=$(field "d['data']['tracker_id_public']")
eq "$(api GET "/goals?scope=registration&scope_id=$R")" 200 "the registration's goals"
G_INSTALL=$(field "d['data'][0]['goal_id']")
eq "$(api PUT "/goals/$G_INSTALL/campaigns/$CAMP" '{}')" 200 "the campaign pays for the install"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP,\"payout\":4,\"definition\":{\"name\":\"Level 3\",\"trigger\":{\"event\":\"level_reached\",\"where\":[{\"prop\":\"level\",\"op\":\"gte\",\"value\":3}]}}}")" 201 \
   "and \$4 for level 3"

eq "$(api GET "/users/$OWNER/identity-key")" 200 "the account's linking key (the operator's server holds it)"
LINK=$(field "d['data']['linking_key']")
CUST=u-829
SIG=$(python3 -c "import hmac,hashlib,sys; print(hmac.new(bytes.fromhex(sys.argv[1]), ('custom:'+sys.argv[2]).encode(), hashlib.sha256).hexdigest())" "$LINK" "$CUST")

say "two clicks: the customer on the web, then the phone through the store link"
CW=$(click "$TRK" "$OUT/hw" "&cust=$CUST&cust_type=custom&cust_sig=$SIG")
[ -n "$CW" ] && ok "the web click ($CW)" || bad "no web click recorded"
# The person a click belongs to: its visitor key, or what that key was merged into.
person() { Q "SELECT COALESCE(v.alias_of, cv.visitor_key) FROM 202_clicks_visitor cv JOIN 202_identity_visitors v ON v.visitor_key = cv.visitor_key WHERE cv.click_id = $1"; }
VW=$(person "$CW")
[ -n "$VW" ] && ok "is linked to the signed customer's visitor ($VW)" || bad "the web click has no visitor"
CP=$(click "$TRK" "$OUT/hp")
[ -n "$CP" ] && ok "the phone's click ($CP)" || bad "no phone click recorded"
REF=$(referrer_of "$OUT/hp")
eq "$(printf '%s' "$REF" | grep -cE "^p202=$CP\.[A-Za-z0-9_-]{16}$")" 1 "its store link carries p202=<click>.<signature>"
VP_BEFORE=$(person "$CP")
[ "$VP_BEFORE" != "$VW" ] && ok "a different browser: not yet the customer's visitor" || bad "the phone's click already had the web visitor"

# ─────────────────────────────────────────────────────────────────────
say "the SDK's engine against this instance (sdk/android-attribution LiveServerTest)"
if P202_LIVE_BASE="$BASE" P202_LIVE_APP_TOKEN="$TOKEN" P202_LIVE_APP_KEY=com.p202.sdk.summit P202_LIVE_REFERRER="$REF" \
   P202_LIVE_CLICK_TIME="$(Q "SELECT click_time FROM 202_clicks WHERE click_id=$CP")" \
   P202_LIVE_CUSTOMER_ID="$CUST" P202_LIVE_CUSTOMER_SIG="$SIG" P202_LIVE_OUT="$OUT/live.json" \
   "$GRADLE" -p "$ROOT/sdk/android-attribution" --no-daemon -q :core:test --tests '*LiveServerTest*' --rerun > "$OUT/gradle.log" 2>&1; then
    ok "LiveServerTest passed: install, events, customer, a relaunch, an organic and a forged install"
else
    bad "LiveServerTest failed (see $OUT/gradle.log)"
    sed -n '1,60p' "$OUT/gradle.log" | grep -v JAVA_TOOL_OPTIONS
fi
[ -s "$OUT/live.json" ] && ok "and wrote what it did" || bad "LiveServerTest wrote no results (skipped?)"
UA_=$(live install_a); UB=$(live install_b); UC=$(live install_c); STORE_A=$(live store_a)

say "device A, as the server recorded it"
eq "$(Q "SELECT CONCAT_WS('/', match_state, trusted, click_id, sdk_version, app_version, os_version, is_test) FROM 202_app_installs WHERE install_uuid='$UA_'")" \
   "attributed/1/$CP/1.0.0/3.2.0/15/0" "attributed to the phone's click, with the SDK's version facts"
eq "$(Q "SELECT CONCAT_WS('/', source, dedupe_key, pixel_type, click_payout) FROM 202_conversion_logs WHERE click_id=$CP AND dedupe_key='install'")" \
   "app_install/install/4/2.50000" "the install conversion on the click"
EVENTS=$(python3 -c "import json,sys; print(','.join(\"'\"+e+\"'\" for e in json.load(open(sys.argv[1]))['events_a']))" "$OUT/live.json" 2>/dev/null)
eq "$(Q "SELECT COUNT(*) FROM 202_goal_events e JOIN 202_app_installs i ON e.subject_type='install' AND e.subject_id=i.install_row_id WHERE i.install_uuid='$UA_' AND e.event_id IN (${EVENTS:-''})")" 3 \
   "the three events, stored under the ids the SDK minted"
eq "$(Q "SELECT CONCAT_WS('/', e.name, JSON_EXTRACT(e.properties, '\$.sku'), e.revenue + 0, e.transaction_id) FROM 202_goal_events e JOIN 202_app_installs i ON e.subject_type='install' AND e.subject_id=i.install_row_id WHERE i.install_uuid='$UA_' AND e.name='purchase'" 2>/dev/null)" \
   'purchase/"gems_100"/4.99/GPA.live-1' "the purchase with its properties, revenue and transaction id"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$CP AND dedupe_key LIKE 'goal:%' AND payable=1 AND superseded_by IS NULL AND deleted=0")" 1 "level 3 reached, once"
eq "$(Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$CP")" "1/6.50000" "the click is worth \$2.50 + \$4.00"
eq "$(person "$CP")" "$(person "$CW")" "setCustomerId joined the phone's click to the web click's person"
eq "$(Q "SELECT raw_payload LIKE '%\"customer\"%' FROM 202_app_installs WHERE install_uuid='$UA_'")" 0 "the id was set after the install: it rode the events route, not the body"

say "device B: an organic install whose customer rode its body"
eq "$(Q "SELECT CONCAT_WS('/', match_state, ISNULL(click_id), raw_payload LIKE '%\"customer\":{\"id\":\"$CUST\"%') FROM 202_app_installs WHERE install_uuid='$UB'")" "organic/1/1" \
   "organic, no click, the claim kept with the body"

say "device C: a forged token"
eq "$(Q "SELECT CONCAT_WS('/', match_state, trusted) FROM 202_app_installs WHERE install_uuid='$UC'")" "bad_token/0" "refuted"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_subjects s JOIN 202_app_installs i ON s.subject_type='install' AND s.subject_id=i.install_row_id WHERE i.install_uuid='$UC'")" 0 "and its events reached no goal"

say "the persisted body is the install: replayed, it is a duplicate; changed, a conflict"
python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['install_body'])" "$STORE_A" > "$OUT/a-body.json" 2>/dev/null
eq "$(device POST /apps/installs "$TOKEN" "$OUT/a-body.json")" 200 "the SDK's stored body, sent again"
eq "$(field "[d['data']['match'], d['data']['duplicate']]")" '["attributed", true]' "is answered as the recorded install"
python3 -c "import json,sys; b=json.load(open(sys.argv[1])); b['app_version']='9.9.9'; json.dump(b, open(sys.argv[2],'w'))" "$OUT/a-body.json" "$OUT/a-changed.json"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/a-changed.json")" 409 "with one field changed, the same install_uuid is refused"
# Well formed, but over another id: what someone without the linking key can make.
FORGED=$(python3 -c "import hmac,hashlib,sys; print(hmac.new(bytes.fromhex(sys.argv[1]), b'custom:someone-else', hashlib.sha256).hexdigest())" "$LINK")
printf '{"customer":{"id":"%s","type":"custom","signature":"%s"}}' "$CUST" "$FORGED" > "$OUT/forged-customer.json"
eq "$(device POST "/apps/installs/$UA_/events" "$TOKEN" "$OUT/forged-customer.json")" 200 "a customer with a signature the operator never made"
eq "$(field "d['data']['customer']")" unverified "is answered unverified and links nothing"
has "$ROOT/tests/fixtures/app-sdk-contract/android/events-requests.json" '"a customer alone"' "(the shape is in the shared vectors)"

# The SDK's stores (one directory the Kotlin test made) are read; drop them.
case "$STORE_A" in /*/a.json) rm -rf "$(dirname "$STORE_A")" ;; esac

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
