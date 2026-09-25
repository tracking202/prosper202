#!/bin/bash
# Live pass for identity capture (PR 2): first-party signals link clicks into
# one visitor, driven over HTTP against a running instance with the database
# read back after every step:
#
#   - the direct-link redirect (dl.php) sets an HttpOnly, SameSite=Lax
#     p202vid cookie, refreshes the same value on a return visit, and replaces
#     one it did not mint; two clicks in one browser share a visitor key;
#   - the landing page's p202lpid joins a second browser, merging the two
#     visitors and recording the merge; the identity parameters never ride
#     the redirect to the offer;
#   - consent: p202_consent=0, or a campaign with identity capture off, sets
#     no cookie and links nothing, while the click is still recorded;
#   - the rotator (rtr.php), which writes its click rows inline, links its
#     clicks the same way and honours a capture-off campaign;
#   - the landing-page beacon (record.php) from another site links by
#     p202lpid and mints no cookie;
#   - a conversion (pb.php) carrying a customer id signed with the account's
#     linking key joins two devices; a forged signature links nothing;
#   - the REST API hands out the linking key and rotates it, after which an
#     old signature links nothing; it links a customer_ref without one;
#   - a signal that joins more than twenty visitors is quarantined and links
#     nothing after that.
#
# Truncates the identity tables and seeds its own campaigns, trackers and
# landing page (ids 940001-940401), so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: the API step needs a key for this instance." >&2
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
ne()   { if [ "$1" != "$2" ]; then ok "$3"; else bad "$3 (both '$1')"; fi; }

USER_ID=$(Q "SELECT user_id FROM 202_users ORDER BY user_id LIMIT 1")
[ -n "$USER_ID" ] || { echo "no user in $DB" >&2; exit 2; }
NOW=$(date +%s)
CAMP_ON=940001; CAMP_OFF=940002
ACIP_ON=940100; ACIP_OFF=940101
T_ON=940200; T_OFF=940201; R_ON=940202; R_OFF=940203
ROT_ON=940400; ROT_OFF=940401
LP_PUBLIC=940300
CAMPS="$CAMP_ON,$CAMP_OFF"

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS));
DELETE FROM 202_conversion_logs WHERE campaign_id IN ($CAMPS);
DELETE FROM 202_clicks_spy WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_clicks WHERE aff_campaign_id IN ($CAMPS);
DELETE FROM 202_trackers WHERE tracker_id_public IN ($T_ON, $T_OFF, $R_ON, $R_OFF);
DELETE FROM 202_clicks_rotator WHERE rotator_id IN ($ROT_ON, $ROT_OFF);
DELETE FROM 202_rotators WHERE id IN ($ROT_ON, $ROT_OFF);
DELETE FROM 202_landing_pages WHERE landing_page_id_public = $LP_PUBLIC;
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN ($CAMPS);
TRUNCATE 202_identity_visitors; TRUNCATE 202_identity_signals; TRUNCATE 202_identity_observations;
TRUNCATE 202_identity_merges; TRUNCATE 202_clicks_visitor;
SQL
}
cleanup
trap cleanup EXIT

mysql_q "$DB" <<SQL
SET SESSION sql_mode='';
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP_ON, aff_campaign_id_public=$ACIP_ON, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='identity-pass-on', aff_campaign_url='http://offer.example/on', aff_campaign_payout=5, aff_campaign_time=$NOW, identity_signals=1;
INSERT INTO 202_aff_campaigns SET aff_campaign_id=$CAMP_OFF, aff_campaign_id_public=$ACIP_OFF, user_id=$USER_ID, aff_network_id=1,
  aff_campaign_name='identity-pass-off', aff_campaign_url='http://offer.example/off', aff_campaign_payout=5, aff_campaign_time=$NOW, identity_signals=0;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T_ON, aff_campaign_id=$CAMP_ON, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$T_OFF, aff_campaign_id=$CAMP_OFF, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_rotators SET id=$ROT_ON, public_id=$ROT_ON, user_id=$USER_ID, name='identity-pass-rot-on', default_campaign=$CAMP_ON;
INSERT INTO 202_rotators SET id=$ROT_OFF, public_id=$ROT_OFF, user_id=$USER_ID, name='identity-pass-rot-off', default_campaign=$CAMP_OFF;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$R_ON, aff_campaign_id=0, rotator_id=$ROT_ON, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_trackers SET user_id=$USER_ID, tracker_id_public=$R_OFF, aff_campaign_id=0, rotator_id=$ROT_OFF, click_cloaking=0, tracker_time=$NOW;
INSERT INTO 202_landing_pages SET user_id=$USER_ID, landing_page_id_public=$LP_PUBLIC, aff_campaign_id=$CAMP_ON,
  landing_page_nickname='identity-pass-lp', landing_page_url='http://lp.example/', landing_page_time=$NOW, landing_page_type=0;
SQL

# One browser = one cookie jar. Returns the click id the request recorded.
UA='Mozilla/5.0 (X11; Linux x86_64) identity-pass'
click() { # $1 jar, $2 query, $3 header file
    local before after
    before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    curl -s -o /dev/null -D "$3" -A "$UA" -b "$1" -c "$1" "$BASE/tracking202/redirect/dl.php?$2"
    after=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    [ "$after" != "$before" ] && echo "$after" || echo ""
}
vkey()   { Q "SELECT COALESCE(v.alias_of, cv.visitor_key) FROM 202_clicks_visitor cv JOIN 202_identity_visitors v ON v.visitor_key = cv.visitor_key WHERE cv.click_id = $1"; }
jarvid() { awk '$6 == "p202vid" {print $7}' "$1" | tail -1; }
LPID_A=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
LPID_Q=cccccccccccccccccccccccccccccccc

say "the redirect sets and reuses the visitor cookie"
C1=$(click "$OUT/b1" "t202id=$T_ON" "$OUT/h1")
[ -n "$C1" ] && ok "click recorded ($C1)" || bad "no click recorded"
SETC=$(grep -i '^set-cookie: p202vid=' "$OUT/h1" | tr -d '\r')
[[ "$SETC" =~ p202vid=[0-9a-f]{32} ]] && ok "p202vid minted: 32 hex" || bad "no p202vid cookie: $SETC"
[[ "$SETC" == *"HttpOnly"* ]] && ok "HttpOnly" || bad "not HttpOnly: $SETC"
[[ "$SETC" == *"SameSite=Lax"* ]] && ok "SameSite=Lax" || bad "not SameSite=Lax: $SETC"
V1=$(jarvid "$OUT/b1")
K1=$(vkey "$C1")
[ -n "$K1" ] && ok "click linked to visitor $K1" || bad "click $C1 not linked"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$C1 AND signal_type='vid'")" 1 "the cookie is recorded as the click's evidence"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_signals WHERE signal_hash LIKE '%$V1%'")" 0 "the raw cookie value is not stored"

C2=$(click "$OUT/b1" "t202id=$T_ON" "$OUT/h2")
eq "$(jarvid "$OUT/b1")" "$V1" "a return visit keeps the same cookie value"
grep -qi '^set-cookie: p202vid=' "$OUT/h2" && ok "and refreshes its lifetime" || bad "cookie not refreshed"
eq "$(vkey "$C2")" "$K1" "two clicks in one browser: one visitor"

say "a landing-page id joins a second browser"
C3=$(click "$OUT/b2" "t202id=$T_ON&p202lpid=$LPID_A" "$OUT/h3")
K3=$(vkey "$C3")
ne "$K3" "$K1" "a second browser starts as a different visitor"
LOC=$(grep -i '^location:' "$OUT/h3" | tr -d '\r')
[[ "$LOC" == *"offer.example/on"* ]] && ok "redirected to the offer" || bad "unexpected redirect: $LOC"
[[ "$LOC" != *p202lpid* ]] && ok "the landing-page id does not ride the redirect" || bad "p202lpid leaked: $LOC"
C4=$(click "$OUT/b1" "t202id=$T_ON&p202lpid=$LPID_A&p202_consent=1&cust_sig=00" "$OUT/h4")
LOC=$(grep -i '^location:' "$OUT/h4" | tr -d '\r')
[[ "$LOC" != *cust_sig* && "$LOC" != *p202_consent* ]] && ok "nor do cust_sig or p202_consent" || bad "identity params leaked: $LOC"
KM=$(vkey "$C4")
eq "$KM" "$(( K1 > K3 ? K1 : K3 ))" "browser one's lpid click merges both visitors into the higher key"
eq "$(vkey "$C1")" "$KM" "browser one's first click follows the merge"
eq "$(vkey "$C3")" "$KM" "browser two's click follows the merge"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_merges WHERE click_id=$C4 AND requeued_at IS NULL")" 1 "the merge is recorded for the attribution worker"

say "a cookie the tracker did not mint is replaced"
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tp202vid\tforged-value\n' > "$OUT/b3"
C5=$(click "$OUT/b3" "t202id=$T_ON" "$OUT/h5")
V5=$(jarvid "$OUT/b3")
[[ "$V5" =~ ^[0-9a-f]{32}$ ]] && ok "forged cookie replaced by a minted one" || bad "cookie is now '$V5'"
[ -n "$(vkey "$C5")" ] && ok "and the click is linked by the new one" || bad "click $C5 not linked"

say "the rotator links its clicks the same way"
rclick() { # $1 jar, $2 query, $3 header file
    local before after
    before=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    curl -s -o /dev/null -D "$3" -A "$UA" -b "$1" -c "$1" "$BASE/tracking202/redirect/rtr.php?$2"
    after=$(Q "SELECT COALESCE(MAX(click_id),0) FROM 202_clicks")
    [ "$after" != "$before" ] && echo "$after" || echo ""
}
CR=$(rclick "$OUT/b1" "t202id=$R_ON" "$OUT/hr1")
[ -n "$CR" ] && ok "rotator click recorded ($CR)" || bad "no rotator click recorded"
LOC=$(grep -i '^location:' "$OUT/hr1" | tr -d '\r')
[[ "$LOC" == *"offer.example/on"* ]] && ok "rotator redirected to its default campaign" || bad "unexpected rotator redirect: $LOC"
eq "$(jarvid "$OUT/b1")" "$V1" "the rotator reuses the browser's cookie"
eq "$(vkey "$CR")" "$KM" "and its click joins that browser's visitor"
CRO=$(rclick "$OUT/b1" "t202id=$R_OFF" "$OUT/hr2")
[ -n "$CRO" ] && ok "rotator click on a capture-off campaign recorded ($CRO)" || bad "no rotator click recorded"
grep -qi '^set-cookie: p202vid=' "$OUT/hr2" && bad "capture-off rotator set p202vid" || ok "a capture-off rotator campaign sets no cookie"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks_visitor WHERE click_id=$CRO")" 0 "and links nothing"

say "consent withheld: nothing captured, the click still recorded"
C6=$(click "$OUT/b4" "t202id=$T_ON&p202_consent=0&p202lpid=$LPID_A" "$OUT/h6")
[ -n "$C6" ] && ok "click recorded ($C6)" || bad "no click recorded"
grep -qi '^set-cookie: p202vid=' "$OUT/h6" && bad "p202_consent=0 still set p202vid" || ok "p202_consent=0 sets no cookie"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks_visitor WHERE click_id=$C6")" 0 "and links nothing"
C7=$(click "$OUT/b1" "t202id=$T_OFF&p202lpid=$LPID_A" "$OUT/h7")
[ -n "$C7" ] && ok "click on a capture-off campaign recorded ($C7)" || bad "no click recorded"
grep -qi '^set-cookie: p202vid=' "$OUT/h7" && bad "capture-off campaign set p202vid" || ok "a campaign with capture off sets no cookie"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks_visitor WHERE click_id=$C7")" 0 "and links nothing, though the browser has a cookie"

say "a landing-page beacon from another site links by p202lpid and mints nothing"
before=$(Q "SELECT MAX(click_id) FROM 202_clicks")
curl -s -D "$OUT/h8" -o "$OUT/b8.js" -A "$UA" -H 'Sec-Fetch-Site: cross-site' -H 'Referer: http://lp.example/' \
  "$BASE/tracking202/static/record.php?lpip=$LP_PUBLIC&p202lpid=$LPID_A"
C8=$(Q "SELECT MAX(click_id) FROM 202_clicks")
ne "$C8" "$before" "record.php recorded a click ($C8)"
grep -qi '^set-cookie: p202vid=' "$OUT/h8" && bad "a cross-site beacon minted p202vid" || ok "no cookie minted on a cross-site beacon"
eq "$(vkey "$C8")" "$KM" "the beacon's click joins the visitor its p202lpid names"
curl -s -D "$OUT/h9" -o /dev/null -A "$UA" -H 'Sec-Fetch-Site: same-origin' "$BASE/tracking202/static/record.php?lpip=$LP_PUBLIC"
grep -qi '^set-cookie: p202vid=' "$OUT/h9" && ok "a same-site beacon does mint" || bad "same-site beacon set no cookie"

say "a signed customer id joins two devices; a forged one links nothing"
api_key() { curl -s -H "Authorization: Bearer $P202_API_KEY" "$BASE/api/v3/users/$USER_ID/identity-key" | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["linking_key"])'; }
LINK=$(api_key)
[[ "$LINK" =~ ^[0-9a-f]{64}$ ]] && ok "the API hands out the account's linking key" || bad "no linking key: '$LINK'"
eq "$LINK" "$(Q "SELECT link_key FROM 202_identity_keys WHERE user_id=$USER_ID")" "the key the API shows is the one the tracker verifies with"
# The operator's server signs "<type>:<value>" with the linking key.
sign() { printf %s "$1" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$LINK" | awk '{print $NF}'; }
ANA=$(printf %s 'ana@example.com' | sha256sum | awk '{print $1}')
ANA_UP=$(printf %s "$ANA" | tr 'a-f' 'A-F')
SIG=$(sign "email_sha256:$ANA")
CP=$(click "$OUT/b5" "t202id=$T_ON" "$OUT/h10")   # the phone: a new visitor
KP=$(vkey "$CP")
ne "$KP" "$KM" "the phone starts as its own visitor"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/pb.php?acip=$ACIP_ON&subid=$C2&txid=L1&cust=$ANA&cust_type=email_sha256&cust_sig=$SIG")
eq "$code" 200 "laptop conversion with the signed customer accepted"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/pb.php?acip=$ACIP_ON&subid=$CP&txid=P1&cust=$ANA&cust_type=email_sha256&cust_sig=$(sign "custom:$ANA")")
eq "$code" 200 "a conversion with a forged signature is still recorded"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$CP AND signal_type='cust'")" 0 "but links nothing"
eq "$(vkey "$CP")" "$KP" "the phone is still its own visitor"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/pb.php?acip=$ACIP_ON&subid=$CP&txid=P2&cust=$ANA_UP&cust_type=email_sha256&cust_sig=$SIG")
eq "$code" 200 "phone conversion with the same signed customer accepted"
KJ=$(vkey "$CP")
eq "$KJ" "$(( KM > KP ? KM : KP ))" "the signed customer merged phone and laptop"
eq "$(vkey "$C1")" "$KJ" "the laptop's first click is in the joined journey"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_signals WHERE signal_type='cust'")" 1 "one customer signal: the digest folds to lower case"

say "a read-only key cannot fetch the signing secret"
RO=$(curl -s -X POST -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' -d '{"scope":"read"}' \
  "$BASE/api/v3/users/$USER_ID/api-keys" | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; print(d.get("api_key") or d.get("key") or "")')
[ -n "$RO" ] && ok "minted a read-only key" || bad "could not mint a read-only key"
code=$(curl -s -o "$OUT/ro.json" -w '%{http_code}' -H "Authorization: Bearer $RO" "$BASE/api/v3/users/$USER_ID/identity-key")
eq "$code" 403 "a read-only key is refused the linking key"
grep -q "users:write" "$OUT/ro.json" && ok "and told which scope it needs" || bad "refusal does not name the scope: $(cat "$OUT/ro.json")"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $RO" "$BASE/api/v3/users/$USER_ID/identity-key/rotate")
eq "$code" 403 "and cannot rotate it"
curl -s -o /dev/null -X DELETE -H "Authorization: Bearer $P202_API_KEY" "$BASE/api/v3/users/$USER_ID/api-keys/$RO"

say "rotating the linking key stops old signatures linking"
OLD_SIG=$(sign "custom:bob-1")
code=$(curl -s -o "$OUT/rot.json" -w '%{http_code}' -X POST -H "Authorization: Bearer $P202_API_KEY" "$BASE/api/v3/users/$USER_ID/identity-key/rotate")
eq "$code" 200 "rotate answered 200"
NEW=$(api_key)
ne "$NEW" "$LINK" "the key changed"
CB=$(click "$OUT/b7" "t202id=$T_ON" "$OUT/h12")
curl -s -o /dev/null "$BASE/tracking202/static/pb.php?acip=$ACIP_ON&subid=$CB&txid=B1&cust=bob-1&cust_sig=$OLD_SIG"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$CB AND signal_type='cust'")" 0 "a signature made with the old key links nothing"
LINK=$NEW
curl -s -o /dev/null "$BASE/tracking202/static/pb.php?acip=$ACIP_ON&subid=$CB&txid=B2&cust=bob-1&cust_sig=$(sign "custom:bob-1")"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$CB AND signal_type='cust'")" 1 "one made with the new key links"

say "the API links a customer_ref without a signature"
CA=$(click "$OUT/b6" "t202id=$T_ON" "$OUT/h11")
curl -s -o "$OUT/api.json" -w '%{http_code}' -X POST -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' \
  -d "{\"click_id\": $CA, \"transaction_id\": \"API1\", \"customer_ref\": \"$ANA\", \"customer_ref_type\": \"email_sha256\"}" \
  "$BASE/api/v3/conversions" > "$OUT/api.code"
eq "$(cat "$OUT/api.code")" 201 "API conversion created"
KA=$(vkey "$CA")
eq "$KA" "$(( KJ > KA ? KJ : KA ))" "the API's customer_ref merged that browser (into the higher key)"
eq "$(vkey "$C1")" "$KA" "and the laptop's first click is in the same journey"

say "a signal that joins too many visitors is quarantined"
# Browser 1 attaches the lpid to its visitor; each later browser has its own
# visitor first and is then merged by the lpid: 22 browsers, 21 merges.
QFIRST=""
for i in $(seq 1 22); do
  click "$OUT/q$i" "t202id=$T_ON" "$OUT/hq" > /dev/null
  c=$(click "$OUT/q$i" "t202id=$T_ON&p202lpid=$LPID_Q" "$OUT/hq")
  [ -z "$QFIRST" ] && QFIRST=$c
done
QROW=$(Q "SELECT merges, quarantined_at IS NOT NULL FROM 202_identity_signals WHERE signal_type='lpid' AND visitor_key = (SELECT visitor_key FROM 202_clicks_visitor WHERE click_id=$QFIRST)")
eq "$QROW" "$(printf '21\t1')" "the shared lpid is quarantined after its 21st merge"
CROWD=$(vkey "$QFIRST")
CQ1=$(click "$OUT/qx" "t202id=$T_ON" "$OUT/hq")
CQ2=$(click "$OUT/qx" "t202id=$T_ON&p202lpid=$LPID_Q" "$OUT/hq")
ne "$(vkey "$CQ2")" "$CROWD" "a new browser carrying it is not pulled into the crowd"
eq "$(vkey "$CQ2")" "$(vkey "$CQ1")" "it stays its own visitor"
eq "$(Q "SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$CQ2 AND signal_type='lpid'")" 1 "though it is still observed"

say "the server log"
LOG=${P202_SERVER_LOG:-}
if [ -n "$LOG" ] && [ -f "$LOG" ]; then
  # Warnings are not counted: record_simple.php's referrer parsing reads
  # optional query keys unguarded and predates this pass.
  if grep -E 'identity:|PHP Fatal' "$LOG" | grep -q .; then
    bad "identity errors or fatals in the server log:"; grep -E 'identity:|PHP Fatal' "$LOG" | tail -5
  else
    ok "no identity errors or fatals logged"
  fi
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
