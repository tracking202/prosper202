#!/bin/bash
# Live pass for the Android intake's abuse limits (plan §7.1, §8.3), driven
# against a running instance over HTTP — the SDK's requests with the app
# token only, the operator's through the REST API and the pages — with the
# database read back after every step:
#
#   - the registration's limits: the defaults a new app gets, each refused
#     by name when malformed (read raw, never cast), and the Setup page's
#     Advanced form carrying all six;
#   - click-to-install time: real clicks through dl.php, installs whose
#     Google install-begin sits 40 s and 100 s after them, recorded on the
#     row and flagged `ok`, `short` (the minimum raised) and `long` (the
#     maximum lowered); the report's ctit counts, the ctit-flag grouping and
#     filter, the install list's filter, and Analyze › Mobile Apps' Fraud
#     signals panel;
#   - the install cap: past install_cap_per_minute the intake answers 429
#     with Retry-After, and nothing is stored or counted; a replay is still
#     answered; another app's budget is its own; an unreadable cap is a 503
#     naming it;
#   - the event cap: a batch that would pass event_cap_per_minute is 429 and
#     stored nowhere, the rest of the budget still spendable; a replayed
#     batch at the cap is answered with its duplicates and costs nothing; a
#     body carrying only a customer id is capped too;
#   - refunds: an install and an events batch admitted and then failed (a
#     trigger planted to fail the insert) spend none of the budget, so the
#     SDK's retry is admitted;
#   - goals reached too fast: flagged and paid (and sent) under `count`,
#     flagged and neither paid nor sent under `hold`; the report's
#     fast_goals count and filter;
#   - the upgrade page's backup warning, above its button (the stored
#     version wound back for the GET and put back after).
#
# Truncates the app, goal and outbox tables, so it needs a scratch database.
# Each app spends its own budget and the pass posts well under the per-peer
# limit, so it can run after android-intake.sh as long as that one's last
# step (which trips the peer bucket) has had its minute.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
P202_USER=${P202_USER:-}
P202_PASS=${P202_PASS:-}

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
device() {
    local args=(-s -o "$OUT/body" -D "$OUT/headers" -w '%{http_code}' -X "$1" -H "X-P202-App-Token: $3")
    [ -n "${4:-}" ] && args+=(-H 'Content-Type: application/json' --data-binary "@$4")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
header() { awk -v h="$(printf '%s' "$1" | tr 'A-Z' 'a-z')" 'BEGIN{FS=": "} tolower($1)==h {sub(/\r$/,"",$2); print $2}' "$OUT/headers"; }

# install_body FILE UUID REFERRER CLICK_TIME BEGIN_DELAY [APP_KEY] — an
# SDK-shaped install: Google's store click 3 s after ours, its install-begin
# BEGIN_DELAY s after ours.
install_body() {
    python3 - "$@" <<'PY'
import json, sys
path, uuid, referrer, t, delay = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4]), int(sys.argv[5])
app_key = sys.argv[6] if len(sys.argv) > 6 else 'com.p202.abuse.summit'
json.dump({
    "install_uuid": uuid, "app_key": app_key, "store": "google_play",
    "referrer": {"status": "ok", "install_referrer": referrer,
                 "referrer_click_timestamp_seconds": t + 2, "install_begin_timestamp_seconds": t + delay - 1,
                 "referrer_click_timestamp_server_seconds": t + 3, "install_begin_timestamp_server_seconds": t + delay,
                 "install_version": "3.2.0", "google_play_instant": False},
    "first_open_at": t + delay + 20, "app_version": "3.2.0", "sdk_version": "1.0.0", "os_version": "15",
    "test": False, "integrity_token": None,
}, open(path, 'w'))
PY
}
# events_body FILE PREFIX N FIRST_AT — N events named tick, one second apart.
events_body() {
    python3 -c "
import json, sys
p, prefix, n, t = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])
json.dump({'events': [{'event_id': prefix + str(i), 'name': 'tick', 'occurred_at': t + i} for i in range(n)]}, open(p, 'w'))" "$@"
}
UA='Mozilla/5.0 (Linux; Android 14; Pixel 8) android-abuse-pass'
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
uuid() { python3 -c 'import uuid; print(uuid.uuid4())'; }
# ctime CLICK — the click's time, first moved an hour into the past: the
# install bodies date Google's install-begin up to 100 s after the click, and
# an install that began in the server's future would make every event after
# it read as reached before it (an event's time is min(occurred, received)).
ctime() {
    Q "UPDATE 202_clicks SET click_time = click_time - 3600 WHERE click_id = $1; UPDATE 202_clicks_spy SET click_time = click_time - 3600 WHERE click_id = $1"
    Q "SELECT click_time FROM 202_clicks WHERE click_id = $1"
}
row() { Q "SELECT $2 FROM 202_app_installs WHERE install_uuid = '$1'"; }

cleanup() {
  mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%'));
DELETE FROM 202_conversion_logs WHERE campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%');
DELETE FROM 202_dataengine WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%');
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%');
DELETE FROM 202_clicks WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%');
DELETE FROM 202_trackers WHERE aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%');
DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN (SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name = 'abuse-pass');
DELETE FROM 202_ppc_accounts WHERE ppc_account_name = 'abuse-pass';
DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'abuse-pass%';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
TRUNCATE 202_notification_pending;
-- DELETE, not TRUNCATE: the caps' buckets are keyed on registration and
-- install ids, so ids a rerun inside the same minute reused would read the
-- last run's counts.
DELETE FROM 202_app_installs; DELETE FROM 202_app_registrations;
SQL
}
cleanup
VERSION_BEFORE=$(Q "SELECT version FROM 202_version LIMIT 1")
restore_version() { Q "UPDATE 202_version SET version = '$VERSION_BEFORE'"; }
trap 'cleanup; restore_version' EXIT

# ─────────────────────────────────────────────────────────────────────
say "setup: an Android app with the default limits, a linked campaign, a traffic source"
eq "$(api POST /apps '{"store_link":"com.p202.abuse.summit","app_name":"Summit"}')" 201 "an Android app"
R=$(field "d['data']['registration_id']"); TOKEN=$(field "d['data']['app_token']")
eq "$(field "[d['data'][k] for k in ('ctit_min_seconds','ctit_max_seconds','install_cap_per_minute','event_cap_per_minute','fast_goal_seconds','fast_goal_policy')]")" \
   '[10, 86400, 300, 200, 5, "count"]' "the default limits: CTIT 10 s / 1 day, 300 installs and 200 events a minute, goals under 5 s flagged and paid"
for bad_limit in '{"install_cap_per_minute":"1e3"}' '{"install_cap_per_minute":0}' '{"event_cap_per_minute":99}' '{"ctit_min_seconds":"07"}' \
                 '{"ctit_max_seconds":59}' '{"fast_goal_seconds":3601}' '{"fast_goal_policy":"Hold"}' '{"fast_goal_seconds":null}' \
                 '{"ctit_min_seconds":86400}'; do
    key=$(printf '%s' "$bad_limit" | python3 -c 'import json,sys; print(list(json.load(sys.stdin))[0])')
    code=$(api PUT "/apps/$R" "$bad_limit")
    if [ "$code" = 422 ] && [ -n "$(field "d['field_errors']['$key']")" ]; then ok "$bad_limit is refused, naming $key"; else bad "$bad_limit: $code $(cat "$OUT/body")"; fi
done
eq "$(api POST /apps '{"store_link":"990088002","app_name":"iOS","install_cap_per_minute":5}')" 422 "an iOS app takes no Android limit"
eq "$(Q "SELECT CONCAT_WS(',', ctit_min_seconds, install_cap_per_minute, fast_goal_policy) FROM 202_app_registrations WHERE registration_id = $R")" \
   "10,300,count" "nothing refused was stored"

eq "$(api POST /aff-networks '{"aff_network_name":"abuse-pass"}')" 201 "an affiliate network"
NET=$(field "d['data']['aff_network_id']")
STORE='https://play.google.com/store/apps/details?id=com.p202.abuse.summit&referrer=p202%3D[[p202_install_token]]'
eq "$(api POST /campaigns "{\"aff_campaign_name\":\"abuse-pass A\",\"aff_campaign_url\":\"$STORE\",\"aff_campaign_payout\":\"2.50\",\"aff_network_id\":$NET,\"payout_mode\":\"accumulate\",\"app_registration_id\":$R}")" 201 "a campaign linked to the app"
CAMP=$(field "d['data']['aff_campaign_id']")
eq "$(api POST /ppc-networks '{"ppc_network_name":"abuse-pass"}')" 201 "a traffic source"
PNET=$(field "d['data']['ppc_network_id']")
eq "$(api POST /ppc-accounts "{\"ppc_account_name\":\"abuse-pass\",\"ppc_network_id\":$PNET}")" 201 "and its account"
PACC=$(field "d['data']['ppc_account_id']")
Q "INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('$BASE/api/v3/versions?abuse=1&sub=[[subid]]&goal=[[p202_goal]]', 4, $PACC)"
eq "$(api POST /trackers "{\"aff_campaign_id\":$CAMP,\"ppc_account_id\":$PACC}")" 201 "a tracker"
TRK=$(field "d['data']['tracker_id_public']")
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP,\"payout\":3,\"definition\":{\"name\":\"Signup\",\"trigger\":{\"event\":\"signup\"}}}")" 201 "a Signup goal paying \$3"
G_SIGNUP=$(field "d['data']['goal_id']")
G_INSTALL=$(Q "SELECT goal_id FROM 202_goals WHERE builtin = 'install' AND scope_id = $R")
eq "$(api PUT "/goals/$G_INSTALL/campaigns/$CAMP" '{}')" 200 "the campaign lists the install goal"

# ─────────────────────────────────────────────────────────────────────
say "click-to-install time: recorded and flagged against the app's thresholds"
C1=$(click "$TRK" "$OUT/h1"); T1=$(ctime "$C1"); U1=$(uuid)
install_body "$OUT/i1.json" "$U1" "$(referrer_of "$OUT/h1")" "$T1" 40
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i1.json")" 200 "an install 40 s after its click"
eq "$(row "$U1" "CONCAT_WS(',', match_state, ctit_seconds, ctit_flag)")" "attributed,40,ok" "is attributed, with a CTIT of 40 s, ok"

eq "$(api PUT "/apps/$R" '{"ctit_min_seconds":"60"}')" 200 "the short threshold raised to 60 s"
C2=$(click "$TRK" "$OUT/h2"); T2=$(ctime "$C2"); U2=$(uuid)
install_body "$OUT/i2.json" "$U2" "$(referrer_of "$OUT/h2")" "$T2" 40
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i2.json")" 200 "the next install, 40 s after its click"
eq "$(row "$U2" "CONCAT_WS(',', match_state, ctit_seconds, ctit_flag)")" "attributed,40,short" "is flagged short, and still attributed: a flag never refuses"

eq "$(api PUT "/apps/$R" '{"ctit_min_seconds":10,"ctit_max_seconds":60}')" 200 "the long threshold lowered to 60 s"
C3=$(click "$TRK" "$OUT/h3"); T3=$(ctime "$C3"); U3=$(uuid)
install_body "$OUT/i3.json" "$U3" "$(referrer_of "$OUT/h3")" "$T3" 100
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i3.json")" 200 "an install 100 s after its click"
eq "$(row "$U3" "CONCAT_WS(',', ctit_seconds, ctit_flag)")" "100,long" "is flagged long"
U4=$(uuid)
install_body "$OUT/i4.json" "$U4" "" "$T3" 40
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i4.json")" 200 "an organic install"
eq "$(row "$U4" "COALESCE(ctit_flag, 'NULL')")" "NULL" "has no click to measure from"

eq "$(api GET "/apps/report?platform=android&registration_id=$R")" 200 "the Android report"
eq "$(field "[d['data']['totals'][k] for k in ('received','ctit_measured','ctit_short','ctit_long')]")" '[4, 3, 1, 1]' \
   "counts 3 measured installs, 1 short and 1 long of the 4"
eq "$(api GET "/apps/report?platform=android&registration_id=$R&group_by=ctit-flag")" 200 "grouped by ctit-flag"
eq "$(field "sorted((g['ctit_flag'], g['received']) for g in d['data']['groups'])")" '[["long", 1], ["ok", 1], ["short", 1], ["unmeasured", 1]]' "one install in each"
eq "$(api GET "/apps/report?platform=android&ctit_flag=short")" 200 "filtered to the short tail"
eq "$(field "d['data']['totals']['received']")" 1 "one install"
eq "$(api GET "/apps/report?platform=android&ctit_flag=quick")" 422 "an unknown tail is refused"
eq "$(api GET "/apps/report?ctit_flag=short")" 422 "and on the iOS report, which has no installs"
eq "$(api GET "/apps/$R/installs?ctit_flag=long")" 200 "the install list filtered to the long tail"
eq "$(field "[(i['install_uuid'], i['ctit_seconds'], i['ctit_flag']) for i in d['data']]")" "[[\"$U3\", 100, \"long\"]]" "names that install, with its CTIT"

# ─────────────────────────────────────────────────────────────────────
say "goals reached too fast: flagged and paid under count, held under hold"
eval_at() { echo $(( $(row "$1" install_begin_server_at) + $2 )); }
printf '{"events":[{"event_id":"s1","name":"signup","occurred_at":%s}]}' "$(eval_at "$U1" 2)" > "$OUT/e1.json"
eq "$(device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/e1.json")" 200 "a signup 2 s after the install"
IROW1=$(row "$U1" install_row_id)
eq "$(Q "SELECT CONCAT_WS(',', too_fast, payable) FROM 202_goal_outcomes WHERE subject_id = $IROW1 AND goal_id = $G_SIGNUP")" "1,1" "is flagged too fast, and paid under count"
eq "$(Q "SELECT too_fast FROM 202_goal_outcomes WHERE subject_id = $IROW1 AND goal_id = $G_INSTALL")" 0 "the install is never too fast after itself"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_pending WHERE url LIKE '%goal=Signup%'")" 1 "and its postback is queued"

eq "$(api PUT "/apps/$R" '{"fast_goal_policy":"hold","fast_goal_seconds":30}')" 200 "the app switched to hold, under 30 s"
printf '{"events":[{"event_id":"s1","name":"signup","occurred_at":%s}]}' "$(eval_at "$U2" 10)" > "$OUT/e2.json"
eq "$(device POST "/apps/installs/$U2/events" "$TOKEN" "$OUT/e2.json")" 200 "a signup 10 s after the next install"
IROW2=$(row "$U2" install_row_id)
eq "$(Q "SELECT CONCAT_WS(',', too_fast, payable) FROM 202_goal_outcomes WHERE subject_id = $IROW2 AND goal_id = $G_SIGNUP")" "1,0" "is flagged and held"
eq "$(Q "SELECT payable FROM 202_conversion_logs WHERE click_id = $C2 AND source = 'goal'")" 0 "its ledger row is tracked, not paid"
eq "$(Q "SELECT COUNT(*) FROM 202_notification_pending WHERE url LIKE '%goal=Signup%'")" 1 "and nothing more is sent"
printf '{"events":[{"event_id":"s1","name":"signup","occurred_at":%s}]}' "$(eval_at "$U3" 100)" > "$OUT/e3.json"
eq "$(device POST "/apps/installs/$U3/events" "$TOKEN" "$OUT/e3.json")" 200 "a signup 100 s after a third install"
eq "$(Q "SELECT CONCAT_WS(',', too_fast, payable) FROM 202_goal_outcomes WHERE subject_id = $(row "$U3" install_row_id) AND goal_id = $G_SIGNUP")" "0,1" "is neither flagged nor held"

eq "$(api GET "/apps/report?platform=android&registration_id=$R")" 200 "the report"
eq "$(field "[d['data']['totals'][k] for k in ('fast_goals',)] + [d['data']['totals']['events']['Signup']['count'], d['data']['totals']['events']['Signup']['revenue']]")" \
   '[2, 3, 6]' "counts 2 of the 3 signups too fast, with revenue from the two paid (the held one carries none)"
eq "$(api GET "/apps/report?platform=android&fast_goals=1")" 200 "filtered to installs with a fast goal"
eq "$(field "d['data']['totals']['received']")" 2 "two installs"
eq "$(api GET "/apps/report?platform=android&fast_goals=yes")" 422 "a filter value that is not 0 or 1 is refused"

# ─────────────────────────────────────────────────────────────────────
say "the event cap: a batch that would pass it is refused whole"
eq "$(api PUT "/apps/$R" '{"event_cap_per_minute":100}')" 200 "the event cap lowered to 100 a minute"
# The organic install has posted nothing yet, so its minute starts empty.
events_body "$OUT/b1.json" a 60 "$(eval_at "$U4" 200)"
eq "$(device POST "/apps/installs/$U4/events" "$TOKEN" "$OUT/b1.json")" 200 "60 events"
events_body "$OUT/b2.json" b 60 "$(eval_at "$U4" 300)"
eq "$(device POST "/apps/installs/$U4/events" "$TOKEN" "$OUT/b2.json")" 429 "60 more are refused"
wait_s=$(header Retry-After)
if [ -n "$wait_s" ] && [ "$wait_s" -ge 1 ] && [ "$wait_s" -le 60 ]; then ok "with Retry-After: $wait_s"; else bad "Retry-After '$wait_s'"; fi
has "$OUT/body" "event_cap_per_minute = 100" "the answer names the cap"
IROW4=$(row "$U4" install_row_id)
eq "$(Q "SELECT COUNT(*) FROM 202_goal_events WHERE subject_type = 'install' AND subject_id = $IROW4")" 60 "none of the refused batch was stored"
events_body "$OUT/b3.json" c 40 "$(eval_at "$U4" 400)"
eq "$(device POST "/apps/installs/$U4/events" "$TOKEN" "$OUT/b3.json")" 200 "the 40 left in the budget still go through"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_events WHERE subject_type = 'install' AND subject_id = $IROW4")" 100 "100 stored in all"
events_body "$OUT/b4.json" d 60 "$(eval_at "$U1" 400)"
eq "$(device POST "/apps/installs/$U1/events" "$TOKEN" "$OUT/b4.json")" 200 "another install has its own budget"
eq "$(device POST "/apps/installs/$U4/events" "$TOKEN" "$OUT/b1.json")" 200 "at the cap, a replay of the first 60 is still answered"
eq "$(field "[len(d['data']['accepted']), len(d['data']['duplicates'])]")" "[0, 60]" "as 60 duplicates"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_events WHERE subject_type = 'install' AND subject_id = $IROW4")" 100 "storing nothing"
printf '{"customer":{"id":"u-829","type":"custom","signature":"%s"}}' "$(printf 'a%.0s' $(seq 64))" > "$OUT/cust.json"
eq "$(device POST "/apps/installs/$U4/events" "$TOKEN" "$OUT/cust.json")" 429 "a customer id alone is under the same cap"
has "$OUT/body" "event_cap_per_minute = 100" "and the answer names it"

# ─────────────────────────────────────────────────────────────────────
say "refunds: a request admitted and then failed spends nothing"
fail_inserts() { mysql_q "$DB" -e "CREATE TRIGGER p202_live_planted_failure BEFORE INSERT ON $1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted failure'"; }
stop_failing() { mysql_q "$DB" -e "DROP TRIGGER IF EXISTS p202_live_planted_failure"; }
trap 'stop_failing; cleanup; restore_version' EXIT
U7=$(uuid); install_body "$OUT/i7.json" "$U7" "" "$T3" 40
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i7.json")" 200 "a fresh install, its event budget untouched"
IROW7=$(row "$U7" install_row_id)
fail_inserts 202_goal_events
events_body "$OUT/f1.json" f 60 "$(eval_at "$U7" 200)"
code=$(device POST "/apps/installs/$U7/events" "$TOKEN" "$OUT/f1.json")
stop_failing
if [ "${code:0:1}" = 5 ]; then ok "a batch of 60 the store fails is a $code"; else bad "the failed batch answered $code"; fi
eq "$(Q "SELECT COUNT(*) FROM 202_goal_events WHERE subject_type = 'install' AND subject_id = $IROW7")" 0 "and stored nothing"
events_body "$OUT/f2.json" g 100 "$(eval_at "$U7" 300)"
eq "$(device POST "/apps/installs/$U7/events" "$TOKEN" "$OUT/f2.json")" 200 "the whole budget of 100 is still there: the 60 were refunded"
eq "$(device POST "/apps/installs/$U7/events" "$TOKEN" "$OUT/f2.json")" 200 "and a replay of those 100 at the cap is answered"
eq "$(field "len(d['data']['duplicates'])")" 100 "as duplicates"
events_body "$OUT/f3.json" h 1 "$(eval_at "$U7" 500)"
eq "$(device POST "/apps/installs/$U7/events" "$TOKEN" "$OUT/f3.json")" 429 "while one new event is refused"

eq "$(api POST /apps '{"store_link":"com.p202.abuse.single","app_name":"Single","install_cap_per_minute":1}')" 201 "an app capped at 1 install a minute"
TOKEN_S=$(field "d['data']['app_token']")
US1=$(uuid); install_body "$OUT/s1.json" "$US1" "" "$T3" 40 com.p202.abuse.single
fail_inserts 202_app_installs
code=$(device POST /apps/installs "$TOKEN_S" "$OUT/s1.json")
stop_failing
if [ "${code:0:1}" = 5 ]; then ok "an install the store fails is a $code"; else bad "the failed install answered $code"; fi
eq "$(row "$US1" "COUNT(*)")" 0 "and is not stored"
eq "$(device POST /apps/installs "$TOKEN_S" "$OUT/s1.json")" 200 "the SDK's retry is admitted: the failed attempt was refunded"
US2=$(uuid); install_body "$OUT/s2.json" "$US2" "" "$T3" 40 com.p202.abuse.single
eq "$(device POST /apps/installs "$TOKEN_S" "$OUT/s2.json")" 429 "and the cap still holds for what was recorded"

# ─────────────────────────────────────────────────────────────────────
say "the install cap: past it nothing is stored, and the SDK is told when to retry"
eq "$(api POST /apps '{"store_link":"com.p202.abuse.capped","app_name":"Capped","install_cap_per_minute":3}')" 201 "an app capped at 3 installs a minute"
RC=$(field "d['data']['registration_id']"); TOKEN_C=$(field "d['data']['app_token']")
for n in 1 2 3; do
    UC=$(uuid); install_body "$OUT/c$n.json" "$UC" "" "$T3" 40 com.p202.abuse.capped
    eq "$(device POST /apps/installs "$TOKEN_C" "$OUT/c$n.json")" 200 "install $n of 3"
done
U_OVER=$(uuid); install_body "$OUT/c4.json" "$U_OVER" "" "$T3" 40 com.p202.abuse.capped
eq "$(device POST /apps/installs "$TOKEN_C" "$OUT/c4.json")" 429 "the fourth is refused"
wait_s=$(header Retry-After)
if [ -n "$wait_s" ] && [ "$wait_s" -ge 1 ] && [ "$wait_s" -le 60 ]; then ok "with Retry-After: $wait_s"; else bad "Retry-After '$wait_s'"; fi
eq "$(field "d['retry_after_seconds']")" "$wait_s" "the body says the same"
has "$OUT/body" "install_cap_per_minute = 3" "and names the cap"
eq "$(Q "SELECT COUNT(*) FROM 202_app_installs WHERE registration_id = $RC")" 3 "nothing was stored past the cap"
eq "$(row "$U_OVER" "COUNT(*)")" 0 "the refused install is nowhere"
eq "$(device POST /apps/installs "$TOKEN_C" "$OUT/c1.json")" 200 "a replay of a stored install is still answered"
eq "$(field "d['data']['duplicate']")" true "as the duplicate it is"
U5=$(uuid); install_body "$OUT/i5.json" "$U5" "" "$T3" 40
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i5.json")" 200 "the first app's budget is its own"

Q "UPDATE 202_app_registrations SET install_cap_per_minute = 0 WHERE registration_id = $R"
U6=$(uuid); install_body "$OUT/i6.json" "$U6" "" "$T3" 40
eq "$(device POST /apps/installs "$TOKEN" "$OUT/i6.json")" 503 "a cap no write would store is a 503"
has "$OUT/body" "install_cap_per_minute" "naming the setting"
eq "$(header Retry-After)" 300 "with Retry-After"
eq "$(row "$U6" "COUNT(*)")" 0 "and nothing stored"
Q "UPDATE 202_app_registrations SET install_cap_per_minute = 300 WHERE registration_id = $R"

# ─────────────────────────────────────────────────────────────────────
if [ -n "$P202_USER" ] && [ -n "$P202_PASS" ]; then
    say "the pages: Setup's Advanced limits and Analyze's Fraud signals"
    JAR="$OUT/jar"
    curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
    LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
    curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
        --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
    curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/setup/mobile_apps.php?app=$R" -o "$OUT/setup.html"
    for f in ctit_min_seconds ctit_max_seconds install_cap_per_minute event_cap_per_minute fast_goal_seconds fast_goal_policy; do
        has "$OUT/setup.html" "name=\"$f\"" "Setup's settings form carries $f"
    done
    has "$OUT/setup.html" '<option value="hold" selected>' "and shows the app's hold policy"
    curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/analyze/mobile_apps.php?platform=android&registration_id=$R&range=last7" -o "$OUT/an.html"
    has "$OUT/an.html" "Fraud signals" "Analyze › Mobile Apps has the Fraud signals panel"
    python3 - "$OUT/an.html" <<'PY' && ok "which counts 1 short, 1 long, 3 measured and 2 fast goals" || bad "the panel's counts"
import re, sys
html = open(sys.argv[1]).read()
panel = html[html.index('Fraud signals'):]
cells = re.findall(r'<td><a [^>]*>([^<]+)</a></td><td class="num">([^<]+)</td>', panel)[:4]
want = [('Installs too soon after the click', '1'), ('Installs too long after the click', '1'),
        ('Installs with a click-to-install time', '3'), ('Goals reached too soon after the install', '2')]
sys.exit(0 if cells == want else print(cells) or 1)
PY
    curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/analyze/mobile_apps.php?platform=android&registration_id=$R&range=last7&ctit_flag=short" -o "$OUT/an2.html"
    has "$OUT/an2.html" "click-to-install time is <strong>short</strong>" "the ctit filter narrows the page and says so"
else
    say "the pages: skipped (set P202_USER and P202_PASS)"
fi

# ─────────────────────────────────────────────────────────────────────
say "the upgrade page says a backup is required, before its button"
Q "UPDATE 202_version SET version = '1.9.75'"
curl -s "$BASE/202-config/upgrade.php" -o "$OUT/upgrade.html"
python3 - "$OUT/upgrade.html" <<'PY' && ok "the warning sits inside the form, above the button" || bad "the warning's place"
import sys
h = open(sys.argv[1]).read()
w, b, f = h.find('id="upgrade-backup-warning"'), h.find('id="upgrade-submit"'), h.find('id="upgrade-form"')
sys.exit(0 if -1 < f < w < b else print(f, w, b) or 1)
PY
has "$OUT/upgrade.html" "Back up your database before you press the button." "it asks for the backup"
has "$OUT/upgrade.html" "restoring that backup is the only way back" "and says restoring it is the only way back"
has "$OUT/upgrade.html" "<code>202_conversion_logs.dedupe_key</code> required (NOT NULL)" "and why, from below 1.9.76"
has "$OUT/upgrade.html" 'class="alert alert-warning p202-flash" role="status" id="upgrade-backup-warning"' "as the kit's warning flash"
restore_version

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
rm -rf "$OUT"
[ "$FAIL" -eq 0 ]
