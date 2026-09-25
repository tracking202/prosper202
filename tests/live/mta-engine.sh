#!/bin/bash
# Live pass for the multi-touch attribution engine (PR 9), over HTTP against a
# running instance, with the database read back after every step:
#
#   - three clicks from one browser (a cookie jar) across two campaigns and
#     one click from another browser; a postback (gpb.php) converts the first
#     browser's last click; the worker runs the way cron runs it;
#   - the journey is exactly the three same-browser clicks, and the
#     stranger's click earns nothing;
#   - the credits under every model (created over the API) are the expected
#     shares and each conversion's credits sum to its value; the report
#     grouped by campaign differs between first_touch and last_touch (the
#     property the old engine could not produce), with each campaign's own
#     click cost;
#   - counted state: on a replace campaign $5 then $10 reports $10, and
#     deleting the $10 brings the $5 back;
#   - isolation: with an engine table dropped, a conversion still records
#     (200 and its outbox row); the worker stops without charging the row,
#     and once the table is back the credits appear;
#   - an identity merge (a signed customer id arriving with a later
#     conversion) re-attributes the conversion it joins;
#   - the minutely cron (202-cronjobs/index.php) drains the outbox too;
#   - bad input is refused by name, and the default model cannot be deleted.
#
# Truncates the identity and attribution tables and seeds its own campaigns
# and trackers (ids 950001-950301), so it needs a scratch database. Run from
# the repository root of the instance being served (the worker runs here).

BASE=${P202_BASE:-http://127.0.0.1:8130}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: the models and reports are read over the API." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2
[ -f 202-cronjobs/attribution-worker.php ] || { echo "run from the repository root" >&2; exit 2; }

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

api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
js()   { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
worker() { "$PHP" 202-cronjobs/attribution-worker.php > "$OUT/worker.out" 2>&1; echo $?; }

USER_ID=$(Q "SELECT user_id FROM 202_users ORDER BY user_id LIMIT 1")
[ -n "$USER_ID" ] || { echo "no user in $DB" >&2; exit 2; }
NOW=$(date +%s)
CAMP1=950001; CAMP2=950002; CAMP3=950003
T1=950101; T2=950102; T3=950103
CAMPS="$CAMP1,$CAMP2,$CAMP3"

cleanup() {
  mysql_q "$DB" <<SQL
-- The pass owns the MTA state (credits and journeys are truncated below),
-- so it drains the whole outbox too: on a shared instance other passes'
-- conversions sit in it, and the worker runs here would credit them into
-- the totals this pass checks.
TRUNCATE 202_attribution_pending;
DELETE FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS);
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_clicks WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_trackers WHERE tracker_id_public IN ($T1, $T2, $T3);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_attribution_models WHERE user_id = $USER_ID AND is_default IS NULL;
DELETE FROM 202_last_ips WHERE ip_id IN (SELECT ip_id FROM 202_ips WHERE ip_address LIKE '198.51.100.%');
TRUNCATE 202_attribution_credits; TRUNCATE 202_attribution_journeys; TRUNCATE 202_attribution_journey_meta;
TRUNCATE 202_identity_visitors; TRUNCATE 202_identity_signals; TRUNCATE 202_identity_observations;
TRUNCATE 202_identity_merges; TRUNCATE 202_clicks_visitor;
SQL
}
# The isolation step drops a table; whatever happens, put it back.
CREDITS_DDL=$(mysql_q -N "$DB" -e "SHOW CREATE TABLE 202_attribution_credits" | cut -f2-)
restore_credits() { Q "SHOW TABLES LIKE '202_attribution_credits'" | grep -q . || mysql_q "$DB" -e "$(printf '%b' "$CREDITS_DDL")"; }
trap 'restore_credits; cleanup' EXIT
cleanup

mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP1, aff_campaign_id_public=$CAMP1, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='mta-pass-one', aff_campaign_url='http://offer.example/one', aff_campaign_payout=1, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP2, aff_campaign_id_public=$CAMP2, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='mta-pass-two', aff_campaign_url='http://offer.example/two', aff_campaign_payout=1, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP3, aff_campaign_id_public=$CAMP3, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='mta-pass-three', aff_campaign_url='http://offer.example/three', aff_campaign_payout=1, aff_campaign_time=$NOW,
  identity_signals=1, payout_mode='accumulate';
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T1, aff_campaign_id=$CAMP1, click_cpc=0.20, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T2, aff_campaign_id=$CAMP2, click_cpc=0.30, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T3, aff_campaign_id=$CAMP3, click_cpc=0.10, click_cloaking=0, tracker_time=$NOW;
SQL

UA='Mozilla/5.0 (X11; Linux x86_64) mta-pass'
# Each browser has its own address (the tracker reads X-Forwarded-For): one
# person's repeat clicks share an IP, which Prosper202 marks "filtered" and
# the journey still counts; a stranger has another.
click() { # $1 jar, $2 query, $3 client IP; prints the click id the request recorded
    local before after
    before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    curl -s -o /dev/null -A "$UA" -H "X-Forwarded-For: $3" -b "$1" -c "$1" "$BASE/tracking202/redirect/dl.php?$2"
    after=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    [ "$after" != "$before" ] && echo "$after" || echo ""
}
gpb() { curl -s -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/gpb.php?$1"; }
convid() { Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$1 AND transaction_id='$2'"; }
credits() { # $1 conv, $2 model: "click:credit ..." in position order
    Q "SELECT GROUP_CONCAT(CONCAT(click_id, ':', credit) ORDER BY position SEPARATOR ' ') FROM 202_attribution_credits WHERE conv_id=$1 AND model_id=$2"
}
convsum() { Q "SELECT COALESCE(SUM(revenue), 0) FROM 202_attribution_credits WHERE conv_id=$1 AND model_id=$2"; }
ok_code() { if [ "$1" = 200 ] || [ "$1" = 202 ]; then ok "$2 ($1)"; else bad "$2 (HTTP $1)"; fi; }

say "models are created over the API, and the list is the engine's"
DEFAULT=$(api "$BASE/api/v3/attribution/models" | js '[m["model_id"] for m in d["data"] if m["is_default"]][0]')
eq "$(api "$BASE/api/v3/attribution/models" | js 'len([m for m in d["data"] if m["is_default"]])')" 1 "the account has exactly one default model"
eq "$(api "$BASE/api/v3/attribution/models/$DEFAULT" | js 'd["data"]["model_type"]')" last_touch "the default is last touch"
mk() { api -X POST -d "$1" "$BASE/api/v3/attribution/models" | js 'd["data"]["model_id"]'; }
FIRST=$(mk '{"model_name":"First (pass)","model_type":"first_touch"}')
LINEAR=$(mk '{"model_name":"Linear (pass)","model_type":"linear"}')
DECAY=$(mk '{"model_name":"Decay (pass)","model_type":"time_decay","weighting_config":{"half_life_hours":24}}')
UPOS=$(mk '{"model_name":"U-shaped (pass)","model_type":"position_based","weighting_config":{"first_weight":0.4,"last_weight":0.4}}')
for m in FIRST LINEAR DECAY UPOS; do [[ "${!m}" =~ ^[0-9]+$ ]] && ok "created $m model ${!m}" || bad "creating $m failed: '${!m}'"; done
code=$(api -o "$OUT/bad.json" -w '%{http_code}' -X POST -d '{"model_name":"Algo","model_type":"algorithmic"}' "$BASE/api/v3/attribution/models")
eq "$code" 422 "a model the engine cannot compute is refused"
grep -q 'last_touch, first_touch, linear, time_decay, position_based' "$OUT/bad.json" && ok "and the refusal lists the valid types" || bad "no type list: $(cat "$OUT/bad.json")"
code=$(api -o "$OUT/bad2.json" -w '%{http_code}' -X POST -d '{"model_name":"Str","model_type":"linear","lookback_days":"30"}' "$BASE/api/v3/attribution/models")
eq "$code" 422 "a lookback sent as a string is refused, not cast"

say "one browser, three clicks across two campaigns; a stranger on the same campaign"
# The stranger clicks campaign one between the first browser's clicks: the
# old engine's "journey" (the account's recent clicks on the campaign) would
# have swept it in.
A1=$(click "$OUT/a" "t202id=$T1" 198.51.100.10); sleep 1
S1=$(click "$OUT/s" "t202id=$T1" 198.51.100.20); sleep 1
A2=$(click "$OUT/a" "t202id=$T1" 198.51.100.10); sleep 1
A3=$(click "$OUT/a" "t202id=$T2" 198.51.100.10); sleep 1
for c in A1 A2 A3 S1; do [ -n "${!c}" ] && ok "click $c = ${!c}" || bad "click $c not recorded"; done
eq "$(Q "SELECT COUNT(DISTINCT visitor_key) FROM 202_clicks_visitor WHERE click_id IN ($A1,$A2,$A3)")" 1 "the three share a visitor key"
eq "$(Q "SELECT COUNT(DISTINCT visitor_key) FROM 202_clicks_visitor WHERE click_id IN ($A1,$S1)")" 2 "the stranger has another"
eq "$(Q "SELECT GROUP_CONCAT(click_filtered ORDER BY click_id) FROM 202_clicks WHERE click_id IN ($A1,$A2,$A3)")" "0,1,1" "the tracker marks the repeat clicks from one IP filtered"

ok_code "$(gpb "subid=$A3&amount=12&txid=MTA-A")" "gpb converts the last click"
CONV_A=$(convid "$A3" MTA-A)
eq "$(Q "SELECT reason FROM 202_attribution_pending WHERE conv_id=$CONV_A")" recorded "the conversion is in the outbox, nothing more"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_journeys WHERE conv_id=$CONV_A")" 0 "the pixel request computed no journey"

say "the worker, the way cron runs it"
eq "$(worker)" 0 "attribution-worker.php exits 0"
grep -q 'credited=1' "$OUT/worker.out" && ok "and reports what it did: $(tr -d '\n' < "$OUT/worker.out")" || bad "worker output: $(cat "$OUT/worker.out")"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id=$CONV_A")" 0 "the outbox row is consumed"

say "the journey is exactly the three same-browser clicks, filtered repeats included"
api "$BASE/api/v3/attribution/conversions/$CONV_A/journey" > "$OUT/journey.json"
eq "$(js '",".join(str(t["click_id"]) for t in d["data"]["touches"])' < "$OUT/journey.json")" "$A1,$A2,$A3" "journey = A1, A2, A3 (oldest first)"
eq "$(js 'd["data"]["touches"][0]["signals"]' < "$OUT/journey.json")" "['vid']" "each touch shows the signal that linked it"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_credits WHERE click_id=$S1")" 0 "the stranger (same campaign, another browser)'s click has no credit under any model"

say "credits under every model"
eq "$(credits "$CONV_A" "$DEFAULT")" "$A3:1.00000000" "last touch: the converting click"
eq "$(credits "$CONV_A" "$FIRST")" "$A1:1.00000000" "first touch: the first click"
eq "$(credits "$CONV_A" "$LINEAR")" "$A1:0.33333333 $A2:0.33333333 $A3:0.33333334" "linear: thirds, remainder on the last"
eq "$(credits "$CONV_A" "$UPOS")" "$A1:0.40000000 $A2:0.20000000 $A3:0.40000000" "position based: 0.4 / 0.2 / 0.4"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_credits WHERE conv_id=$CONV_A AND model_id=$DECAY")" 3 "time decay: every touch weighted"
for m in DEFAULT FIRST LINEAR DECAY UPOS; do
  eq "$(convsum "$CONV_A" "${!m}")" 12.00000 "$m: the credits sum to the conversion's \$12"
  eq "$(Q "SELECT SUM(credit) FROM 202_attribution_credits WHERE conv_id=$CONV_A AND model_id=${!m}")" 1.00000000 "$m: and the credit to exactly one"
done

say "the report grouped by campaign differs between first and last touch"
bd() { api "$BASE/api/v3/attribution/reports/breakdown?group_by=campaign&model_id=$1" > "$OUT/bd.json"; }
rev() { js "next((r['attributed_revenue'] for r in d['data'] if r['key']=='$1'), 'none')" < "$OUT/bd.json"; }
bd "$DEFAULT"; LT1=$(rev $CAMP1); LT2=$(rev $CAMP2); COST1=$(js "next(r['cost'] for r in d['data'] if r['key']=='$CAMP1')" < "$OUT/bd.json")
CLICKS1=$(js "next(r['clicks'] for r in d['data'] if r['key']=='$CAMP1')" < "$OUT/bd.json")
bd "$FIRST"; FT1=$(rev $CAMP1); FT2=$(rev $CAMP2); FTOT=$(js 'd["totals"]["attributed_revenue"]' < "$OUT/bd.json")
eq "$LT1/$LT2" "0.00000/12.00000" "last touch: campaign two gets the \$12"
eq "$FT1/$FT2" "12.00000/0.00000" "first touch: campaign one does"
[ "$LT1" != "$FT1" ] && ok "the two models tell campaign one different stories" || bad "first and last touch agree"
eq "$FTOT" 12.00000 "the totals are the conversion value under either model"
eq "$CLICKS1" 3 "campaign one's clicks are its own three, the stranger's included"
eq "$COST1" 0.60000 "and its cost is theirs, not the converting click's"
bd "$LINEAR"; eq "$(rev $CAMP1)/$(rev $CAMP2)" "8.00000/4.00000" "linear: two thirds and one third"

say "counted state: a replace campaign's \$5 then \$10 is \$10, and \$5 again when the \$10 goes"
C1=$(click "$OUT/c" "t202id=$T1" 198.51.100.30)
gpb "subid=$C1&amount=5&txid=MTA-C5" >/dev/null
gpb "subid=$C1&amount=10&txid=MTA-C10" >/dev/null
CONV_5=$(convid "$C1" MTA-C5); CONV_10=$(convid "$C1" MTA-C10)
worker >/dev/null
csum() { Q "SELECT COALESCE(SUM(revenue),0) FROM 202_attribution_credits WHERE conv_id IN ($CONV_5,$CONV_10) AND model_id=$DEFAULT"; }
eq "$(csum)" 10.00000 "MTA reports \$10, not \$15"
eq "$(Q "SELECT click_payout FROM 202_clicks WHERE click_id=$C1")" 10.00000 "the same \$10 the click shows"
eq "$(api -o /dev/null -w '%{http_code}' -X DELETE "$BASE/api/v3/conversions/$CONV_10")" 204 "the \$10 conversion is deleted"
eq "$(Q "SELECT reason FROM 202_attribution_pending WHERE conv_id=$CONV_5")" counted_state "the \$5 is queued because what counts changed"
worker >/dev/null
eq "$(csum)" 5.00000 "MTA reports \$5 again"

say "isolation: an engine table missing never costs a conversion"
mysql_q "$DB" -e "DROP TABLE 202_attribution_credits"
I1=$(click "$OUT/i" "t202id=$T2" 198.51.100.40)
ok_code "$(gpb "subid=$I1&amount=7&txid=MTA-I")" "the postback still answers success"
CONV_I=$(convid "$I1" MTA-I)
[ -n "$CONV_I" ] && ok "the conversion is recorded ($CONV_I)" || bad "no conversion row"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id=$CONV_I")" 1 "with its outbox row"
eq "$(worker)" 1 "the worker stops with an error"
grep -q '202_attribution_credits' "$OUT/worker.out" && ok "naming the missing table" || bad "worker output: $(cat "$OUT/worker.out")"
eq "$(Q "SELECT CONCAT(attempts, '/', retry_at) FROM 202_attribution_pending WHERE conv_id=$CONV_I")" 0/0 "the row is not charged for the engine's failure"
restore_credits
eq "$(worker)" 0 "with the table back the worker runs"
eq "$(convsum "$CONV_I" "$DEFAULT")" 7.00000 "and the credits appear"

say "a merge re-attributes the conversion it joins"
LINK=$(api "$BASE/api/v3/users/$USER_ID/identity-key" | js 'd["data"]["linking_key"]')
sign() { printf %s "$1" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$LINK" | awk '{print $NF}'; }
CUST=mta-pass-customer-1
SIG=$(sign "custom:$CUST")
D1=$(click "$OUT/d" "t202id=$T1&cust=$CUST&cust_sig=$SIG" 198.51.100.50); sleep 1   # the laptop, signed in
E1=$(click "$OUT/e" "t202id=$T3" 198.51.100.60)                                   # the phone, anonymous
gpb "subid=$E1&amount=4&txid=MTA-E1" >/dev/null
CONV_E=$(convid "$E1" MTA-E1)
worker >/dev/null
eq "$(Q "SELECT GROUP_CONCAT(click_id ORDER BY position) FROM 202_attribution_journeys WHERE conv_id=$CONV_E")" "$E1" "before the merge the phone's journey is one touch"
gpb "subid=$E1&amount=1&txid=MTA-E2&cust=$CUST&cust_sig=$SIG" >/dev/null
eq "$(Q "SELECT COUNT(*) FROM 202_identity_merges WHERE requeued_at IS NULL")" 1 "the signed customer id merged the two, and left the fan-out to the worker"
worker >/dev/null
grep -q 'merges re-queued 1' "$OUT/worker.out" && ok "the worker re-queued the merge's conversions" || bad "worker output: $(cat "$OUT/worker.out")"
eq "$(Q "SELECT GROUP_CONCAT(click_id ORDER BY position) FROM 202_attribution_journeys WHERE conv_id=$CONV_E")" "$D1,$E1" "the phone's conversion now starts at the laptop's click"
eq "$(credits "$CONV_E" "$FIRST")" "$D1:1.00000000" "first touch moves to the laptop"
eq "$(convsum "$CONV_E" "$LINEAR")" 4.00000 "and the credits still sum to \$4"

say "the minutely cron drains the outbox too"
X1=$(click "$OUT/x" "t202id=$T1" 198.51.100.70)
gpb "subid=$X1&amount=2&txid=MTA-X" >/dev/null
CONV_X=$(convid "$X1" MTA-X)
mysql_q "$DB" -e "DELETE FROM 202_cronjobs WHERE cronjob_type='second'"
curl -s -o "$OUT/cron.html" "$BASE/202-cronjobs/index.php"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id=$CONV_X")" 0 "202-cronjobs/index.php ran the worker"
eq "$(convsum "$CONV_X" "$DEFAULT")" 2.00000 "and the conversion is credited"

say "model changes recompute; the default is protected"
code=$(api -o "$OUT/upd.json" -w '%{http_code}' -X PUT -d '{"lookback_days":1}' "$BASE/api/v3/attribution/models/$LINEAR")
eq "$code" 200 "a narrower lookback is accepted"
eq "$(js 'd["data"]["recompute_pending"]' < "$OUT/upd.json")" True "and marked for recompute"
worker >/dev/null
eq "$(credits "$CONV_A" "$LINEAR")" "$A1:0.33333333 $A2:0.33333333 $A3:0.33333334" "a one-day window still holds all three recent clicks"
eq "$(api "$BASE/api/v3/attribution/models/$LINEAR" | js 'd["data"]["recompute_pending"]')" False "the recompute finished"
eq "$(api -o /dev/null -w '%{http_code}' -X DELETE "$BASE/api/v3/attribution/models/$DEFAULT")" 409 "the default model cannot be deleted"
eq "$(api -o /dev/null -w '%{http_code}' -X PUT -d '{"status":"inactive"}' "$BASE/api/v3/attribution/models/$FIRST")" 200 "a model can be deactivated"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_credits WHERE model_id=$FIRST")" 0 "and its credits go with it"
eq "$(api -o /dev/null -w '%{http_code}' "$BASE/api/v3/attribution/reports/breakdown?model_id=$FIRST")" 409 "a report on it says why it has none"
eq "$(api -o /dev/null -w '%{http_code}' "$BASE/api/v3/attribution/reports/breakdown?group_by=campaign&typo=1")" 422 "an unknown report parameter is refused"

say "journey metrics"
api "$BASE/api/v3/attribution/reports/journeys" > "$OUT/jm.json"
eq "$(js 'd["data"]["conversions"]' < "$OUT/jm.json")" "$(Q "SELECT COUNT(*) FROM 202_attribution_journey_meta WHERE user_id=$USER_ID")" "every attributed conversion is counted"
[ "$(js 'd["data"]["one_touch_by_browser"][0]["browser"]' < "$OUT/jm.json")" != "" ] && ok "with the one-touch share by browser" || bad "no browser breakdown"

if [ -n "${P202_SERVER_LOG:-}" ] && [ -f "$P202_SERVER_LOG" ]; then
  if grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' "$P202_SERVER_LOG" | grep -v 'mta-pass-ignore' > "$OUT/warn"; then
    bad "PHP warnings in the server log: $(head -3 "$OUT/warn")"
  else
    ok "no PHP warnings, notices or fatals in the server log"
  fi
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
