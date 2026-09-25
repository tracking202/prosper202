#!/bin/bash
# Live pass for the Mobile Apps UI (PR 11): Setup › Mobile Apps and Analyze ›
# Mobile Apps driven over HTTP the way an operator uses them, the database
# read back after every write, and the report's numbers checked against the
# rows that produced them:
#
#   - registering an Android app and an iOS app from their store links: the
#     platform read from the link, the name and the icon from the store (a
#     local fake of both stores, tests/fixtures/app-store/fake_store.py), a
#     name asked for only when the store does not answer, an icon on another
#     host refused, a re-paste answered with the app;
#   - an Android app's settings (the window read raw: `07` refused), Play
#     Integrity in PR 6's order (a mode before its credential refused, a
#     key that is not JSON refused and never echoed back, the credential set,
#     a mode without its project number refused, observe with it, the
#     credential's deletion refused while the mode needs it);
#   - app goals and their funnel: the form's goal with its window from the
#     install and `after`, an API refusal under its field, a goal the form
#     cannot show listed with `p202 goal update`;
#   - an iOS app's SKAN values naming a goal from the menu, and the horizon
#     warning on an edit;
#   - the link builder: a campaign made ready (the Play link with the install
#     token, linked to the app; the App Store link for iOS) and the store
#     link a real click redirects to;
#   - installs and events through the public intake (attributed through a
#     real click, organic, forged), then GET /apps/report and the Analyze
#     page — both platforms, Android, the funnel, the match states — against
#     the database, the CLI's `app report` beside them when P202_BIN is set;
#   - the notification outbox's reads and a traffic source's correction URL,
#     set on Setup › Traffic Sources.
#
# The web server must run with P202_APP_STORE_LOOKUP_ORIGIN pointing at
# 127.0.0.1:$P202_CAPTURE_PORT (default 8331), where this pass starts the
# fake store: the stores are not reachable from a sandbox or CI, and
# StoreListing accepts only a loopback override. Truncates the app, goal and
# outbox tables, so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
CAPTURE_PORT=${P202_CAPTURE_PORT:-8331}
CLI=${P202_BIN:-}
CLI_HOME=${P202_CLI_HOME:-}

if [ -z "$P202_PASS" ] || [ -z "$P202_API_KEY" ]; then
    echo "P202_PASS and P202_API_KEY are required: this pass logs in as $P202_USER and drives the REST API as the admin." >&2
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
JAR="$OUT/jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
clean(){ # FILE — no PHP error text in a page
    if grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$1"; then bad "$2 (PHP error in the page)"; else ok "$2"; fi
}

# api METHOD PATH [JSON-BODY] — as the operator; body in $OUT/body, status printed.
api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# device METHOD PATH TOKEN [BODY-FILE] — as the SDK: the app token header only.
device() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "X-P202-App-Token: $3")
    [ -n "${4:-}" ] && args+=(-H 'Content-Type: application/json' --data-binary "@$4")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }

# get PATH OUT — a page as the signed-in operator.
get() { curl -sS -b "$JAR" -c "$JAR" -L "$BASE$1" -o "$2"; }
# post PATH FORM-PAGE OUT field=value... — submit a form with the csrf_token of
# the page it is on, following the post-redirect-get; the number of redirects
# is in $REDIRECTS (1 = saved, 0 = the form came back refused).
post() {
    local path="$1" page="$2" out="$3" tok
    shift 3
    tok=$(grep -oE 'name="csrf_token" value="[^"]+"' "$page" | head -1 | sed 's/.*value="//; s/"//')
    local args=()
    for kv in "$@"; do args+=(--data-urlencode "$kv"); done
    REDIRECTS=$(curl -sS -b "$JAR" -c "$JAR" -L "$BASE$path" --data-urlencode "csrf_token=$tok" "${args[@]}" -o "$out" -w '%{num_redirects}')
}
msgs() { grep -oE '<div class="p202-flash__body">[^<]*|<div class="invalid-feedback d-block">[^<]*' "$1" | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }
uuid() { python3 -c 'import uuid; print(uuid.uuid4())'; }
SETUP=/tracking202/setup/mobile_apps.php
ANALYZE=/tracking202/analyze/mobile_apps.php

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }

STORE_PID=
cleanup() {
    [ -n "$STORE_PID" ] && kill "$STORE_PID" 2> /dev/null
    mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%'));
DELETE FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%');
DELETE FROM 202_dataengine WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%');
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%');
DELETE FROM 202_clicks WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%');
DELETE FROM 202_trackers WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%');
DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN (SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name LIKE 'apps-ui-pass%');
DELETE FROM 202_ppc_accounts WHERE ppc_account_name LIKE 'apps-ui-pass%';
DELETE FROM 202_ppc_networks WHERE ppc_network_name LIKE 'apps-ui-pass%';
DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'apps-ui-pass%';
DELETE FROM 202_aff_networks WHERE aff_network_name LIKE 'apps-ui-pass%';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
TRUNCATE 202_app_registrations; TRUNCATE 202_app_installs; TRUNCATE 202_app_postbacks;
TRUNCATE 202_app_skan_encodings; TRUNCATE 202_app_skan_encoding_history; TRUNCATE 202_app_integrity_credentials;
TRUNCATE 202_notification_pending; TRUNCATE 202_notification_correction_urls;
SQL
}
cleanup
trap cleanup EXIT

# A previous pass may have spent this peer's intake bucket: wait it out
# rather than read 429s as defects.
for _ in 1 2 3; do
    code=$(curl -s -o /dev/null -D "$OUT/probe" -w '%{http_code}' "$BASE/api/v3/apps/installs")
    [ "$code" = 429 ] || break
    wait_s=$(awk 'tolower($1) == "retry-after:" {print $2+0}' "$OUT/probe")
    sleep "${wait_s:-60}"
done

say "the fake store, and a signed-in operator"
python3 "$ROOT/tests/fixtures/app-store/fake_store.py" --port "$CAPTURE_PORT" > "$OUT/store.out" 2> "$OUT/store.err" &
STORE_PID=$!
for _ in $(seq 1 50); do grep -q READY "$OUT/store.out" 2> /dev/null && break; sleep 0.2; done
has "$OUT/store.out" "READY $CAPTURE_PORT" "the fake store listens on $CAPTURE_PORT"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
    --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o /dev/null
get "$SETUP" "$OUT/list.html"
has "$OUT/list.html" "202-account/signout.php" "the signed-in chrome offers a sign-out"
has "$OUT/list.html" "p202-shell-v2" "Setup › Mobile Apps is on the v2 shell"
clean "$OUT/list.html" "the list renders clean"
has "$OUT/list.html" 'App Store or Google Play link' "one field registers either platform"

# ─────────────────────────────────────────────────────────────────────
say "register: the platform from the link, the name and icon from the store"
post "$SETUP" "$OUT/list.html" "$OUT/reg-a.html" action=register "app_reference=https://play.google.com/store/apps/details?id=com.p202.live.summit&hl=en"
msgs "$OUT/reg-a.html"
eq "$REDIRECTS" 1 "the Play link registered (post-redirect-get)"
RA=$(Q "SELECT registration_id FROM 202_app_registrations WHERE app_key='com.p202.live.summit'")
eq "$(Q "SELECT CONCAT(platform, '|', app_name) FROM 202_app_registrations WHERE registration_id='$RA'")" "android|Summit Quest" \
   "Android, named from the Play listing's og:title without \" - Apps on Google Play\" (needs P202_APP_STORE_LOOKUP_ORIGIN=http://127.0.0.1:$CAPTURE_PORT on the server)"
eq "$(Q "SELECT app_icon LIKE 'data:image/gif;base64,%' FROM 202_app_registrations WHERE registration_id='$RA'")" 1 "the icon fetched once and kept as a data: URI (a GIF, by its bytes)"
eq "$(Q "SELECT COUNT(*) FROM 202_goals WHERE scope='registration' AND scope_id='$RA' AND builtin='install'")" 1 "with its built-in install goal"
has "$OUT/reg-a.html" 'src="data:image/gif;base64,' "the page shows the stored icon, so the viewer's browser calls no store"
has "$OUT/reg-a.html" 'id=com.p202.live.summit&amp;referrer=p202%3D[[p202_install_token]]' "the app page builds the Play link with the install token"
clean "$OUT/reg-a.html" "the Android app page renders clean"

get "$SETUP" "$OUT/list.html"
post "$SETUP" "$OUT/list.html" "$OUT/reg-i.html" action=register "app_reference=https://apps.apple.com/us/app/summit-run/id990077001"
eq "$(Q "SELECT CONCAT(platform, '|', app_name, '|', app_icon LIKE 'data:image/png;base64,%') FROM 202_app_registrations WHERE app_key='990077001'")" "ios|Summit Run|1" \
   "an App Store link: iOS, named and iconed from the lookup service"
RI=$(Q "SELECT registration_id FROM 202_app_registrations WHERE app_key='990077001'")
has "$OUT/reg-i.html" 'https://apps.apple.com/app/id990077001' "the iOS page gives the App Store link"
has "$OUT/reg-i.html" 'NSAdvertisingAttributionReportEndpoint' "and the Info.plist keys"
clean "$OUT/reg-i.html" "the iOS app page renders clean"

get "$SETUP" "$OUT/list.html"
post "$SETUP" "$OUT/list.html" "$OUT/reg-n.html" action=register "app_reference=com.p202.live.nameless"
msgs "$OUT/reg-n.html"
eq "$REDIRECTS" 0 "a listing the store does not have: the form comes back"
has "$OUT/reg-n.html" "Google Play did not answer, so the name could not be looked up" "asking for the name only, in the store's words"
has "$OUT/reg-n.html" "Read as Android · Package com.p202.live.nameless" "and saying what it read from the link"
eq "$(Q "SELECT COUNT(*) FROM 202_app_registrations WHERE app_key='com.p202.live.nameless'")" 0 "nothing registered under a placeholder"
post "$SETUP" "$OUT/reg-n.html" "$OUT/reg-n2.html" action=register "app_reference=com.p202.live.nameless" "app_name=Nameless"
eq "$(Q "SELECT CONCAT(app_name, '|', app_icon IS NULL) FROM 202_app_registrations WHERE app_key='com.p202.live.nameless'")" "Nameless|1" "the typed name registers it, with no icon"

get "$SETUP" "$OUT/list.html"
post "$SETUP" "$OUT/list.html" "$OUT/reg-e.html" action=register "app_reference=com.p202.live.noicon"
eq "$(Q "SELECT CONCAT(app_name, '|', app_icon IS NULL) FROM 202_app_registrations WHERE app_key='com.p202.live.noicon'")" "No Icon & Co|1" \
   "an entity-encoded title decoded; an og:image on another host never fetched"
curl -s "http://127.0.0.1:$CAPTURE_PORT/control/requests" > "$OUT/store-requests.json"
hasnt "$OUT/store-requests.json" "evil" "the fake store saw no request for the foreign icon (it would have gone elsewhere, and did not go anywhere)"

get "$SETUP" "$OUT/list.html"
post "$SETUP" "$OUT/list.html" "$OUT/reg-d.html" action=register "app_reference=https://play.google.com/store/apps/details?id=com.p202.live.summit"
has "$OUT/reg-d.html" "You had already registered this app. Here it is." "a re-paste opens the app"
eq "$(Q "SELECT COUNT(*) FROM 202_app_registrations WHERE app_key='com.p202.live.summit'")" 1 "and registers nothing twice"
get "$SETUP" "$OUT/list.html"
post "$SETUP" "$OUT/list.html" "$OUT/reg-j.html" action=register "app_reference=not a link"
has "$OUT/reg-j.html" "Must be an App Store link" "junk gets AppIdentity's sentence under the field"

# ─────────────────────────────────────────────────────────────────────
say "settings: the Android window is read raw"
get "$SETUP?app=$RA" "$OUT/app-a.html"
post "$SETUP" "$OUT/app-a.html" "$OUT/set1.html" action=update return_to=app "registration_id=$RA" platform=android "app_name=Summit Quest" notes= attribution_window_days=07
msgs "$OUT/set1.html"
eq "$REDIRECTS" 0 "07 is refused, not read as 7"
has "$OUT/set1.html" "must be a whole number from 1 to 365" "in the API's words, under the field"
eq "$(Q "SELECT attribution_window_days FROM 202_app_registrations WHERE registration_id=$RA")" 7 "nothing changed"
post "$SETUP" "$OUT/app-a.html" "$OUT/set2.html" action=update return_to=app "registration_id=$RA" platform=android "app_name=Summit Quest" notes=live attribution_window_days=14 trust_client_revenue=1
eq "$REDIRECTS" 1 "14 and client revenue saved"
eq "$(Q "SELECT CONCAT(attribution_window_days, '|', trust_client_revenue, '|', notes) FROM 202_app_registrations WHERE registration_id=$RA")" "14|1|live" "and stored"
has "$OUT/set2.html" "Changes saved." "the app page says so"

# ─────────────────────────────────────────────────────────────────────
say "Play Integrity, in PR 6's order"
get "$SETUP?app=$RA" "$OUT/app-a.html"
post "$SETUP" "$OUT/app-a.html" "$OUT/pi1.html" action=integrity_mode "registration_id=$RA" integrity_mode=observe integrity_cloud_project_number=123456789012
msgs "$OUT/pi1.html"
eq "$REDIRECTS" 0 "observe before a service account is refused"
has "$OUT/pi1.html" "integrity-credential" "and the sentence says to set the credential first"
eq "$(Q "SELECT integrity_mode FROM 202_app_registrations WHERE registration_id=$RA")" off "the mode stays off"

post "$SETUP" "$OUT/app-a.html" "$OUT/pi2.html" action=integrity_credential "registration_id=$RA" 'credential={"type": "service_account", "private_key": "SECRETMARKER-not-json'
msgs "$OUT/pi2.html"
has "$OUT/pi2.html" "That is not JSON" "a key that is not JSON is refused by name"
hasnt "$OUT/pi2.html" "SECRETMARKER" "and never echoed back into the page"

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$OUT/sa.pem" 2> /dev/null
python3 - "$OUT/sa.pem" "$OUT/key.json" <<'PY'
import json, sys
pem = open(sys.argv[1]).read()
json.dump({"type": "service_account", "project_id": "p202-ui-pass", "private_key_id": "0f1e2d3c4b5a6978", "private_key": pem,
           "client_email": "integrity@p202-ui-pass.iam.gserviceaccount.com", "client_id": "1",
           "token_uri": "https://oauth2.googleapis.com/token"}, open(sys.argv[2], "w"))
PY
TOK=$(grep -oE 'name="csrf_token" value="[^"]+"' "$OUT/app-a.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -b "$JAR" -c "$JAR" -L "$BASE$SETUP" -F "csrf_token=$TOK" -F action=integrity_credential -F "registration_id=$RA" \
    -F "credential_file=@$OUT/key.json;type=application/json" -o "$OUT/pi3.html"
msgs "$OUT/pi3.html"
eq "$(Q "SELECT client_email FROM 202_app_integrity_credentials WHERE registration_id=$RA")" "integrity@p202-ui-pass.iam.gserviceaccount.com" "the key file uploads as the credential"
has "$OUT/pi3.html" "integrity@p202-ui-pass.iam.gserviceaccount.com · key 0f1e2d3c4b5a6978" "the page names the service account and its key id"
hasnt "$OUT/pi3.html" "BEGIN PRIVATE KEY" "and never the key"
post "$SETUP" "$OUT/pi3.html" "$OUT/pi4.html" action=integrity_mode "registration_id=$RA" integrity_mode=observe
msgs "$OUT/pi4.html"
eq "$REDIRECTS" 0 "observe without a Cloud project number is refused"
has "$OUT/pi4.html" "integrity_cloud_project_number" "naming the number"
post "$SETUP" "$OUT/pi3.html" "$OUT/pi5.html" action=integrity_mode "registration_id=$RA" integrity_mode=observe integrity_cloud_project_number=123456789012
eq "$(Q "SELECT CONCAT(integrity_mode, '|', integrity_cloud_project_number) FROM 202_app_registrations WHERE registration_id=$RA")" "observe|123456789012" "observe, with its number"
post "$SETUP" "$OUT/pi5.html" "$OUT/pi6.html" action=integrity_clear "registration_id=$RA"
msgs "$OUT/pi6.html"
has "$OUT/pi6.html" "set integrity_mode to off" "the credential cannot be deleted while observe needs it"
eq "$(Q "SELECT COUNT(*) FROM 202_app_integrity_credentials WHERE registration_id=$RA")" 1 "and is still there"
post "$SETUP" "$OUT/pi5.html" "$OUT/pi7.html" action=integrity_mode "registration_id=$RA" integrity_mode=off
eq "$(Q "SELECT integrity_mode FROM 202_app_registrations WHERE registration_id=$RA")" off "back to off (the installs below are then not held)"

# ─────────────────────────────────────────────────────────────────────
say "goals and the funnel"
get "$SETUP?app=$RA" "$OUT/app-a.html"
G_INSTALL=$(Q "SELECT goal_id FROM 202_goals WHERE scope='registration' AND scope_id=$RA AND builtin='install'")
post "$SETUP" "$OUT/app-a.html" "$OUT/g1.html" action=goal_save "registration_id=$RA" goal_id= "goal_name=Tutorial" goal_event=tutorial_done \
    goal_value=none goal_count=1 goal_repeat=once goal_within_days=7 "goal_after=$G_INSTALL" goal_where_prop= goal_where_op=eq goal_where_value= goal_where_type=auto
msgs "$OUT/g1.html"
eq "$REDIRECTS" 1 "a goal from the form"
G_TUT=$(Q "SELECT goal_id FROM 202_goals WHERE scope='registration' AND scope_id=$RA AND name='Tutorial'")
eq "$(Q "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(definition, '$.within.from')), JSON_EXTRACT(definition, '$.within.days'), JSON_EXTRACT(definition, '$.after')) FROM 202_goal_versions WHERE goal_id='$G_TUT'")" "install|7|[$G_INSTALL]" \
   "its window counts from the install, and it waits for the install goal"
post "$SETUP" "$OUT/g1.html" "$OUT/g2.html" action=goal_save "registration_id=$RA" goal_id= "goal_name=Purchase" goal_event=purchase \
    goal_value=fixed goal_amount=4.00 goal_count=1 goal_repeat=once goal_within_days= "goal_after=$G_TUT"
G_BUY=$(Q "SELECT goal_id FROM 202_goals WHERE scope='registration' AND scope_id=$RA AND name='Purchase'")
eq "$(Q "SELECT JSON_EXTRACT(definition, '$.after', '$.value.amount') FROM 202_goal_versions WHERE goal_id='$G_BUY'")" "[[$G_TUT], \"4.00\"]" "a funnel step after the tutorial, worth 4.00"
post "$SETUP" "$OUT/g2.html" "$OUT/g3.html" action=goal_save "registration_id=$RA" goal_id= "goal_name=Broken" "goal_event=bad name!" goal_value=none goal_count=1 goal_repeat=once
msgs "$OUT/g3.html"
eq "$REDIRECTS" 0 "an event name the API refuses"
has "$OUT/g3.html" 'id="goal_event"' "comes back to the goal form"
eq "$(grep -c 'class="form-control font-monospace is-invalid" id="goal_event"' "$OUT/g3.html")" 1 "with the event field marked"
eq "$(api POST /goals "{\"scope\":\"registration\",\"scope_id\":$RA,\"definition\":{\"name\":\"Big spender\",\"trigger\":{\"event\":\"purchase\"},\"threshold\":{\"sum\":{\"prop\":\"\$revenue\",\"gte\":20}}}}")" 201 \
   "a running-sum goal, made with the API"
G_SUM=$(field "d['data']['goal_id']")
get "$SETUP?app=$RA" "$OUT/g4.html"
has "$OUT/g4.html" "edit with <code>p202 goal update $G_SUM</code>" "the form that cannot show it points at the command that can"
hasnt "$OUT/g4.html" "goal_edit=$G_SUM" "and offers no edit link for it"
python3 - "$OUT/g4.html" "$G_INSTALL" "$G_TUT" "$G_BUY" > "$OUT/order" <<'PY'
import re, sys
page = open(sys.argv[1]).read()
ids = re.findall(r'data-goal-id="(\d+)"', page)
print(ids.index(sys.argv[2]) < ids.index(sys.argv[3]) < ids.index(sys.argv[4]))
PY
eq "$(cat "$OUT/order")" True "the goals are listed in funnel order: install, tutorial, purchase"

# ─────────────────────────────────────────────────────────────────────
say "iOS: a SKAN value names a goal from the menu, and an edit is warned"
get "$SETUP?app=$RI" "$OUT/app-i.html"
post "$SETUP" "$OUT/app-i.html" "$OUT/ig1.html" action=goal_save "registration_id=$RI" goal_id= "goal_name=Level 5" goal_event=level_up \
    goal_value=fixed goal_amount=2.50 goal_count=1 goal_repeat=once goal_where_prop=level goal_where_op=gte goal_where_value=5 goal_where_type=auto
G_L5=$(Q "SELECT goal_id FROM 202_goals WHERE scope='registration' AND scope_id=$RI AND name='Level 5'")
[ -n "$G_L5" ] && ok "an iOS app goal ($G_L5)" || bad "no iOS app goal"
has "$OUT/ig1.html" "<option value=\"$G_L5\">Level 5</option>" "the value editor's goal menu offers it"
post "$SETUP" "$OUT/ig1.html" "$OUT/ig2.html" action=rule_save "registration_id=$RI" kind=fine fine_value=5 "goal_choice=$G_L5" revenue=
eq "$(Q "SELECT CONCAT(fine_value, '|', goal_id) FROM 202_app_skan_encodings WHERE registration_id=$RI")" "5|$G_L5" "fine value 5 means Level 5"
ENC=$(Q "SELECT encoding_id FROM 202_app_skan_encodings WHERE registration_id=$RI")
get "$SETUP?app=$RI&rule_edit=$ENC" "$OUT/ig3.html"
has "$OUT/ig3.html" "a postback carrying it is reported as ambiguous_encoding" "editing a value warns of the horizon where the edit is made"
post "$SETUP" "$OUT/ig3.html" "$OUT/ig4.html" action=rule_save "registration_id=$RI" "rule_id=$ENC" kind=fine fine_value=5 goal_choice=event event_name=tutorial_done revenue=1.00
msgs "$OUT/ig4.html"
has "$OUT/ig4.html" "Conversion value changed." "and the saved edit says until when the report is ambiguous"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encoding_history WHERE encoding_id=$ENC")" 1 "the old meaning is kept for the horizon"
# A postback carrying fine value 5 arriving now, inside the horizon, could
# mean either: the report must credit it to neither and say so. The old
# meaning was saved seconds before the edit (possibly in the same second, an
# empty span), so move its start back ten days: only the clock moves, not
# what it meant.
Q "UPDATE 202_app_skan_encoding_history SET effective_at = retired_at - 10 * 86400 WHERE encoding_id = $ENC"
Q "INSERT INTO 202_app_postbacks (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id,
     conversion_value, postback_sequence_index, conversion_type, redownload, did_win, attribution_signature, signature_state, trusted,
     dedupe_hash, raw_payload, remote_ip, created_at)
   VALUES ($OWNER, $RI, UNIX_TIMESTAMP(), 'skadnetwork', '4.0', 'apps-ui.skadnetwork', 'apps-ui-1', 990077001, 5, 0, 'download', 0, 1, 'sig', 'valid', 1,
     SHA1('apps-ui-1'), '{}', '198.51.100.9', UNIX_TIMESTAMP())"

# ─────────────────────────────────────────────────────────────────────
say "the link builder"
eq "$(api POST /aff-networks '{"aff_network_name":"apps-ui-pass"}')" 201 "an affiliate network"
NET=$(field "d['data']['aff_network_id']")
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"apps-ui-pass Android\",\"aff_campaign_url\":\"https://example.com/offer\",\"aff_campaign_payout\":\"2.50\",\"aff_network_id\":$NET,\"payout_mode\":\"accumulate\"}")" 201 \
   "an Android campaign still pointing at a web offer"
CA=$(field "d['data']['aff_campaign_id']")
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"apps-ui-pass iOS\",\"aff_campaign_url\":\"https://example.com/ios\",\"aff_campaign_payout\":\"1\",\"aff_network_id\":$NET}")" 201 "and an iOS one"
CI=$(field "d['data']['aff_campaign_id']")
get "$SETUP?app=$RA&link_campaign=$CA" "$OUT/lb1.html"
has "$OUT/lb1.html" "Not yet" "the builder says the campaign is not ready"
has "$OUT/lb1.html" "the campaign is not linked to this app" "and why"
post "$SETUP" "$OUT/lb1.html" "$OUT/lb2.html" action=link_apply "registration_id=$RA" "campaign_id=$CA"
msgs "$OUT/lb2.html"
STORE='https://play.google.com/store/apps/details?id=com.p202.live.summit&referrer=p202%3D[[p202_install_token]]'
eq "$(Q "SELECT CONCAT(aff_campaign_url, '|', app_registration_id) FROM 202_aff_campaigns WHERE aff_campaign_id=$CA")" "$STORE|$RA" \
   "one click: the offer URL is the Play link with the install token, and the campaign is linked to the app"
has "$OUT/lb2.html" "Ready" "the builder now says ready"
eq "$(api GET "/apps/$RA/store-link?campaign_id=$CA")" 200 "GET /apps/{id}/store-link agrees"
eq "$(field "[d['data']['campaign']['ready'], d['data']['campaign']['apply']]")" '[true, {}]' "ready, nothing left to apply"
get "$SETUP?app=$RI&link_campaign=$CI" "$OUT/lb3.html"
post "$SETUP" "$OUT/lb3.html" "$OUT/lb4.html" action=link_apply "registration_id=$RI" "campaign_id=$CI"
eq "$(Q "SELECT CONCAT(aff_campaign_url, '|', COALESCE(app_registration_id, 'none')) FROM 202_aff_campaigns WHERE aff_campaign_id=$CI")" "https://apps.apple.com/app/id990077001|none" \
   "iOS: the App Store link, and no campaign-app link (Apple's postback names the app, not the click)"

eq "$(api POST /ppc-networks '{"ppc_network_name":"apps-ui-pass"}')" 201 "a traffic source"
PNET=$(field "d['data']['ppc_network_id']")
eq "$(api POST /ppc-accounts "{\"ppc_account_name\":\"apps-ui-pass\",\"ppc_network_id\":$PNET}")" 201 "and its account"
PACC=$(field "d['data']['ppc_account_id']")
Q "INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('$BASE/api/v3/versions?pr11=reached&goal=[[p202_goal]]&v=[[p202_goal_value]]', 4, $PACC)"
PIXEL=$(Q "SELECT pixel_id FROM 202_ppc_account_pixels WHERE ppc_account_id=$PACC")
eq "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"ppc_account_id\":$PACC}")" 201 "a tracker for the Android campaign"
TRK=$(field "d['data']['tracker_id_public']")
curl -s -o /dev/null -D "$OUT/click.h" -A 'Mozilla/5.0 (Linux; Android 14; Pixel 8) apps-ui-pass' "$BASE/tracking202/redirect/dl.php?t202id=$TRK"
CLICK=$(Q "SELECT MAX(click_id) FROM 202_clicks WHERE aff_campaign_id=$CA")
[ -n "$CLICK" ] && [ "$CLICK" != NULL ] && ok "a real click through the redirect ($CLICK)" || bad "no click"
REFERRER=$(python3 -c "
import sys, urllib.parse
loc = [l.split(':', 1)[1].strip() for l in open(sys.argv[1]) if l.lower().startswith('location:')]
print(urllib.parse.parse_qs(urllib.parse.urlparse(loc[0]).query).get('referrer', [''])[0] if loc else '')" "$OUT/click.h")
eq "$(printf '%s' "$REFERRER" | grep -cE "^p202=$CLICK\.[A-Za-z0-9_-]{16}$")" 1 "the store link the builder set carries the click's signed token"

# ─────────────────────────────────────────────────────────────────────
say "installs and events, through the public intake"
TOKEN_A=$(Q "SELECT app_token FROM 202_app_registrations WHERE registration_id=$RA")
CT=$(Q "SELECT click_time FROM 202_clicks WHERE click_id=$CLICK")
body() { # FILE UUID REFERRER — an SDK-shaped install seconds after the click
    python3 - "$@" "$CT" <<'PY'
import json, sys
path, uuid, referrer, t = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
json.dump({"install_uuid": uuid, "app_key": "com.p202.live.summit", "store": "google_play",
    "referrer": {"status": "ok", "install_referrer": referrer,
                 "referrer_click_timestamp_seconds": t + 1, "install_begin_timestamp_seconds": t + 3,
                 "referrer_click_timestamp_server_seconds": t + 2, "install_begin_timestamp_server_seconds": t + 4,
                 "install_version": "3.2.0", "google_play_instant": False},
    "first_open_at": t + 6, "app_version": "3.2.0", "sdk_version": "1.0.0", "os_version": "15",
    "test": False, "integrity_token": None}, open(path, "w"))
PY
}
U_ATT=$(uuid); U_ORG=$(uuid); U_BAD=$(uuid)
body "$OUT/i1.json" "$U_ATT" "$REFERRER"
eq "$(device POST /apps/installs "$TOKEN_A" "$OUT/i1.json")" 200 "the install from the click"
eq "$(field "d['data']['match']")" attributed "is attributed"
body "$OUT/i2.json" "$U_ORG" "utm_source=google-play&utm_medium=organic"
eq "$(device POST /apps/installs "$TOKEN_A" "$OUT/i2.json")" 200 "an organic install"
body "$OUT/i3.json" "$U_BAD" "p202=$CLICK.AAAAAAAAAAAAAAAA"
eq "$(device POST /apps/installs "$TOKEN_A" "$OUT/i3.json")" 200 "a forged token"
eq "$(field "d['data']['match']")" bad_token "is refuted"
# Goal windows count from the install (Play's install_begin, click + 4 s):
# an event must come after it, so let the clock pass it first.
while [ "$(date +%s)" -le $((CT + 8)) ]; do sleep 1; done
NOW=$(date +%s)
for U in "$U_ATT" "$U_ORG"; do
    printf '{"events": [{"event_id": "t1", "name": "tutorial_done", "occurred_at": %d}, {"event_id": "p1", "name": "purchase", "occurred_at": %d}]}' "$((NOW - 2))" "$((NOW - 1))" > "$OUT/ev.json"
    eq "$(device POST "/apps/installs/$U/events" "$TOKEN_A" "$OUT/ev.json")" 200 "events for $U"
done

# ─────────────────────────────────────────────────────────────────────
say "the report's numbers are the rows'"
DB_INSTALLS=$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE registration_id=$RA AND trusted=1")
DB_RECEIVED=$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE registration_id=$RA")
DB_REFUTED=$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE registration_id=$RA AND trusted=0")
DB_REACHED=$(Q "SELECT COUNT(*) FROM 202_goal_outcomes o JOIN 202_app_installs i ON i.install_row_id=o.subject_id WHERE o.subject_type='install' AND o.superseded_at IS NULL AND i.registration_id=$RA AND i.trusted=1")
DB_REVENUE=$(Q "SELECT FORMAT(COALESCE(SUM(CASE WHEN o.payable=1 THEN o.value END), 0), 2) FROM 202_goal_outcomes o JOIN 202_app_installs i ON i.install_row_id=o.subject_id WHERE o.subject_type='install' AND o.superseded_at IS NULL AND i.registration_id=$RA AND i.trusted=1")
eq "$DB_INSTALLS|$DB_RECEIVED|$DB_REFUTED" "1|3|1" "the rows: 3 installs, 1 attributed, 1 refuted"
eq "$(api GET "/apps/report?platform=android&group_by=registration&registration_id=$RA")" 200 "GET /apps/report, Android, by app"
eq "$(field "[d['data']['groups'][0][k] for k in ('received','installs','organic','refuted_count','goals_reached')]")" "[$DB_RECEIVED, $DB_INSTALLS, 1, $DB_REFUTED, $DB_REACHED]" \
   "received, installs, organic, refuted and goals reached match the rows"
eq "$(field "'%.2f' % d['data']['groups'][0]['revenue']")" "$DB_REVENUE" "revenue is the payable outcomes' value ($DB_REVENUE)"
eq "$(api GET "/apps/report?platform=all&group_by=platform")" 200 "both platforms (platform=all), by platform"
eq "$(field "[g['platform'] for g in d['data']['groups']]")" '["ios", "android"]' "one row per platform, iOS's zeros included"
DB_IOS_INSTALLS=$(Q "SELECT COUNT(*) FROM 202_app_postbacks WHERE trusted=1 AND did_win=1 AND redownload=0 AND conversion_type='download'")
eq "$(field "[d['data']['totals']['ios']['installs'], d['data']['totals']['combined']['installs']]")" "[$DB_IOS_INSTALLS, $((DB_INSTALLS + DB_IOS_INSTALLS))]" "the combined figure adds the platforms' installs"
eq "$(api GET "/apps/report?group_by=ad-network")" 200 "no platform is iOS, as the report meant before Android: an iOS grouping answers"
eq "$(field "[d['data']['platform'], d['meta']['platform']]")" '["ios", "ios"]' "and says it is iOS's"
eq "$(api GET "/apps/report?platform=all&group_by=ad-network")" 422 "an iOS grouping asked of both platforms"
grep -q "ask for platform=ios" "$OUT/body" && ok "is refused, naming the platform to ask for" || bad "the 422 names the platform to ask for"
eq "$(api GET "/apps/report?group_by=goal")" 422 "an Android grouping without platform=android"
eq "$(api GET "/apps/report?platform=android&group_by=goal&registration_id=$RA")" 200 "the funnel's reading"
eq "$(field "{g['goal_name']: [g['installs'], g['unvouched_count']] for g in d['data']['groups']}")" \
   '{"install": [1, 1], "Tutorial": [1, 1], "Purchase": [1, 1]}' "each goal reached by the attributed install, the organic one beside it"

get "$ANALYZE?platform=android&group_by=registration&registration_id=$RA" "$OUT/an1.html"
clean "$OUT/an1.html" "Analyze › Mobile Apps, Android, renders clean"
python3 - "$OUT/an1.html" > "$OUT/an1.txt" <<'PY'
import html, re, sys
page = open(sys.argv[1]).read()
tiles = dict(re.findall(r'<div class="p202-tile__label">([^<]+)</div>\s*<div class="p202-tile__value">([^<]+)</div>', page))
print(html.unescape(tiles.get('Installs', '?')), html.unescape(tiles.get('Goals reached', '?')), html.unescape(tiles.get('Refuted', '?')))
PY
eq "$(cat "$OUT/an1.txt")" "$DB_INSTALLS $DB_REACHED $DB_REFUTED" "its tiles say the rows' installs, goals and refuted"
has "$OUT/an1.html" "How installs were matched" "with the match-state breakdown"
has "$OUT/an1.html" ">bad token<" "naming the forged install's state"
get "$ANALYZE?view=funnel&registration_id=$RA" "$OUT/an2.html"
clean "$OUT/an2.html" "the Funnel tab renders clean"
python3 - "$OUT/an2.html" > "$OUT/an2.txt" <<'PY'
import re, sys
page = open(sys.argv[1]).read()
rows = re.findall(r'data-funnel-goal="\d+">\s*<td>\s*<span class="d-block">([^<]+)', page)
print('|'.join(r.strip() for r in rows))
PY
eq "$(cat "$OUT/an2.txt")" "1. install|2. Tutorial|3. Big spender|4. Purchase" "the funnel's steps by depth of after: Big spender waits for nothing, Purchase for the tutorial"
get "$ANALYZE?platform=all&group_by=platform" "$OUT/an3.html"
clean "$OUT/an3.html" "both platforms render clean"
has "$OUT/an3.html" "iOS figures are Apple's postbacks" "and say how the two platforms' numbers differ"
get "$ANALYZE?platform=ios&group_by=registration" "$OUT/an4.html"
has "$OUT/an4.html" ">Ambiguous<" "the iOS report has an Ambiguous column"
DB_AMB=1
eq "$(api GET "/apps/report?platform=ios&group_by=registration&registration_id=$RI")" 200 "GET /apps/report, iOS, the edited app"
eq "$(field "[d['data']['groups'][0][k] for k in ('postbacks', 'ambiguous_encoding', 'decoded')]")" "[1, $DB_AMB, 0]" "the postback inside the horizon is ambiguous, decoded to neither meaning"
python3 - "$OUT/an4.html" > "$OUT/an4.txt" <<'PY2'
import re, sys
page = open(sys.argv[1]).read()
head = re.search(r'<thead>(.*?)</thead>', page, re.S).group(1)
cols = [re.sub(r'<[^>]+>', '', c).strip() for c in re.findall(r'<th[^>]*>(.*?)</th>', head, re.S)]
row = re.search(r'<tbody>\s*<tr>(.*?)</tr>', page, re.S).group(1)
cells = [re.sub(r'<[^>]+>', '', c).strip() for c in re.findall(r'<td[^>]*>(.*?)</td>', row, re.S)]
print(cells[cols.index('Ambiguous')])
PY2
eq "$(cat "$OUT/an4.txt")" "$DB_AMB" "and the page's Ambiguous cell says the same"

if [ -n "$CLI" ]; then
    HOME="${CLI_HOME:-$HOME}" "$CLI" app report --platform android --group-by registration --registration-id "$RA" --json > "$OUT/cli.json" 2> "$OUT/cli.err"
    eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d['data'][0]['installs'], d['meta']['totals']['received'])" "$OUT/cli.json" 2>/dev/null)" "$DB_INSTALLS $DB_RECEIVED" \
       "p202 app report --platform android: the same groups, and the totals in meta"
    HOME="${CLI_HOME:-$HOME}" "$CLI" app report --platform all --group-by ad-network --json > /dev/null 2> "$OUT/cli2.err"
    has "$OUT/cli2.err" "--platform ios" "p202 app report refuses an iOS grouping asked of both platforms, naming the flag"
    HOME="${CLI_HOME:-$HOME}" "$CLI" app link "$RA" --campaign-id "$CA" --json > "$OUT/cli3.json" 2> "$OUT/cli3.err"
    eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); d=d.get('data', d); print(d['campaign']['ready'], d['android']['referrer_carries_token'] if 'referrer_carries_token' in d.get('android', {}) else '-', d['store_link'])" "$OUT/cli3.json" 2>&1)" \
       "True - $STORE" "p202 app link: the store link with the install token, and the campaign ready"
else
    echo "  NOT RUN the Go CLI checks: set P202_BIN to the Go CLI (and P202_CLI_HOME to its configured HOME)"
fi
if [ -n "${P202_PHP_CLI:-}" ]; then
    PCLIHOME="$OUT/pclihome"; mkdir -p "$PCLIHOME"
    pcli() { (cd "$ROOT" && HOME="$PCLIHOME" $P202_PHP_CLI "$@"); }
    pcli config:set-url "$BASE" > /dev/null && pcli config:set-key "$P202_API_KEY" > /dev/null
    pcli app:report --platform=android --group_by=registration "--registration_id=$RA" --json > "$OUT/pcli1.json" 2>&1
    eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d['data']['groups'][0]['installs'], d['data']['totals']['received'])" "$OUT/pcli1.json" 2>&1)" "$DB_INSTALLS $DB_RECEIVED" \
       "bin/p202 app:report --platform=android: the rows' numbers"
    pcli app:report --group_by=goal > "$OUT/pcli2.txt" 2>&1
    eq "$?" 1 "bin/p202 app:report refuses an Android grouping without --platform=android, before any request"
    has "$OUT/pcli2.txt" "--platform=android" "naming the option"
    pcli app:link "$RA" "--campaign_id=$CA" --json > "$OUT/pcli3.json" 2>&1
    eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d['data']['campaign']['ready'])" "$OUT/pcli3.json" 2>&1)" True "bin/p202 app:link: the campaign is ready"
    pcli app:notifications "--registration_id=$RA" --json > "$OUT/pcli4.json" 2>&1
    eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(len(d['data']))" "$OUT/pcli4.json" 2>&1)" \
       "$(Q "SELECT COUNT(*) FROM 202_notification_pending n WHERE n.conv_id IN (SELECT conversion_id FROM 202_goal_outcomes WHERE subject_type = 'install' AND app_registration_id = $RA)")" \
       "bin/p202 app:notifications: this app's queued postbacks"
else
    echo "  NOT RUN the PHP CLI checks: set P202_PHP_CLI (e.g. \"php bin/p202\")"
fi

# ─────────────────────────────────────────────────────────────────────
say "postbacks sent, and a correction URL"
eq "$(api GET "/apps/notifications?registration_id=$RA")" 200 "GET /apps/notifications"
DB_Q=$(Q "SELECT COUNT(*) FROM 202_notification_pending")
eq "$(field "sum(d['meta']['summary'].values())")" "$DB_Q" "its summary counts every queued postback ($DB_Q)"
get "$ANALYZE?view=notifications&registration_id=$RA" "$OUT/an5.html"
clean "$OUT/an5.html" "the Postbacks sent tab renders clean"
python3 - "$OUT/an5.html" > "$OUT/an5.txt" <<'PY'
import re, sys
page = open(sys.argv[1]).read()
print(sum(int(v.replace(',', '')) for v in re.findall(r'data-outbox-status="\w+">\s*<div class="p202-tile__label">\w+</div>\s*<div class="p202-tile__value">([\d,]+)', page)))
PY
eq "$(cat "$OUT/an5.txt")" "$DB_Q" "its tiles add up to the outbox"
eq "$(grep -c "data-outbox-destination=\"$PIXEL:0\"" "$OUT/an5.html")" "$(Q "SELECT COUNT(*) FROM 202_notification_pending WHERE pixel_id=$PIXEL AND destination=0")" \
   "one row per destination: each outbox row names its pixel and URL"
has "$OUT/an5.html" "Pixel $PIXEL, URL 1" "and says which URL of which pixel it is"

get "/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$PACC" "$OUT/ts1.html"
has "$OUT/ts1.html" 'name="pixel_correction_url[]"' "Setup › Traffic Sources offers a correction URL per pixel"
TS_TOK=$(grep -oE 'name="token" value="[^"]+"' "$OUT/ts1.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$PACC" \
    --data-urlencode "token=$TS_TOK" --data-urlencode do_edit_ppc_account=1 --data-urlencode "ppc_network_id=$PNET" \
    --data-urlencode "ppc_account_name=apps-ui-pass" --data-urlencode "pixel_type_id[]=4" --data-urlencode "pixel_id[]=$PIXEL" \
    --data-urlencode "pixel_code[]=$BASE/api/v3/versions?pr11=reached&goal=[[p202_goal]]" --data-urlencode "pixel_correction_url[]=ftp://nope" -o "$OUT/ts2.html"
has "$OUT/ts2.html" "A correction URL is an http:// or https:// address" "an address that is not http(s) is refused"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$PACC" \
    --data-urlencode "token=$TS_TOK" --data-urlencode do_edit_ppc_account=1 --data-urlencode "ppc_network_id=$PNET" \
    --data-urlencode "ppc_account_name=apps-ui-pass" --data-urlencode "pixel_type_id[]=4" --data-urlencode "pixel_id[]=$PIXEL" \
    --data-urlencode "pixel_code[]=$BASE/api/v3/versions?pr11=reached&goal=[[p202_goal]]" --data-urlencode "pixel_correction_url[]=https://a.example/c https://b.example/c" -o "$OUT/ts3.html"
has "$OUT/ts3.html" "matched by position" "two correction URLs for a one-URL pixel are refused by count"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_correction_urls")" 0 "and nothing is stored"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$PACC" \
    --data-urlencode "token=$TS_TOK" --data-urlencode do_edit_ppc_account=1 --data-urlencode "ppc_network_id=$PNET" \
    --data-urlencode "ppc_account_name=apps-ui-pass" --data-urlencode "pixel_type_id[]=4" --data-urlencode "pixel_id[]=$PIXEL" \
    --data-urlencode "pixel_code[]=$BASE/api/v3/versions?pr11=reached&goal=[[p202_goal]]" \
    --data-urlencode "pixel_correction_url[]=$BASE/api/v3/versions?pr11=correction&v=[[p202_goal_value]]&was=[[p202_previous_value]]" -o "$OUT/ts3.html"
eq "$(Q "SELECT correction_url FROM 202_notification_correction_urls WHERE pixel_id=$PIXEL AND user_id=$OWNER")" \
   "$BASE/api/v3/versions?pr11=correction&v=[[p202_goal_value]]&was=[[p202_previous_value]]" "a server pixel's correction URL is saved"
get "/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$PACC" "$OUT/ts4.html"
has "$OUT/ts4.html" "pr11=correction&amp;v=[[p202_goal_value]]" "and shown again on the form"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/ppc_accounts.php?edit_ppc_account_id=$PACC" \
    --data-urlencode "token=$TS_TOK" --data-urlencode do_edit_ppc_account=1 --data-urlencode "ppc_network_id=$PNET" \
    --data-urlencode "ppc_account_name=apps-ui-pass" --data-urlencode "pixel_type_id[]=4" --data-urlencode "pixel_id[]=999999" \
    --data-urlencode "pixel_code[]=$BASE/x" --data-urlencode "pixel_correction_url[]=https://forged.example/c" -o "$OUT/ts5.html"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_correction_urls WHERE pixel_id=999999")" 0 "a pixel id the account does not have takes no correction URL"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
