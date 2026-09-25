#!/bin/bash
# Live pass for the Android intake (PR 5), driven against a running instance
# as a device and an operator would, with the database read back after every
# step:
#
#   - a real click through the redirect (dl.php) on a campaign whose store
#     link carries [[p202_install_token]]; the token read from Location and
#     checked against GET /apps/{id}/install-token (and `p202 app install
#     token`);
#   - SDK-shaped POST /apps/installs: attributed, with the built-in install
#     goal's conversion on the click (key `install`, pixel_type 4, at Google's
#     install time), the click's value, the MTA outbox row and a queued
#     traffic-source notification — all in one commit;
#   - a replay (duplicate), a reused install_uuid with other content (409), a
#     second device on the same click (duplicate_click), a tampered MAC and a
#     bare click id (bad_token), a click of a campaign linked to another app
#     (foreign_click), another app's token lifted into this one (422), an
#     iOS app's token, an unknown and a malformed token, a body over the cap,
#     the GET probe and a refused method;
#   - events through the goal engine: a campaign paying install $2.50 and
#     level 3 $4.00 reaches exactly those two from levels 1, 2, 3 and a
#     replay of 3; purchases paid from the app's revenue once the
#     registration trusts it; a late, earlier purchase re-decides the second
#     purchase — the old row superseded, its pending notification cancelled,
#     the replacement's queued; events for a pending install wait (503), for
#     a refuted one are refused (409), for an unknown one 404;
#   - an organic install reaches the install goal for the funnel only; a test
#     install counts only under accept_test_signals;
#   - a pending click (the token verified, the click row not yet written) is
#     settled by 202-cronjobs/app-installs.php once the row appears, which
#     also sends the queued notifications: each arrives at the pixel's URL
#     with the goal tokens filled; the pixel holds a second URL that
#     refuses, which is its own row, retried alone — the endpoint that
#     accepted hears each conversion once however often the other fails;
#   - the operator's reads (GET /apps/{id}/installs, the CLI's `app install
#     list/get/simulate`, simulate refused under --staged); deleting a
#     registration unlinks its campaigns, so registering the app again can
#     mint tokens for their clicks; the retention
#     classes, and the per-peer rate limit (last, since it trips the bucket).
#
# The traffic-source pixel points back at this instance's unauthenticated
# /api/v3/versions, so a sent notification is a 2xx the worker can see, and
# at /api/v3/pr5-refuses, a 404 it retries; set P202_SERVER_LOG to the php -S
# log to also match each request line.
#
# Runs 202-cronjobs/*.php from this checkout, so the checkout's 202-config.php
# must name the instance's database. Truncates the app, goal and outbox
# tables, so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
SERVER_LOG=${P202_SERVER_LOG:-}

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
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }

# api METHOD PATH [JSON-BODY] — as the operator; body in $OUT/body, status printed.
api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# device METHOD PATH TOKEN [BODY-FILE] — as the SDK: no API key, the app token
# header only. Body in $OUT/body, headers in $OUT/headers, status printed.
device() {
    local args=(-s -o "$OUT/body" -D "$OUT/headers" -w '%{http_code}' -X "$1" -H "X-P202-App-Token: $3")
    [ -n "${4:-}" ] && args+=(-H 'Content-Type: application/json' --data-binary "@$4")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }

# install_body FILE UUID REFERRER CLICK_TIME [TEST] [APP_KEY] [APP_VERSION] — an
# SDK-shaped install whose Google timestamps sit seconds after the click.
install_body() {
    python3 - "$@" <<'PY'
import json, sys
path, uuid, referrer, t = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
test = len(sys.argv) > 5 and sys.argv[5] == 'test'
app_key = sys.argv[6] if len(sys.argv) > 6 else 'com.p202.pass.summit'
version = sys.argv[7] if len(sys.argv) > 7 else '3.2.0'
json.dump({
    "install_uuid": uuid, "app_key": app_key, "store": "google_play",
    "referrer": {"status": "ok", "install_referrer": referrer,
                 "referrer_click_timestamp_seconds": t + 2, "install_begin_timestamp_seconds": t + 39,
                 "referrer_click_timestamp_server_seconds": t + 3, "install_begin_timestamp_server_seconds": t + 40,
                 "install_version": version, "google_play_instant": False},
    "first_open_at": t + 60, "app_version": version, "sdk_version": "1.0.0", "os_version": "15",
    "test": test, "integrity_token": None,
}, open(path, 'w'))
PY
}
events_body() { # FILE JSON-LIST
    printf '{"events": %s}' "$2" > "$1"
}
UA='Mozilla/5.0 (Linux; Android 14; Pixel 8) android-intake-pass'
# click TRACKER HEADERS — one click through the redirect; prints the click id.
click() {
    local before after
    before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    curl -s -o /dev/null -D "$2" -A "$UA" "$BASE/tracking202/redirect/dl.php?t202id=$1"
    after=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    [ "$after" != "$before" ] && echo "$after" || echo ""
}
# referrer_of HEADERS — Play's install referrer: the Location's referrer= value, decoded once.
referrer_of() {
    python3 -c "
import sys, urllib.parse
loc = [l.split(':', 1)[1].strip() for l in open(sys.argv[1]) if l.lower().startswith('location:')]
q = urllib.parse.urlparse(loc[0]).query if loc else ''
print(urllib.parse.parse_qs(q).get('referrer', [''])[0])" "$1"
}
uuid() { python3 -c 'import uuid; print(uuid.uuid4())'; }
ctime() { Q "SELECT click_time FROM 202_clicks WHERE click_id = $1"; }

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }

cleanup() {
  mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%'));
DELETE FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%');
DELETE FROM 202_dataengine WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%');
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%');
DELETE FROM 202_clicks WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%');
DELETE FROM 202_trackers WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%');
DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN (SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name = 'android-pass');
DELETE FROM 202_ppc_accounts WHERE ppc_account_name = 'android-pass';
DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'android-pass%';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
TRUNCATE 202_app_registrations; TRUNCATE 202_app_installs; TRUNCATE 202_notification_pending;
SQL
}
cleanup
trap cleanup EXIT

# A previous run may have spent this peer's intake bucket (its last step
# trips it on purpose): wait the window out rather than read 429s as defects.
for _ in 1 2 3; do
    code=$(curl -s -o /dev/null -D "$OUT/probe" -w '%{http_code}' "$BASE/api/v3/apps/installs")
    [ "$code" = 429 ] || break
    wait_s=$(awk 'tolower($1) == "retry-after:" {print $2+0}' "$OUT/probe")
    sleep "${wait_s:-60}"
done

# ─────────────────────────────────────────────────────────────────────
say "setup: two Android apps and an iOS one, campaigns linked to them, a traffic source"
eq "$(api POST /apps '{"store_link":"https://play.google.com/store/apps/details?id=com.p202.pass.summit","app_name":"Summit"}')" 201 "an Android app from its Play link"
R=$(field "d['data']['registration_id']"); TOKEN=$(field "d['data']['app_token']")
eq "$(field "d['data']['attribution_window_days']")" 7 "a 7-day attribution window by default"
eq "$(field "d['data']['trust_client_revenue']")" 0 "and the app's reported revenue not trusted"
eq "$(api POST /apps '{"store_link":"com.p202.pass.other","app_name":"Other"}')" 201 "a second Android app"
R_OTHER=$(field "d['data']['registration_id']"); TOKEN_OTHER=$(field "d['data']['app_token']")
eq "$(api POST /apps '{"store_link":"990088001","app_name":"Summit iOS"}')" 201 "an iOS app"
TOKEN_IOS=$(field "d['data']['app_token']")
eq "$(api PUT "/apps/$R" '{"attribution_window_days":"7.0"}')" 422 "a window the int cast would round is refused"
eq "$(api GET "/goals?scope=registration&scope_id=$R")" 200 "the registration's goals"
eq "$(field "[(g['name'], g['builtin']) for g in d['data']]")" '[["install", "install"]]' "include the built-in install goal from the start"
G_INSTALL=$(field "d['data'][0]['goal_id']")
eq "$(api PUT "/goals/$G_INSTALL" '{"definition":{"name":"install","trigger":{"event":"x"}}}')" 409 "which cannot be edited"
eq "$(api DELETE "/goals/$G_INSTALL")" 409 "nor archived"

eq "$(api POST /aff-networks '{"aff_network_name":"android-pass"}')" 201 "an affiliate network"
NET=$(field "d['data']['aff_network_id']")
STORE='https://play.google.com/store/apps/details?id=com.p202.pass.summit&referrer=p202%3D[[p202_install_token]]'
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"android-pass A\",\"aff_campaign_url\":\"$STORE\",\"aff_campaign_payout\":\"2.50\",\"aff_network_id\":$NET,\"payout_mode\":\"accumulate\",\"app_registration_id\":$R}")" 201 \
   "a campaign whose store link carries the install token, linked to the app"
CAMP=$(field "d['data']['aff_campaign_id']")
eq "$(field "d['data']['app_registration_id']")" "$R" "the link is stored"
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"android-pass B\",\"aff_campaign_url\":\"$STORE\",\"aff_campaign_payout\":\"1\",\"aff_network_id\":$NET,\"app_registration_id\":$R_OTHER}")" 201 \
   "a campaign linked to the other app"
CAMP_B=$(field "d['data']['aff_campaign_id']")
eq "$(api PUT "/campaigns/$CAMP_B" '{"app_registration_id":"1e0"}')" 422 "a registration id is read raw, never cast"
eq "$(api PUT "/campaigns/$CAMP_B" "{\"app_registration_id\":$(Q "SELECT registration_id FROM 202_app_registrations WHERE platform='ios'")}")" 422 "and must be an Android app"
eq "$(api POST /ppc-networks '{"ppc_network_name":"android-pass"}')" 201 "a traffic source"
PNET=$(field "d['data']['ppc_network_id']")
eq "$(api POST /ppc-accounts "{\"ppc_account_name\":\"android-pass\",\"ppc_network_id\":$PNET}")" 201 "and its account"
PACC=$(field "d['data']['ppc_account_id']")
Q "INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES
   ('$BASE/api/v3/versions?pr5=android&sub=[[subid]]&goal=[[p202_goal]]&v=[[p202_goal_value]]&tx=[[transactionid]] $BASE/api/v3/pr5-refuses?sub=[[subid]]&goal=[[p202_goal]]', 4, $PACC),
   ('<img src=\"https://browser.example/px?sub=[[subid]]\">', 5, $PACC)"
eq "$(api POST /trackers "{\"aff_campaign_id\":$CAMP,\"ppc_account_id\":$PACC}")" 201 "a tracker for campaign A"
TRK=$(field "d['data']['tracker_id_public']")
eq "$(api POST /trackers "{\"aff_campaign_id\":$CAMP_B,\"ppc_account_id\":$PACC}")" 201 "and for campaign B"
TRK_B=$(field "d['data']['tracker_id_public']")

# The campaign pays for install (at its default) and level 3 ($4), and for
# a second purchase at the value the app reports, when it may be trusted.
eq "$(api PUT "/goals/$G_INSTALL/campaigns/$CAMP" '{}')" 200 "the campaign lists the install goal"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP,\"payout\":4,\"definition\":{\"name\":\"Level 3\",\"trigger\":{\"event\":\"level_reached\",\"where\":[{\"prop\":\"level\",\"op\":\"gte\",\"value\":3}]}}}")" 201 "level 3 at \$4"
G_LEVEL=$(field "d['data']['goal_id']")
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP,\"definition\":{\"name\":\"Second purchase\",\"trigger\":{\"event\":\"purchase\"},\"threshold\":{\"count\":2},\"value\":{\"type\":\"from_property\",\"prop\":\"\$revenue\"}}}")" 201 \
   "the second purchase at its reported value"
G_SECOND=$(field "d['data']['goal_id']")

# ─────────────────────────────────────────────────────────────────────
say "a click through the redirect carries a signed token to the store"
C1=$(click "$TRK" "$OUT/h1")
[ -n "$C1" ] && ok "click recorded ($C1)" || bad "no click recorded"
REF1=$(referrer_of "$OUT/h1")
TOK1=${REF1#p202=}
eq "$(printf '%s' "$TOK1" | grep -cE "^$C1\.[A-Za-z0-9_-]{16}$")" 1 "Location's referrer is p202=<click id>.<16-character signature>"
has "$OUT/h1" "play.google.com/store/apps/details?id=com.p202.pass.summit&referrer=p202%3D$C1." "the token rides the store link's referrer, encoded once"
eq "$(api GET "/apps/$R/install-token?click_id=$C1")" 200 "the operator's install-token read"
eq "$(field "d['data']['install_token']")" "$TOK1" "is the token the redirect signed (one key)"
eq "$(api GET "/apps/$R/install-token?click_id=$C1.0")" 422 "a click id is read raw"
eq "$(api GET "/apps/$R/install-token?click_id=99999999")" 404 "and must be the caller's click"

# ─────────────────────────────────────────────────────────────────────
say "an attributed install is the install goal's conversion on its click"
U1=$(uuid)
install_body "$OUT/i1.json" "$U1" "$REF1&utm_source=newsletter" "$(ctime "$C1")"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i1.json")" 200 "the SDK's install is recorded"
eq "$(field "[d['data']['match'], d['data']['trusted'], d['data']['duplicate']]")" '["attributed", 1, false]' "attributed, trusted, first time"
hasnt "$OUT/body" "click_id" "the answer carries no click data"
hasnt "$OUT/body" "2.5" "and no money"
eq "$(Q "SELECT CONCAT_WS('/', match_state, trusted, click_id, utm_source) FROM 202_app_installs WHERE install_uuid='$U1'")" "attributed/1/$C1/newsletter" "the install row"
CONV1=$(Q "SELECT conversion_id FROM 202_app_installs WHERE install_uuid='$U1'")
eq "$(Q "SELECT CONCAT_WS('/', source, dedupe_key, pixel_type, payable, click_payout, conv_time - $(ctime "$C1"), ISNULL(transaction_id)) FROM 202_conversion_logs WHERE conv_id='$CONV1'")" \
   "app_install/install/4/1/2.50000/40/1" "its conversion: key install, pixel_type 4, at Google's install time, the campaign's default"
eq "$(Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$C1")" "1/2.50000" "the click is a lead worth \$2.50"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id='$CONV1'")" 1 "queued for MTA"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT_WS('/', destination, kind, status) ORDER BY destination) FROM 202_notification_pending WHERE conv_id='$CONV1'")" "0/reached/pending,1/reached/pending" \
   "one server postback queued per URL of the server pixel (the browser pixel has no page to render on)"
eq "$(Q "SELECT url FROM 202_notification_pending WHERE conv_id='$CONV1' AND destination=0")" \
   "$BASE/api/v3/versions?pr5=android&sub=$C1&goal=install&v=2.50&tx=install" "with the goal tokens filled"
eq "$(Q "SELECT CONCAT_WS('/', o.event_id, o.app_registration_id, o.conversion_id) FROM 202_goal_outcomes o WHERE o.goal_id=$G_INSTALL")" "@install/$R/$CONV1" \
   "the install goal's outcome links the conversion and the app"

say "replays, reuse and second devices"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i1.json")" 200 "a replay is answered"
eq "$(field "[d['data']['match'], d['data']['duplicate']]")" '["attributed", true]' "as a duplicate with the stored match"
python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(json.dumps(d, indent=4, sort_keys=True))" "$OUT/i1.json" > "$OUT/i1-pretty.json"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i1-pretty.json")" 200 "whitespace and key order are not content"
eq "$(field "d['data']['duplicate']")" true "still a duplicate"
install_body "$OUT/i1b.json" "$U1" "$REF1&utm_source=newsletter" "$(ctime "$C1")" "" com.p202.pass.summit 3.3.0
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i1b.json")" 409 "the same install_uuid with other content is refused"
has "$OUT/body" "different content" "saying why"
U2=$(uuid)
install_body "$OUT/i2.json" "$U2" "$REF1" "$(ctime "$C1")"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i2.json")" 200 "a second device on the same click"
eq "$(field "[d['data']['match'], d['data']['trusted']]")" '["duplicate_click", null]' "is duplicate_click, unvouched"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C1")" 1 "one install conversion per click"

say "forgeries"
MAC=${TOK1#*.}
FLIP=$([ "${MAC:0:1}" = A ] && echo B || echo A)${MAC:1}
U3=$(uuid)
install_body "$OUT/i3.json" "$U3" "p202=$C1.$FLIP" "$(ctime "$C1")"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i3.json")" 200 "a tampered signature is stored"
eq "$(field "[d['data']['match'], d['data']['trusted']]")" '["bad_token", 0]' "as bad_token, refuted"
has "$OUT/body" "signature does not verify" "with its reason"
U4=$(uuid)
install_body "$OUT/i4.json" "$U4" "p202=$C1" "$(ctime "$C1")"
device POST /apps/installs "$TOKEN" "$OUT/i4.json" > /dev/null
eq "$(field "d['data']['match']")" bad_token "a bare click id (a guessable sequence) is a bad token"
events_body "$OUT/e0.json" '[{"event_id":"x1","name":"level_reached","occurred_at":1}]'
eq "$(device POST "/apps/installs/$U3/events" "$TOKEN" "$OUT/e0.json")" 409 "a refuted install's events are refused"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_subjects s JOIN 202_app_installs i ON i.install_row_id = s.subject_id AND s.subject_type='install' WHERE i.install_uuid IN ('$U3','$U4')")" 0 \
   "a refuted install reaches no goal"
CB=$(click "$TRK_B" "$OUT/hb")
U5=$(uuid)
install_body "$OUT/i5.json" "$U5" "$(referrer_of "$OUT/hb")" "$(ctime "$CB")"
device POST /apps/installs "$TOKEN" "$OUT/i5.json" > /dev/null
eq "$(field "[d['data']['match'], d['data']['trusted']]")" '["foreign_click", 0]' "a click of a campaign linked to another app is foreign"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$CB")" 0 "and pays nothing"

say "tokens and the shared plumbing"
eq "$(device POST /apps/installs "$TOKEN_OTHER" "$OUT/i5.json")" 422 "another app's token lifted into this build"
has "$OUT/body" "com.p202.pass.other" "names the token's app"
has "$OUT/body" "com.p202.pass.summit" "and the one the build claims"
eq "$(device POST /apps/installs "$TOKEN_IOS" "$OUT/i5.json")" 422 "an iOS app's token"
eq "$(device POST /apps/installs "$(printf 'c%.0s' $(seq 64))" "$OUT/i5.json")" 404 "an unknown token"
eq "$(device POST /apps/installs "not-a-token" "$OUT/i5.json")" 400 "a malformed token"
python3 -c "import json; json.dump({'install_uuid':'00000000-0000-4000-8000-000000000000','pad':'x'*20000}, open('$OUT/big.json','w'))"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/big.json")" 413 "a body over 16 KB"
printf '{"install_uuid":"%s","app_key":"com.p202.pass.summit","store":"google_play","referrer":{"status":"ok"},"test":"false"}' "$(uuid)" > "$OUT/bad.json"
eq "$(device POST /apps/installs "$TOKEN" "$OUT/bad.json")" 400 "an invalid body"
eq "$(field "sorted(d['field_errors'])")" '["referrer.install_begin_timestamp_seconds", "referrer.install_begin_timestamp_server_seconds", "referrer.install_referrer", "referrer.referrer_click_timestamp_seconds", "referrer.referrer_click_timestamp_server_seconds", "test"]' \
   "naming every bad field, the string \"false\" among them"
eq "$(curl -s -o "$OUT/body" -w '%{http_code}' "$BASE/api/v3/apps/installs")" 200 "the GET probe"
eq "$(field "d['data']['status']")" ready "answers ready"
eq "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$BASE/api/v3/apps/installs")" 405 "another method is refused"

# ─────────────────────────────────────────────────────────────────────
say "events reach the campaign's goals, once, whatever the order"
T=$(( $(ctime "$C1") + 100 ))
# The server clamps an event dated in its future to the time it received
# it, which would erase the order the purchases below depend on (p0 must
# stay earlier than p1 and p2). The latest one is T + 20, so wait until
# that is in the past; a pass that reaches here fast pays up to ~2 minutes.
#
# This is a clock boundary, measured (PR 12): without the wait, p1, p2 and
# p0 all sort by the second they arrived in. When all three land in one
# second the tie falls to the event id, "p0" < "p1", and the pass is green;
# when p0 lands one second after p2 it sorts last, the second purchase stays
# p2's $10, and three checks below fail. Without the wait, six runs after
# play-integrity.sh failed once — the one whose p0 arrived a second after
# p2 — a one-second pause before p0 failed three runs of three, and the same
# pause with the wait restored passed two of two. The check after p0
# asserts none of the purchases was clamped, so a pass that loses this wait
# fails by name, every run, instead of by luck.
wait_s=$(( T + 21 - $(date +%s) ))
if [ "$wait_s" -gt 0 ]; then sleep "$wait_s"; fi
for level in 1 2 3; do
    events_body "$OUT/e.json" "[{\"event_id\":\"l$level\",\"name\":\"level_reached\",\"occurred_at\":$((T + level)),\"properties\":{\"level\":$level}}]"
    device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/e.json" > /dev/null
done
events_body "$OUT/e.json" "[{\"event_id\":\"l3\",\"name\":\"level_reached\",\"occurred_at\":$((T + 3)),\"properties\":{\"level\":3}}]"
eq "$(device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/e.json")" 200 "a replayed event is answered"
eq "$(field "[d['data']['accepted'], d['data']['duplicates']]")" '[[], ["l3"]]' "as a duplicate"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(source, ':', click_payout) ORDER BY conv_id) FROM 202_conversion_logs WHERE click_id=$C1 AND superseded_reason IS NULL")" \
   "app_install:2.50000,goal:4.00000" "exactly two conversions: the install and level 3"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(o.event_id, ':', o.payable) ORDER BY o.outcome_id) FROM 202_goal_outcomes o JOIN 202_app_installs i ON i.install_row_id=o.subject_id WHERE o.subject_type='install' AND i.install_uuid='$U1' AND o.goal_id=$G_LEVEL AND o.superseded_at IS NULL")" \
   "l3:1" "level 3 is reached once, by the level-3 event, and is payable"
eq "$(Q "SELECT click_payout FROM 202_clicks WHERE click_id=$C1")" "6.50000" "accumulated to \$6.50"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_pending WHERE kind='reached' AND url LIKE '%goal=Level%203&v=4.00%'")" 1 "level 3 queued its own postback"
events_body "$OUT/e.json" '[{"event_id":"r1","name":"level_reached","occurred_at":1,"received_at":1}]'
eq "$(device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/e.json")" 400 "the server's clock is not the app's to set"
eq "$(field "list(d['field_errors'])")" '["events[0].received_at"]' "named by field"
eq "$(device POST "/apps/installs/$(uuid)/events" "$TOKEN" "$OUT/e0.json")" 404 "an unknown install's events"

eq "$(api PUT "/apps/$R" '{"trust_client_revenue":1}')" 200 "the operator trusts the app's revenue"
for p in "p1 10 5" "p2 20 10"; do
    set -- $p
    events_body "$OUT/e.json" "[{\"event_id\":\"$1\",\"name\":\"purchase\",\"occurred_at\":$((T + $2)),\"revenue\":$3}]"
    eq "$(device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/e.json")" 200 "purchase $1 is accepted"
done
eq "$(Q "SELECT CONCAT(click_payout, '/', payable) FROM 202_conversion_logs WHERE click_id=$C1 AND source_ref='goal:$G_SECOND:1' AND superseded_reason IS NULL")" \
   "10.00000/1" "the second purchase pays its reported \$10"
events_body "$OUT/e.json" "[{\"event_id\":\"p0\",\"name\":\"purchase\",\"occurred_at\":$((T + 5)),\"revenue\":1}]"
eq "$(device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/e.json")" 200 "the late purchase p0 is accepted"
eq "$(Q "SELECT SUM(e.occurred_at >= e.received_at) FROM 202_goal_events e JOIN 202_app_installs i ON i.install_row_id = e.subject_id AND e.subject_type = 'install' WHERE i.install_uuid = '$U1' AND e.event_id IN ('p0','p1','p2')")" 0 \
   "no purchase was dated in the server's future, so their order is their own, not the second each arrived in"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(click_payout, ':', COALESCE(superseded_reason, 'counted')) ORDER BY conv_id) FROM 202_conversion_logs WHERE click_id=$C1 AND source_ref='goal:$G_SECOND:1'")" \
   "10.00000:replay,5.00000:counted" "a late, earlier purchase re-decides it: \$10 superseded, \$5 counted"
eq "$(Q "SELECT click_payout FROM 202_clicks WHERE click_id=$C1")" "11.50000" "the click is \$2.50 + \$4 + \$5"
eq "$(Q "SELECT GROUP_CONCAT(status ORDER BY notification_id) FROM 202_notification_pending WHERE url LIKE '%goal=Second%' AND destination=0")" "cancelled,pending" \
   "the network, told nothing yet, hears only the corrected value"

# ─────────────────────────────────────────────────────────────────────
say "organic and test installs"
U6=$(uuid)
install_body "$OUT/i6.json" "$U6" "utm_source=google-play&utm_medium=organic" "$(date +%s)"
device POST /apps/installs "$TOKEN" "$OUT/i6.json" > /dev/null
eq "$(field "[d['data']['match'], d['data']['trusted']]")" '["organic", null]' "an organic install"
eq "$(Q "SELECT CONCAT_WS('/', o.payable, ISNULL(o.conversion_id)) FROM 202_goal_outcomes o JOIN 202_app_installs i ON i.install_row_id=o.subject_id WHERE i.install_uuid='$U6' AND o.goal_id=$G_INSTALL")" "0/1" \
   "reaches the install goal for the funnel, with no conversion"
C7=$(click "$TRK" "$OUT/h7")
U7=$(uuid)
install_body "$OUT/i7.json" "$U7" "$(referrer_of "$OUT/h7")" "$(ctime "$C7")" test
device POST /apps/installs "$TOKEN" "$OUT/i7.json" > /dev/null
eq "$(field "[d['data']['match'], d['data']['trusted'], d['data']['test']]")" '["attributed", null, true]' "a test install on a registration that refuses test signals"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C7")" 0 "pays nothing"
# accept_test_signals is a live policy: the test install already stored is
# re-judged when it changes, its conversion written, then retired.
eq "$(api PUT "/apps/$R" '{"accept_test_signals":1}')" 200 "the operator accepts test signals"
eq "$(Q "SELECT CONCAT_WS('/', trusted, conversion_id IS NOT NULL) FROM 202_app_installs WHERE install_uuid='$U7'")" "1/1" \
   "the stored test install is re-judged: trusted, with its conversion"
eq "$(Q "SELECT CONCAT_WS('/', COUNT(*), SUM(deleted), MIN(dedupe_key)) FROM 202_conversion_logs WHERE click_id=$C7")" "1/0/install" "its install conversion is on its click"
eq "$(Q "SELECT click_lead FROM 202_clicks WHERE click_id=$C7")" 1 "and the click counts it"
eq "$(api PUT "/apps/$R" '{"accept_test_signals":0}')" 200 "the operator withdraws test signals again"
eq "$(Q "SELECT CONCAT_WS('/', IFNULL(trusted, 'null'), ISNULL(conversion_id)) FROM 202_app_installs WHERE install_uuid='$U7'")" "null/1" \
   "the install is unvouched again, off its click"
eq "$(Q "SELECT CONCAT_WS('/', COUNT(*), SUM(deleted)) FROM 202_conversion_logs WHERE click_id=$C7")" "1/1" "its conversion is retired"
eq "$(Q "SELECT click_lead FROM 202_clicks WHERE click_id=$C7")" 0 "and the click no longer counts it"

# ─────────────────────────────────────────────────────────────────────
say "a pending click is settled by the cron, which also sends the postbacks"
C8=$(click "$TRK" "$OUT/h8")
REF8=$(referrer_of "$OUT/h8")
# The redirect writes its click row after sending the visitor on; a store
# round trip can beat it. Hold the row back to reproduce that.
mysql_q "$DB" -e "CREATE TABLE p202_pass_hold_clicks AS SELECT * FROM 202_clicks WHERE click_id=$C8;
                  CREATE TABLE p202_pass_hold_spy AS SELECT * FROM 202_clicks_spy WHERE click_id=$C8;
                  DELETE FROM 202_clicks WHERE click_id=$C8; DELETE FROM 202_clicks_spy WHERE click_id=$C8;"
U8=$(uuid)
install_body "$OUT/i8.json" "$U8" "$REF8" "$(Q "SELECT click_time FROM p202_pass_hold_clicks")"
device POST /apps/installs "$TOKEN" "$OUT/i8.json" > /dev/null
eq "$(field "[d['data']['match'], d['data']['trusted']]")" '["pending_click", null]' "a token whose click is not written yet is pending"
events_body "$OUT/e8.json" "[{\"event_id\":\"l1\",\"name\":\"level_reached\",\"occurred_at\":$(date +%s),\"properties\":{\"level\":5}}]"
eq "$(device POST "/apps/installs/$U8/events" "$TOKEN" "$OUT/e8.json")" 503 "its events wait"
eq "$(awk 'tolower($1) == "retry-after:" {print $2+0}' "$OUT/headers")" 60 "with Retry-After"
mysql_q "$DB" -e "INSERT INTO 202_clicks SELECT * FROM p202_pass_hold_clicks; INSERT INTO 202_clicks_spy SELECT * FROM p202_pass_hold_spy;
                  DROP TABLE p202_pass_hold_clicks; DROP TABLE p202_pass_hold_spy;"
QUEUED_BEFORE=$(Q "SELECT COALESCE(MAX(notification_id), 0) FROM 202_notification_pending")
(cd "$ROOT" && "$PHP" 202-cronjobs/app-installs.php) > "$OUT/cron.txt" 2>&1
eq "$?" 0 "202-cronjobs/app-installs.php runs"
has "$OUT/cron.txt" "settled attributed=1" "and settles the install"
eq "$(Q "SELECT CONCAT_WS('/', match_state, trusted, conversion_id IS NOT NULL, settled_at IS NOT NULL) FROM 202_app_installs WHERE install_uuid='$U8'")" "attributed/1/1/1" \
   "attributed, with its conversion"
eq "$(device POST "/apps/installs/$U8/events" "$TOKEN" "$OUT/e8.json")" 200 "its events are accepted now"
eq "$(Q "SELECT GROUP_CONCAT(DISTINCT status) FROM 202_notification_pending WHERE kind='reached' AND destination=0 AND status <> 'cancelled' AND conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id=$C1)")" sent \
   "every queued postback to the accepting URL was sent"
eq "$(Q "SELECT GROUP_CONCAT(DISTINCT CONCAT_WS('/', status, attempts, last_error LIKE '%/api/v3/pr5-refuses?%')) FROM 202_notification_pending WHERE kind='reached' AND destination=1 AND status <> 'cancelled' AND conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id=$C1)")" "pending/1/1" \
   "the refusing URL's rows were tried once and wait to retry, naming it"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_pending WHERE status='pending' AND attempts=0 AND notification_id <= $QUEUED_BEFORE")" 0 "and nothing queued before the run was left untried"
# Every waiting row's backoff, spent (not just the refusing URL's: a row that
# still carried the accepting URL would be resent too, and the log count
# below must see that): the next run retries them.
Q "UPDATE 202_notification_pending SET next_attempt_at = 0 WHERE status='pending' AND attempts > 0"
(cd "$ROOT" && "$PHP" 202-cronjobs/app-installs.php) > "$OUT/cron2.txt" 2>&1
eq "$?" 0 "the job runs again"
eq "$(Q "SELECT GROUP_CONCAT(DISTINCT CONCAT_WS('/', status, attempts)) FROM 202_notification_pending WHERE kind='reached' AND destination=1 AND status <> 'cancelled' AND conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id=$C1)")" "pending/2" \
   "the refusing URL was retried"
eq "$(Q "SELECT GROUP_CONCAT(DISTINCT CONCAT_WS('/', status, attempts)) FROM 202_notification_pending WHERE kind='reached' AND destination=0 AND status <> 'cancelled' AND conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id=$C1)")" "sent/1" \
   "and the accepting URL was not"
if [ -n "$SERVER_LOG" ]; then
    has "$SERVER_LOG" "GET /api/v3/versions?pr5=android&sub=$C1&goal=install&v=2.50&tx=install" "the network received the install postback"
    has "$SERVER_LOG" "GET /api/v3/versions?pr5=android&sub=$C1&goal=Level%203&v=4.00" "and level 3's, each once"
    eq "$(grep -c "pr5=android&sub=$C1&goal=install&" "$SERVER_LOG")" 1 "the install postback arrived exactly once, though its sibling URL failed twice"
    eq "$(grep -c "GET /api/v3/pr5-refuses?sub=$C1&goal=install" "$SERVER_LOG")" 2 "the refusing URL was asked twice"
fi
(cd "$ROOT" && "$PHP" 202-cronjobs/app-installs.php extra) > "$OUT/cron-bad.txt" 2>&1
eq "$?" 1 "the job refuses an argument"

# ─────────────────────────────────────────────────────────────────────
say "the operator's reads and the CLI"
eq "$(api GET "/apps/$R/installs?match_state=bad_token")" 200 "installs by state"
eq "$(field "sorted(i['install_uuid'] for i in d['data'])")" "$(python3 -c "import json,sys; print(json.dumps(sorted(sys.argv[1:])))" "$U3" "$U4")" "the two bad tokens"
eq "$(api GET "/apps/$R/installs/$U1")" 200 "one install"
eq "$(field "[d['data']['match_state'], d['data']['click_id'], d['data']['is_test']]")" "[\"attributed\", $C1, false]" "with its click"
hasnt "$OUT/body" "raw_payload" "never the raw body"
eq "$(api GET "/apps/$R/installs?match_state=Attributed")" 422 "an unknown state is refused"
CLI="$OUT/p202"
if (cd "$ROOT/go-cli" && go build -o "$CLI" .) 2> "$OUT/build.err"; then
  mkdir -p "$OUT/home/.p202"
  printf '{"url": "%s", "api_key": "%s"}' "$BASE" "$P202_API_KEY" > "$OUT/home/.p202/config.json"
  p202() { HOME="$OUT/home" "$CLI" "$@"; }
  p202 app install list "$R" --match-state attributed --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))['data']))" "$OUT/cli.json" 2>/dev/null)" 3 "app install list --match-state attributed"
  p202 app install token "$R" --click "$C1" --json > "$OUT/cli.json" 2>/dev/null
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['install_token'])" "$OUT/cli.json" 2>/dev/null)" "$TOK1" "app install token"
  C9=$(click "$TRK" "$OUT/h9")
  U9=$(uuid)
  p202 app install simulate "$R" --click "$C9" --install-uuid "$U9" --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1]))['data']; print(d['match'], d['duplicate'])" "$OUT/cli.json" 2>/dev/null)" "attributed False" \
     "app install simulate posts as the SDK and is attributed"
  eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C9 AND dedupe_key='install'")" 1 "with the install conversion"
  p202 app install simulate "$R" --click "$C9" --install-uuid "$U9" --json > "$OUT/cli.json" 2>/dev/null
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['duplicate'])" "$OUT/cli.json" 2>/dev/null)" True "the same --install-uuid again is a duplicate"
  p202 app install simulate "$R" --click "$C9" --staged --json > "$OUT/cli.out" 2> "$OUT/cli.err"
  eq "$?" 1 "simulate refuses --staged"
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['error']['category'])" "$OUT/cli.err" 2>/dev/null)" validation "with a validation envelope"
  eq "$(wc -c < "$OUT/cli.out" | tr -d ' ')" 0 "and nothing on stdout"
  eq "$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE click_id=$C9")" 1 "and nothing posted"
  p202 app install list x --json > /dev/null 2> "$OUT/cli.err"
  has "$OUT/cli.err" "p202 app list --platform android" "a bad registration id names the command that lists them"
else
  bad "the CLI builds ($(head -3 "$OUT/build.err"))"
fi

say "deleting a registration unlinks its campaigns; registering the app again attributes them"
# An install of the other app still waiting for its click when the app goes.
UPEND=$(uuid)
Q "INSERT INTO 202_app_installs (user_id, registration_id, install_uuid, body_hash, store, match_state, match_reason, referrer_status, received_at, raw_payload)
   SELECT user_id, registration_id, '$UPEND', 'pending', 'google_play', 'pending_click', 'Click not recorded yet.', 'ok', UNIX_TIMESTAMP(), '{}'
   FROM 202_app_registrations WHERE registration_id=$R_OTHER"
eq "$(api DELETE "/apps/$R_OTHER?dry_run=1")" 200 "a delete preview"
eq "$(field "[c['action'] for c in d['data']['cascade'] if c['resource'] == 'campaigns']")" '["unlink (app_registration_id set to NULL)"]' "names the campaigns it unlinks"
has "$OUT/body" "pending_click → bad_token" "and the pending clicks it settles"
eq "$(api DELETE "/apps/$R_OTHER")" 204 "the other app's registration is deleted"
eq "$(Q "SELECT CONCAT_WS('/', match_state, trusted, settled_at IS NOT NULL) FROM 202_app_installs WHERE install_uuid='$UPEND'")" "bad_token/0/1" \
   "its pending click is settled as the deadline would, never paid, not left pending for good"
eq "$(api GET "/campaigns/$CAMP_B")" 200 "its campaign is kept"
eq "$(field "d['data']['app_registration_id']")" "" "unlinked in the delete"
eq "$(api POST /apps '{"store_link":"com.p202.pass.other","app_name":"Other again"}')" 201 "the app is registered again"
R_AGAIN=$(field "d['data']['registration_id']")
C10=$(click "$TRK_B" "$OUT/h10")
eq "$(api GET "/apps/$R_AGAIN/install-token?click_id=$C10")" 200 "a token for the campaign's click is no longer refused as another app's"
eq "$(api PUT "/campaigns/$CAMP_B" "{\"app_registration_id\":$R_AGAIN}")" 200 "and the campaign links to the new registration"

say "retention and the rate limit"
(cd "$ROOT" && "$PHP" 202-cronjobs/app-retention.php --dry-run) > "$OUT/ret.txt" 2>&1
has "$OUT/ret.txt" "installs/refuted: 90-day window" "installs have a refuted class"
has "$OUT/ret.txt" "installs/unvouched: 180-day window" "and an unvouched one"
: > "$OUT/codes"
for _ in $(seq 1 130); do
    curl -s -o /dev/null -D "$OUT/rl" -w '%{http_code}\n' "$BASE/api/v3/apps/installs" >> "$OUT/codes"
done
eq "$(grep -c '^429$' "$OUT/codes" | awk '{print ($1 > 0)}')" 1 "a peer past 120 requests a minute is answered 429"
eq "$(awk 'tolower($1) == "retry-after:" {print ($2 > 0)}' "$OUT/rl")" 1 "with Retry-After"
eq "$(curl -s -o /dev/null -w '%{http_code}' -H "X-P202-App-Token: $TOKEN" "$BASE/api/v3/apps/schema")" 200 "the schema route keeps its own bucket"
printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
