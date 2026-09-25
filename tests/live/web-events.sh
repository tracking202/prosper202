#!/bin/bash
# Live pass for web events (PR 4b), driven against a running instance with
# the database read back after every step:
#
#   - pixels and postbacks with `event=`: gpb, gpx, upx, pb and px store the
#     event on the click and the campaign's goals decide what it is worth —
#     a fixed-value signup, a purchase paid from `amount`, an upsell that
#     waits for the purchase, a tracked view — with the ledger rows, the
#     click's accumulated value and the goal outcomes read back; a retry is a
#     duplicate; malformed parameters are refused by name; a reversal cannot
#     be an event; the per-campaign postback keeps its campaign scope;
#   - legacy: a campaign without goals records `event=` hits as the plain
#     conversion it always did (with the name on the row), and a pixel
#     without `event=` on a goal campaign is still a plain conversion;
#   - traffic-source notification: each payable goal the campaign notifies
#     for is sent once, as a server-to-server postback with [[p202_goal]],
#     [[p202_goal_value]] and [[transactionid]] filled (read back from the
#     instance's own request log), a goal that does not notify is not sent,
#     a duplicate and a replay's replacement rows are never sent again, and
#     the universal pixel returns the browser pixel with the goal filled;
#   - POST /events: strict body, 201/200, events on a campaign without goals
#     stored with a note, Idempotency-Key, the `events` scope area, staging;
#   - p202.track()'s intake (tracking202/static/event.php): a visitor id tied
#     to its landing page click by record.php, the event recorded against that
#     click with its revenue untrusted, an unknown visitor answered 204, the
#     method and CORS answers.
#
# The browser half of p202.track() — the real script on a real page — is
# tests/browser/specs/web-events.spec.js.
#
# Environment: P202_BASE, P202_DB, P202_DB_USER/P202_DB_PASS, P202_API_KEY
# (the instance's admin key), P202_USER/P202_PASS (its login, for the goal
# editor), P202_CLI_PREPEND (a file to prepend to the PHP CLI on a partial
# vendor/, as CLAUDE.md describes), P202_SERVER_LOG (the instance's php -S log,
# where its self-addressed traffic-source postbacks are read back; the
# server must run with PHP_CLI_SERVER_WORKERS >= 2 so a postback to itself
# is served while the request that sends it is still open).
#
# Seeds its own campaigns and clicks (ids 960001-960040) and truncates the
# goal tables, so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
SERVER_LOG=${P202_SERVER_LOG:-}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass drives the REST API as the instance's admin." >&2
    exit 2
fi
if [ -z "$SERVER_LOG" ] || [ ! -r "$SERVER_LOG" ]; then
    echo "P202_SERVER_LOG must name the instance's readable php -S log: traffic-source postbacks are read back from it." >&2
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

# api METHOD PATH [JSON-BODY] [KEY] [EXTRA-HEADER] — body in $OUT/body; prints the status.
api() {
    local key=${4-$P202_API_KEY}
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $key")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    [ -n "${5:-}" ] && args+=(-H "$5")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# hit PATH-AND-QUERY — a public endpoint; body in $OUT/body; prints the status.
hit() { curl -s -o "$OUT/body" -w '%{http_code}' "$BASE$1"; }
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
value() { Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$1"; }
rows()  { Q "SELECT GROUP_CONCAT(CONCAT(source, ':', COALESCE(event_name,'-'), ':', click_payout, ':', payable, ':', IF(superseded_by IS NULL, 'live', 'sup')) ORDER BY conv_id SEPARATOR ' ') FROM 202_conversion_logs WHERE click_id=$1 AND deleted=0"; }
events(){ Q "SELECT COUNT(*) FROM 202_goal_events WHERE subject_type='click' AND subject_id=$1"; }
live()  { Q "SELECT COUNT(*) FROM 202_goal_outcomes WHERE subject_type='click' AND subject_id=$1 AND superseded_at IS NULL"; }
# notified GOAL-NAME — how many traffic-source postbacks this pass's sink got for that goal so far.
notified() { grep -c "GET /tracking202/static/index.html?p202sink=$RUN&g=$1&" "$SERVER_LOG"; }

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }
RUN=$(date +%s)$RANDOM
NOW=$(date +%s)
T=$((NOW - 3600))
PPC=960900
ACIP_G=960100; ACIP_N=960101; ACIP_R=960102; LP_PUBLIC=960200
C_GPB=960001; C_GPX=960002; C_UPX=960003; C_PB=960004; C_PX=960005; C_BAD=960006; C_PLAIN=960007
C_LEG=960008; C_LEG2=960009; C_API=960010; C_APIN=960011; C_STAGE=960012; C_REPLAY=960013; C_OTHER=960014; C_CLI=960015
CLICKS="$C_GPB,$C_GPX,$C_UPX,$C_PB,$C_PX,$C_BAD,$C_PLAIN,$C_LEG,$C_LEG2,$C_API,$C_APIN,$C_STAGE,$C_REPLAY,$C_OTHER,$C_CLI"

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICKS) OR campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP_G, $ACIP_N, $ACIP_R)));
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICKS) OR campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP_G, $ACIP_N, $ACIP_R));
DELETE FROM 202_dataengine WHERE click_id IN ($CLICKS);
DELETE o FROM 202_identity_observations o JOIN 202_clicks c ON c.click_id = o.click_id JOIN 202_landing_pages lp ON lp.landing_page_id = c.landing_page_id WHERE lp.landing_page_id_public = $LP_PUBLIC;
DELETE c FROM 202_clicks c JOIN 202_landing_pages lp ON lp.landing_page_id = c.landing_page_id WHERE lp.landing_page_id_public = $LP_PUBLIC;
DELETE FROM 202_clicks WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_spy WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_tracking WHERE click_id IN ($CLICKS);
DELETE FROM 202_landing_pages WHERE landing_page_id_public = $LP_PUBLIC;
DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = $PPC;
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP_G, $ACIP_N, $ACIP_R);
DELETE FROM 202_aff_networks WHERE aff_network_name = 'web events pass';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
SQL
}
cleanup
# P202_KEEP=1 leaves the seeded rows in place for a look afterwards.
[ "${P202_KEEP:-}" = 1 ] || trap cleanup EXIT

mysql_q "$DB" -e "INSERT INTO 202_aff_networks SET user_id=$OWNER, aff_network_name='web events pass', aff_network_deleted=0, aff_network_time=$NOW"
NET=$(Q "SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='web events pass'")
mysql_q "$DB" <<SQL
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode)
  VALUES ($ACIP_G, $OWNER, $NET, 'web events goals', 'http://example.test/', 0, $NOW, 0, 'accumulate'),
         ($ACIP_N, $OWNER, $NET, 'web events no goals', 'http://example.test/', 2.50, $NOW, 2.50, 'replace'),
         ($ACIP_R, $OWNER, $NET, 'web events other', 'http://example.test/', 0, $NOW, 0, 'accumulate');
SQL
CAMP_G=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_G")
CAMP_N=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_N")
CAMP_R=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_R")
[ -n "$CAMP_G" ] && [ -n "$CAMP_N" ] && [ -n "$CAMP_R" ] || { echo "seeding campaigns failed" >&2; exit 2; }
seed_click() { # id campaign
  mysql_q "$DB" -e "
    INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
      VALUES ($1, $OWNER, $2, 0, $PPC, 0.10, 0, 0, 0, 0, 0, $T, 0, 0);
    INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
      VALUES ($1, $OWNER, $2, 0, $PPC, 0.10, 0, 0, 0, 0, 0, $T);
    INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES ($1, 0, 0, 0, 0);"
}
for c in $C_GPB $C_GPX $C_UPX $C_PB $C_PX $C_BAD $C_PLAIN $C_API $C_STAGE $C_REPLAY $C_CLI; do seed_click "$c" "$CAMP_G"; done
for c in $C_LEG $C_LEG2 $C_APIN; do seed_click "$c" "$CAMP_N"; done
seed_click $C_OTHER "$CAMP_R"
[ "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id IN ($CLICKS)")" = 15 ] || { echo "seeding clicks failed" >&2; exit 2; }

# The traffic source: a server-to-server postback addressed to this instance
# (read back from its request log) and an image pixel (read back from the
# universal pixel's markup).
SINK="$BASE/tracking202/static/index.html?p202sink=$RUN&g=[[p202_goal]]&gid=[[p202_goal_id]]&v=[[p202_goal_value]]&p=[[payout]]&tx=[[transactionid]]&s=[[subid]]"
mysql_q "$DB" -e "INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES
  ($PPC, '$SINK', 4), ($PPC, 'https://img.example.test/px?g=[[p202_goal]]&v=[[p202_goal_value]]', 1);"

# The goal campaign's funnel.
goal() { # name definition-json [extra body fields]
  api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_G,\"definition\":$2${3:+,$3}}" > /dev/null
  field "d['data']['goal_id']"
}
G_SIGNUP=$(goal Signup '{"name":"Signup","trigger":{"event":"signup"},"value":{"type":"fixed","amount":"1.00"}}')
G_BUY=$(goal Purchase '{"name":"Purchase","trigger":{"event":"purchase"},"value":{"type":"from_property"}}')
G_UP=$(goal Upsell "{\"name\":\"Upsell\",\"trigger\":{\"event\":\"upsell\"},\"after\":[$G_BUY],\"value\":{\"type\":\"fixed\",\"amount\":4}}" '"notify_traffic_source":false')
G_VIEW=$(goal Viewed '{"name":"Viewed","trigger":{"event":"view"}}')
[ -n "$G_SIGNUP" ] && [ -n "$G_BUY" ] && [ -n "$G_UP" ] && [ -n "$G_VIEW" ] || { echo "creating goals failed" >&2; exit 2; }

# ─────────────────────────────────────────────────────────────────────
say "gpb: an event is evaluated by the click's goals"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_GPB&event=signup")" 200 "gpb with event=signup answers 200"
eq "$(field "[d['msg'], d['duplicate'], len(d['outcomes'])]")" '["Event recorded", false, 1]' "naming the one outcome it reached"
eq "$(rows $C_GPB)" "goal:signup:1.00000:1:live" "one ledger row: source goal, the event's name, the goal's value"
eq "$(value $C_GPB)" "1/1.00000" "the click is a lead worth the goal"
eq "$(Q "SELECT CONCAT(event_id, ':', revenue_trusted) FROM 202_goal_events WHERE subject_id=$C_GPB")" "@once:signup:1" \
   "the event is stored under a derived id (no event_id or transaction id was sent)"
eq "$(Q "SELECT dedupe_key FROM 202_conversion_logs WHERE click_id=$C_GPB")" "goal:$G_SIGNUP:1:1:@once:signup" "keyed by goal, version, n and event"
eq "$(field "d['notifications'][0]['status']")" sent "the traffic source is told"
eq "$(notified Signup)" 1 "once, by server-to-server postback"
grep "p202sink=$RUN&g=Signup&" "$SERVER_LOG" | tail -1 > "$OUT/sink"
has "$OUT/sink" "gid=$G_SIGNUP&v=1.00&p=1.00&tx=goal%3A$G_SIGNUP%3A1%3A1%3A@once%3Asignup&s=$C_GPB" \
   "with the goal, its value, the row's key as the transaction id, and the subid"

sleep 1 # a network's retry comes later: the server's clock has moved on
eq "$(hit "/tracking202/static/gpb.php?subid=$C_GPB&event=signup")" 200 "the same postback again, a second later"
eq "$(field "[d['msg'], d['duplicate']]")" '["Event already recorded", true]' "is a duplicate"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C_GPB")" 1 "writes nothing"
eq "$(notified Signup)" 1 "and tells the traffic source nothing again"

eq "$(hit "/tracking202/static/gpb.php?subid=$C_GPB&event=purchase&amount=12.5&txid=ORD-1&event_props=%7B%22plan%22%3A%22pro%22%7D")" 200 \
   "a purchase with an amount, a transaction id and properties"
eq "$(Q "SELECT CONCAT(event_id, '|', transaction_id, '|', revenue, '|', properties) FROM 202_goal_events WHERE subject_id=$C_GPB AND name='purchase'")" \
   '@tx:ORD-1|ORD-1|12.5|{"plan":"pro"}' "is stored under its transaction id, with its revenue and properties"
eq "$(value $C_GPB)" "1/13.50000" "the postback's amount pays the purchase goal: 1 + 12.50 accumulated"
eq "$(Q "SELECT transaction_id FROM 202_conversion_logs WHERE click_id=$C_GPB AND click_payout=12.5")" ORD-1 "the ledger row keeps the network's id"
grep "p202sink=$RUN&g=Purchase&" "$SERVER_LOG" | tail -1 > "$OUT/sink"
has "$OUT/sink" "v=12.50&p=12.50&tx=ORD-1&" "the traffic source hears the purchase with the network's own id"

say "gpx, upx, pb, px carry events too"
hit "/tracking202/static/gpb.php?subid=$C_GPX&event=purchase&amount=5&txid=ORD-2" > /dev/null
# Events are ordered by time, then arrival, then id; two in one second are
# ordered by id, so the upsell arrives a second after the purchase it needs.
sleep 1
code=$(curl -s -o "$OUT/gif" -w '%{http_code}' "$BASE/tracking202/static/gpx.php?subid=$C_GPX&event=upsell")
eq "$code" 200 "gpx with event=upsell still answers its image"
eq "$(rows $C_GPX)" "goal:purchase:5.00000:1:live goal:upsell:4.00000:1:live" "the upsell waits for the purchase and pays 4"
eq "$(notified Upsell)" 0 "a goal the campaign does not notify for is not sent"

eq "$(hit "/tracking202/static/upx.php?subid=$C_UPX&event=signup")" 200 "upx with event=signup"
has "$OUT/body" "https://img.example.test/px?g=Signup&amp;v=1.00" "answers the traffic source's image pixel with the goal filled"
eq "$(rows $C_UPX)" "goal:signup:1.00000:1:live" "and records the signup"
eq "$(hit "/tracking202/static/upx.php?subid=$C_UPX&event=view")" 200 "a tracked (unpaid) goal"
eq "$(rows $C_UPX)" "goal:signup:1.00000:1:live goal:view:0.00000:0:live" "writes an unpaid row for the breakdown"
eq "$(value $C_UPX)" "1/1.00000" "and leaves the click's value alone"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_outcomes WHERE subject_id=$C_UPX AND goal_id=$G_VIEW AND payable=0")" 1 "its outcome is not payable"

eq "$(hit "/tracking202/static/pb.php?acip=$ACIP_G&subid=$C_PB&event=signup&event_id=pb-1")" 200 "pb with event=signup and an event_id"
eq "$(Q "SELECT event_id FROM 202_goal_events WHERE subject_id=$C_PB")" pb-1 "is stored under the sender's id"
eq "$(hit "/tracking202/static/pb.php?acip=$ACIP_N&subid=$C_PB&event=signup&event_id=pb-2")" 404 "pb for another campaign's click is refused"
eq "$(events $C_PB)" 1 "and stores nothing"

code=$(curl -s -o /dev/null -w '%{http_code}' -b "tracking202subid=$C_PX" "$BASE/tracking202/static/px.php?acip=$ACIP_G&event=signup")
eq "$code" 200 "px with event=signup (the click from its cookie)"
eq "$(rows $C_PX)" "goal:signup:1.00000:1:live" "records the signup"

say "malformed events are refused by name, and record nothing"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=Sale%20Complete")" 422 "an event name with a space"
eq "$(field "list(d['field_errors'])")" '["event"]' "names event"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=sale&event_props=%7Bbad")" 422 "event_props that is not JSON"
eq "$(field "list(d['field_errors'])")" '["event_props"]' "names event_props"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=sale&event_props=%5B1%2C2%5D")" 422 "event_props that is a list"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=sale&amount=1e3")" 422 "an amount the float parser would accept"
eq "$(field "list(d['field_errors'])")" '["amount"]' "names amount"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=sale&event_id=%40install")" 422 "an event id in the server's reserved namespace"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=sale&status=reversed&txid=X")" 422 "an event that is also a reversal"
eq "$(events $C_BAD)$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C_BAD")" 00 "none of them stored an event or a conversion"
hit "/tracking202/static/gpb.php?subid=$C_BAD&event=signup&event_id=e-1" > /dev/null
eq "$(hit "/tracking202/static/gpb.php?subid=$C_BAD&event=purchase&event_id=e-1")" 409 "an event id reused for another event"

say "legacy: no event, or no goals, is today's conversion"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_PLAIN&amount=3&txid=P-1")" 202 "gpb without event= on a goal campaign (202: its pixels fired, as always)"
eq "$(rows $C_PLAIN)" "postback:-:3.00000:1:live" "is the plain postback conversion it always was"
eq "$(events $C_PLAIN)" 0 "and stores no event"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_LEG&event=sale&amount=5&txid=L-1")" 202 "gpb with event= on a campaign without goals"
eq "$(rows $C_LEG)" "postback:sale:5.00000:1:live" "records the plain conversion, with the event's name on the row"
eq "$(events $C_LEG)" 0 "and stores no event"
eq "$(hit "/tracking202/static/gpb.php?subid=$C_LEG2&event=Sale%20Complete")" 202 "a name that is not an event name, on a campaign without goals"
eq "$(rows $C_LEG2)" "postback:-:0.00000:1:live" "still records the conversion it always did (replace mode: the click's value; the name is not kept)"

# ─────────────────────────────────────────────────────────────────────
say "POST /events"
eq "$(api POST /events "{\"click_id\":$C_API,\"events\":[{\"event_id\":\"a1\",\"name\":\"signup\"}],\"colour\":1}")" 422 "an unknown field is refused"
eq "$(field "list(d['field_errors'])")" '["colour"]' "by name"
eq "$(api POST /events "{\"click_id\":\"1e3\",\"events\":[{\"event_id\":\"a1\",\"name\":\"signup\"}]}")" 422 "a click id the int cast would rewrite"
eq "$(api POST /events "{\"click_id\":$C_API,\"events\":[{\"event_id\":\"a1\",\"name\":\"signup\",\"received_at\":1,\"revenue_trusted\":true}]}")" 422 \
   "received_at and revenue_trusted are the server's"
eq "$(field "sorted(d['field_errors'])")" '["events[0].received_at", "events[0].revenue_trusted"]' "each named"
eq "$(api POST /events "{\"click_id\":$C_API,\"events\":[{\"name\":\"signup\"}]}")" 422 "an event without an id"
eq "$(api POST /events "{\"click_id\":$C_API,\"events\":[]}")" 422 "no events"
eq "$(api POST /events "{\"click_id\":999999999,\"events\":[{\"event_id\":\"a1\",\"name\":\"signup\"}]}")" 404 "an unknown click"
eq "$(events $C_API)" 0 "none of them stored anything"

BODY="{\"click_id\":$C_API,\"events\":[{\"event_id\":\"a1\",\"name\":\"signup\"},{\"event_id\":\"a2\",\"name\":\"purchase\",\"revenue\":20,\"transaction_id\":\"API-1\",\"properties\":{\"plan\":\"pro\"}}]}"
eq "$(api POST /events "$BODY" "$P202_API_KEY" "Idempotency-Key: we-$RUN")" 201 "two events are accepted: 201"
eq "$(field "[d['data']['accepted'], d['data']['goals_evaluated'], [o['goal_id'] for o in d['data']['outcomes']]]")" "[[\"a1\", \"a2\"], true, [$G_SIGNUP, $G_BUY]]" \
   "naming each event and each goal it reached"
eq "$(value $C_API)" "1/21.00000" "revenue from the API is trusted: 1 + 20"
eq "$(api POST /events "$BODY" "$P202_API_KEY" "Idempotency-Key: we-$RUN")" 201 "the same Idempotency-Key and body"
eq "$(field "d.get('idempotent_replay')")" true "replays the recorded answer"
eq "$(api POST /events "{\"click_id\":$C_API,\"events\":[{\"event_id\":\"a3\",\"name\":\"view\"}]}" "$P202_API_KEY" "Idempotency-Key: we-$RUN")" 422 \
   "the same key with another body is refused"
sleep 1
eq "$(api POST /events "$BODY")" 200 "the same events without a key, a second later (no occurred_at: the time was the server's): 200"
eq "$(field "[d['data']['accepted'], d['data']['duplicates']]")" '[[], ["a1", "a2"]]' "all duplicates, nothing written"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C_API")" 2 "the ledger still holds two rows"
eq "$(api POST /events "{\"click_id\":$C_API,\"events\":[{\"event_id\":\"a1\",\"name\":\"view\"}]}")" 409 "an event id reused with other content"

eq "$(api POST /events "{\"click_id\":$C_APIN,\"events\":[{\"event_id\":\"n1\",\"name\":\"signup\"}]}")" 201 "an event on a campaign without goals"
eq "$(field "[d['data']['goals_evaluated'], 'reevaluation' in d['data']['note']]")" '[false, true]' "is stored, with a note on how to apply goals to it"
eq "$(events $C_APIN)$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$C_APIN")" 10 "one event, no conversion"

say "a replay's replacement rows are never announced again"
before=$(notified Purchase)
api POST /events "{\"click_id\":$C_REPLAY,\"events\":[{\"event_id\":\"r2\",\"name\":\"purchase\",\"revenue\":10,\"occurred_at\":$((NOW - 60))}]}" > /dev/null
eq "$(notified Purchase)" "$((before + 1))" "the first purchase is announced"
eq "$(api POST /events "{\"click_id\":$C_REPLAY,\"events\":[{\"event_id\":\"r1\",\"name\":\"purchase\",\"revenue\":1,\"occurred_at\":$((NOW - 600))}]}")" 201 \
   "an earlier purchase arrives late"
eq "$(field "[d['data']['replayed'], [o['notify'] for o in d['data']['outcomes']]]")" '[true, ["suppressed"]]' \
   "the replay moves the outcome to it, and marks the replacement suppressed"
eq "$(notified Purchase)" "$((before + 1))" "so the network is not told a second time"
eq "$(rows $C_REPLAY)" "goal:purchase:10.00000:1:sup goal:purchase:1.00000:1:live" "the ledger supersedes the moved row"

say "events is its own scope area; POST /events is stageable"
mint() {
    local code
    code=$(api POST "/users/$OWNER/api-keys" "{\"scope\":\"$1\"}")
    [ "$code" = 201 ] || { echo "    minting a $1 key answered $code: $(cat "$OUT/body")" >&2; return; }
    field "d['data'].get('api_key') or d['data'].get('key')"
}
GOALS_KEY=$(mint goals:write)
EVENTS_KEY=$(mint events:write)
STAGE_KEY=$(mint stage)
eq "$(api POST /events "{\"click_id\":$C_STAGE,\"events\":[{\"event_id\":\"s0\",\"name\":\"signup\"}]}" "$GOALS_KEY")" 403 "a goals:write key cannot post events"
eq "$(api POST /events "{\"click_id\":$C_STAGE,\"events\":[{\"event_id\":\"s0\",\"name\":\"view\"}]}" "$EVENTS_KEY")" 201 "an events:write key can"
eq "$(api POST "/events?staged=1" "{\"click_id\":$C_STAGE,\"events\":[{\"event_id\":\"s1\",\"name\":\"signup\"}]}" "$STAGE_KEY")" 202 \
   "a stage key proposes an event"
CHANGE=$(field "d['data'].get('change_id') or d['data'].get('id')")
eq "$(events $C_STAGE)" 1 "which is not recorded"
eq "$(api POST "/staged-changes/$CHANGE/apply" '')" 200 "until it is applied"
eq "$(Q "SELECT GROUP_CONCAT(event_id ORDER BY event_row_id) FROM 202_goal_events WHERE subject_id=$C_STAGE")" "s0,s1" "and then it is"
eq "$(value $C_STAGE)" "1/1.00000" "and reaches its goal"
for k in "$GOALS_KEY" "$EVENTS_KEY" "$STAGE_KEY"; do [ -n "$k" ] && api DELETE "/users/$OWNER/api-keys/$k" > /dev/null; done

# ─────────────────────────────────────────────────────────────────────
say "p202.track(): the landing page's intake"
NOWS=$(date +%s)
mysql_q "$DB" -e "INSERT INTO 202_landing_pages SET user_id=$OWNER, landing_page_id_public=$LP_PUBLIC, aff_campaign_id=$CAMP_G,
  landing_page_nickname='web events lp', landing_page_url='http://lp.example.test/', landing_page_deleted=0, landing_page_time=$NOWS, landing_page_type=0;"
LPID=$(head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n')
curl -s -o /dev/null -A 'Mozilla/5.0 (X11; Linux x86_64) web-events' -H 'Sec-Fetch-Site: cross-site' \
  "$BASE/tracking202/static/record.php?lpip=$LP_PUBLIC&p202lpid=$LPID"
LP_CLICK=$(Q "SELECT c.click_id FROM 202_clicks c JOIN 202_landing_pages lp ON lp.landing_page_id=c.landing_page_id WHERE lp.landing_page_id_public=$LP_PUBLIC ORDER BY c.click_id DESC LIMIT 1")
[ -n "$LP_CLICK" ] && ok "record.php recorded the landing page click ($LP_CLICK)" || bad "record.php recorded no click"
track() { curl -s -o "$OUT/body" -w '%{http_code}' -X POST -H 'Content-Type: application/x-www-form-urlencoded' --data "$1" "$BASE/tracking202/static/event.php"; }
eq "$(track "lpip=$LP_PUBLIC&p202lpid=$LPID&event_id=js-1&name=purchase&revenue=30&props=%7B%22plan%22%3A%22pro%22%7D")" 202 "a tracked purchase"
eq "$(field "[d['accepted'], d['duplicate'], d['event_id']]")" '[true, false, "js-1"]' "is accepted"
eq "$(field "sorted(d)")" '["accepted", "duplicate", "event_id"]' "the answer never names the click"
eq "$(Q "SELECT CONCAT(subject_id, ':', revenue, ':', revenue_trusted) FROM 202_goal_events WHERE event_id='js-1'")" "$LP_CLICK:30:0" \
   "it is the visitor's click, with the page's revenue untrusted"
eq "$(Q "SELECT CONCAT(payable, ':', value_note) FROM 202_goal_outcomes WHERE subject_id=$LP_CLICK AND goal_id=$G_BUY")" "0:untrusted_value" \
   "so the purchase goal tracks it and does not pay it"
eq "$(track "lpip=$LP_PUBLIC&p202lpid=$LPID&event_id=js-2&name=signup")" 202 "a tracked signup"
eq "$(value "$LP_CLICK")" "1/1.00000" "pays its fixed value"
eq "$(track "lpip=$LP_PUBLIC&p202lpid=$LPID&event_id=js-2&name=signup")" 202 "sent again"
eq "$(field "d['duplicate']")" true "is a duplicate"
eq "$(track "lpip=$LP_PUBLIC&p202lpid=0123456789abcdef0123456789abcdef&event_id=js-3&name=signup")" 204 "a visitor id never seen on a click: 204"
eq "$(track "lpip=$LP_PUBLIC&p202lpid=$LPID&event_id=js-4&name=bad%20name")" 422 "a bad event name: 422"
eq "$(track "lpip=$LP_PUBLIC&p202lpid=$LPID&event_id=js-5&name=signup&click_id=$C_GPB")" 422 "a click id from the page is refused, never used"
eq "$(field "list(d['field_errors'])")" '["click_id"]' "by name"
eq "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/event.php")" 405 "GET is not allowed"
curl -s -D "$OUT/h" -o /dev/null -X OPTIONS -H 'Origin: http://lp.example.test' "$BASE/tracking202/static/event.php"
has "$OUT/h" "Access-Control-Allow-Origin: *" "a page on another site may post (no credentials involved)"

# ─────────────────────────────────────────────────────────────────────
say "the CLIs: p202 event send (Go) and event:send (PHP)"
CLI="$OUT/p202"
mkdir -p "$OUT/home/.p202"
printf '{"url": "%s", "api_key": "%s"}' "$BASE" "$P202_API_KEY" > "$OUT/home/.p202/config.json"
if (cd "$ROOT/go-cli" && go build -o "$CLI" .) 2> "$OUT/build.err"; then
  gocli() { HOME="$OUT/home" "$CLI" "$@"; }
  gocli event send --click-id "$C_CLI" --name purchase --id cli-1 --revenue 7.5 --transaction-id CLI-1 --props '{"plan":"pro"}' --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$?" 0 "p202 event send"
  eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1]))['data']; print(d['accepted'], [o['amount'] for o in d['outcomes']])" "$OUT/cli.json" 2>/dev/null)" \
     "['cli-1'] ['7.50000']" "records the event and the purchase goal it reaches"
  eq "$(Q "SELECT properties FROM 202_goal_events WHERE subject_id=$C_CLI")" '{"plan":"pro"}' "with its properties, typed"
  gocli event send --click-id "$C_CLI" --name purchase --id cli-1 --revenue 7.5 --transaction-id CLI-1 --props '{"plan":"pro"}' --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['duplicates'])" "$OUT/cli.json" 2>/dev/null)" "['cli-1']" "run again, it is a duplicate"
  gocli event send --click-id "$C_CLI" --name purchase --revenue 1e3 --id x --json > /dev/null 2> "$OUT/cli.err"
  eq "$?" 1 "a bad flag exits 1"
  eq "$(python3 -c "import json,sys; e=json.load(open(sys.argv[1]))['error']; print(e['category'], '--revenue' in e['message'])" "$OUT/cli.err" 2>/dev/null)" "validation True" \
     "with a validation envelope naming the flag"
  gocli event send --click-id "$C_CLI" --name view --id cli-1 --json > /dev/null 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; e=json.load(open(sys.argv[1]))['error']; print(e['http_status'], 'new --id' in e['hint'])" "$OUT/cli.err" 2>/dev/null)" "409 True" \
     "an event id reused for another event: 409, with the next step"
else
  bad "the Go CLI builds from this checkout: $(head -3 "$OUT/build.err")"
fi
PHPCLI=(php)
[ -n "${P202_CLI_PREPEND:-}" ] && PHPCLI+=(-d "auto_prepend_file=$P202_CLI_PREPEND")
phpcli() { (cd "$ROOT" && HOME="$OUT/home" "${PHPCLI[@]}" bin/p202 "$@"); }
if phpcli config:set-url "$BASE" > /dev/null 2> "$OUT/phpcli.err" && phpcli config:set-key "$P202_API_KEY" > /dev/null 2>> "$OUT/phpcli.err"; then
  phpcli event:send --click_id="$C_CLI" --name=signup --id=cli-2 --json > "$OUT/phpcli.json" 2>> "$OUT/phpcli.err"
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['accepted'])" "$OUT/phpcli.json" 2>/dev/null)" "['cli-2']" "event:send records an event"
  eq "$(value $C_CLI)" "1/8.50000" "and the click is worth both goals"
  phpcli event:send --click_id=1e3 --name=signup --id=cli-3 > "$OUT/phpcli.txt" 2>&1
  eq "$?" 1 "a click id the int cast would rewrite is refused"
  has "$OUT/phpcli.txt" "--click_id" "by name"
else
  bad "the PHP CLI could not start (set P202_CLI_PREPEND on a partial vendor/): $(head -3 "$OUT/phpcli.err")"
fi

# ─────────────────────────────────────────────────────────────────────
say "the goal editor on Setup › Campaigns"
JAR="$OUT/jar"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
  --data-urlencode "user_name=${P202_USER:-evalci}" --data-urlencode "user_pass=${P202_PASS:-}" -o "$OUT/pl.html"
PAGE="$BASE/tracking202/setup/aff_campaigns.php?edit_aff_campaign_id=$CAMP_G"
curl -sS -b "$JAR" -c "$JAR" "$PAGE" -o "$OUT/campaign.html"
if grep -q 'id="campaign-goals"' "$OUT/campaign.html"; then ok "the campaign's page has its Goals panel"; else bad "the campaign's page has its Goals panel (is P202_PASS set?)"; fi
# The goal form's own token field, read from inside that form (CLAUDE.md #21).
TOKEN=$(python3 -c "import re,sys; s=open(sys.argv[1]).read(); f=re.search(r'<form[^>]*id=\"goal-form\".*?</form>', s, re.S); m=f and re.search(r'name=\"token\" value=\"([^\"]+)\"', f.group(0)); print(m.group(1) if m else '')" "$OUT/campaign.html")
[ -n "$TOKEN" ] && ok "the goal form carries the session token" || bad "the goal form carries the session token"
for g in Signup Purchase Upsell Viewed; do has "$OUT/campaign.html" ">$g</span>" "it lists $g"; done
# post FIELDS… — the goal form, as the browser posts it; prints the status and the redirect.
post() { curl -sS -b "$JAR" -c "$JAR" -o "$OUT/post.html" -w '%{http_code} %{redirect_url}' "$PAGE" --data-urlencode "token=$TOKEN" "$@"; }
goals() { Q "SELECT COUNT(*) FROM 202_goals WHERE scope='campaign' AND scope_id=$CAMP_G"; }
before=$(goals)
code=$(post --data-urlencode goal_action=save --data-urlencode goal_id= --data-urlencode goal_name=Refund \
  --data-urlencode goal_event=refund --data-urlencode goal_value=fixed --data-urlencode goal_amount=2.5 --data-urlencode goal_count=2.5 \
  --data-urlencode goal_repeat=once --data-urlencode goal_notify=1)
eq "${code%% *}" 200 "a count of 2.5 is answered on the page, not redirected"
has "$OUT/post.html" "Reached on the Nth event: a whole number from 1 to 10000." "with its sentence"
eq "$(goals)" "$before" "and nothing is created"
curl -sS -b "$JAR" -c "$JAR" -o "$OUT/post.html" "$PAGE" --data-urlencode "token=wrong" \
  --data-urlencode goal_action=save --data-urlencode goal_name=Refund --data-urlencode goal_event=refund --data-urlencode goal_value=none
has "$OUT/post.html" "Invalid or expired form token" "a post without the session token is refused"
eq "$(goals)" "$before" "and creates nothing"
post --data-urlencode goal_action=save --data-urlencode goal_id= --data-urlencode goal_name=Signup \
  --data-urlencode goal_event=other --data-urlencode goal_value=none --data-urlencode goal_count=1 --data-urlencode goal_repeat=once > /dev/null
has "$OUT/post.html" "already exists" "a second goal named Signup is refused under its name, in the API's words"
code=$(post --data-urlencode goal_action=save --data-urlencode goal_id= --data-urlencode goal_name=Renewal \
  --data-urlencode goal_event=renewal --data-urlencode goal_value=property --data-urlencode goal_count=1 --data-urlencode goal_repeat=each \
  --data-urlencode goal_repeat_max=12 --data-urlencode goal_within_days=30 --data-urlencode "goal_after=$G_BUY" \
  --data-urlencode goal_where_prop=plan --data-urlencode goal_where_op=eq --data-urlencode goal_where_value=pro \
  --data-urlencode goal_payout=3 --data-urlencode goal_notify=0)
case "$code" in *goal_saved=1*) ok "a goal with every advanced setting is saved (redirected)";; *) bad "a goal with every advanced setting is saved (got '$code')";; esac
G_REN=$(Q "SELECT goal_id FROM 202_goals WHERE scope_id=$CAMP_G AND name='Renewal'")
eq "$(Q "SELECT definition FROM 202_goal_versions WHERE goal_id='$G_REN'")" \
   "{\"name\":\"Renewal\",\"trigger\":{\"event\":\"renewal\",\"where\":[{\"prop\":\"plan\",\"op\":\"eq\",\"value\":\"pro\"}]},\"threshold\":{\"count\":1},\"after\":[$G_BUY],\"within\":{\"days\":30,\"from\":\"click\"},\"repeat\":{\"mode\":\"each\",\"max\":12},\"value\":{\"type\":\"from_property\",\"prop\":\"\$revenue\"}}" \
   "stored as the API would store it"
eq "$(Q "SELECT CONCAT(payout, '/', notify_traffic_source) FROM 202_campaign_goals WHERE goal_id='$G_REN' AND campaign_id=$CAMP_G")" "3.00000/0" \
   "paid on the campaign at the override, without telling the traffic source"
code=$(post --data-urlencode goal_action=save --data-urlencode "goal_id=$G_REN" --data-urlencode goal_name=Renewal \
  --data-urlencode goal_event=renewal --data-urlencode goal_value=none --data-urlencode goal_count=1 --data-urlencode goal_repeat=once)
case "$code" in *goal_saved=1*) ok "editing it";; *) bad "editing it (got '$code')";; esac
eq "$(Q "SELECT current_version FROM 202_goals WHERE goal_id='$G_REN'")" 2 "adds a version"
eq "$(Q "SELECT COUNT(*) FROM 202_campaign_goals WHERE goal_id='$G_REN'")" 0 "and a goal worth nothing with no payout stops being paid"
post --data-urlencode goal_action=archive --data-urlencode "goal_id=$G_BUY" > /dev/null
has "$OUT/post.html" "cannot be archived" "archiving a goal another waits for is refused, with the reason"
code=$(post --data-urlencode goal_action=archive --data-urlencode "goal_id=$G_VIEW")
case "$code" in *goal_archived=1*) ok "archiving a goal";; *) bad "archiving a goal (got '$code')";; esac
eq "$(Q "SELECT archived_at IS NOT NULL FROM 202_goals WHERE goal_id=$G_VIEW")" 1 "archives it"
OTHER_PAGE="$BASE/tracking202/setup/aff_campaigns.php?edit_aff_campaign_id=$CAMP_R"
curl -sS -b "$JAR" -c "$JAR" -o "$OUT/post.html" "$OTHER_PAGE" --data-urlencode "token=$TOKEN" \
  --data-urlencode goal_action=archive --data-urlencode "goal_id=$G_UP"
has "$OUT/post.html" "not one of this campaign" "another campaign's page cannot archive this campaign's goal"
eq "$(Q "SELECT archived_at IS NULL FROM 202_goals WHERE goal_id=$G_UP")" 1 "which stays live"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
