#!/bin/bash
# Live pass for the legacy conversion endpoints: the per-campaign pixel
# (tracking202/static/px.php), the per-campaign postback (pb.php) and the
# ClickBank INS receiver (cb202.php). Until this change all three only flagged
# the click; this pass proves each one now writes a 202_conversion_logs row
# through the shared writer, with the gate, the scoping and the de-duplication
# that come with it.
#
# The endpoints are public, so no login is needed. Clicks and a campaign are
# seeded straight into the scratch database, because a redirect through
# dl.php would need a tracker and a network as well and the click row is all
# these endpoints read.

# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}

# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }
# -----------------------------------------------------------------------

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

# The writer refuses to convert a click it cannot find, so the account and the
# campaign must be real rows; only the click rows are made up. Ids are high so
# they cannot collide with anything the instance created itself.
USER_ID=$(Q "SELECT user_id FROM 202_users ORDER BY user_id LIMIT 1")
[ -n "$USER_ID" ] || { echo "no user in $DB" >&2; exit 2; }
NOW=$(date +%s)
CLICK_A=910000001; CLICK_B=910000002; CLICK_C=910000003; CLICK_D=910000004; CLICK_E=910000005
ACIP=987654321

mysql_q "$DB" <<SQL
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D,$CLICK_E);
DELETE FROM 202_clicks      WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D,$CLICK_E);
DELETE FROM 202_clicks_spy  WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D,$CLICK_E);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP, $((ACIP+1)));
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout)
  VALUES ($ACIP, $USER_ID, 0, 'legacy-pixels pass', 'http://example.test/', 7.50, $NOW, 7.50),
         ($((ACIP+1)), $USER_ID, 0, 'legacy-pixels other', 'http://example.test/', 1.00, $NOW, 1.00);
SQL
CAMP=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP")
OTHER=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$((ACIP+1))")
mysql_q "$DB" <<SQL
INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
  VALUES ($CLICK_A, $USER_ID, $CAMP,  0, 0, 0.10, 7.50, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_B, $USER_ID, $CAMP,  0, 0, 0.10, 7.50, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_C, $USER_ID, $OTHER, 0, 0, 0.10, 1.00, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_D, $USER_ID, $CAMP,  0, 0, 0.10, 7.50, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_E, $((USER_ID+1000)), $CAMP, 0, 0, 0.10, 7.50, 0, 0, 0, 0, $((NOW-3600)), 0, 0);
INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
  SELECT click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time
  FROM 202_clicks WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D,$CLICK_E);
SQL

rows()   { Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$1 AND deleted=0"; }
lead()   { Q "SELECT click_lead FROM 202_clicks WHERE click_id=$1"; }
payout() { Q "SELECT click_payout FROM 202_clicks WHERE click_id=$1"; }
ptype()  { Q "SELECT GROUP_CONCAT(DISTINCT pixel_type) FROM 202_conversion_logs WHERE click_id=$1"; }
txids()  { Q "SELECT GROUP_CONCAT(COALESCE(transaction_id,'-') ORDER BY conv_id) FROM 202_conversion_logs WHERE click_id=$1"; }

say "px.php: the per-campaign pixel records a row from the cookie"
curl -sS -o /dev/null -w '%{http_code}\n' -b "tracking202subid=$CLICK_A" "$BASE/tracking202/static/px.php?acip=$ACIP" > "$OUT/px1"
eq "$(cat "$OUT/px1")" 200 "pixel answers 200"
eq "$(rows $CLICK_A)" 1 "one conversion row written"
eq "$(lead $CLICK_A)" 1 "click flagged as a lead"
eq "$(ptype $CLICK_A)" 1 "row carries pixel_type 1 (image pixel)"
eq "$(payout $CLICK_A)" 7.50000 "click payout kept at the campaign payout (pixel carries no amount)"
curl -sS -o /dev/null -b "tracking202subid=$CLICK_A" "$BASE/tracking202/static/px.php?acip=$ACIP"
eq "$(rows $CLICK_A)" 1 "a second fire of the same pixel adds no row (one conversion per click without an id)"

say "px.php: a cookie naming another account's click does not convert for this campaign"
# CLICK_E is a real click owned by a different user_id. A cookie can name any
# click on the install, so the owner check is what keeps it out.
curl -sS -o /dev/null -w '%{http_code}\n' -b "tracking202subid=$CLICK_E" "$BASE/tracking202/static/px.php?acip=$ACIP" > "$OUT/pxE"
eq "$(cat "$OUT/pxE")" 200 "the pixel still answers 200 (it is an image tag)"
eq "$(rows $CLICK_E)" 0 "no row for the other account's click"
eq "$(lead $CLICK_E)" 0 "and it is not flagged"
curl -sS -o /dev/null -b "tracking202subid=not-a-number" "$BASE/tracking202/static/px.php?acip=$ACIP"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id NOT IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D,$CLICK_E) AND conv_time>=$NOW")" 0 "a garbage cookie records nothing for a made-up click"

say "pb.php: the per-campaign postback records rows and de-duplicates by transaction id"
curl -sS -o /dev/null -w '%{http_code}\n' "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=$CLICK_B&txid=ORDER-1" > "$OUT/pb1"
eq "$(cat "$OUT/pb1")" 200 "postback answers 200"
eq "$(rows $CLICK_B)" 1 "one row for ORDER-1"
eq "$(ptype $CLICK_B)" 2 "row carries pixel_type 2 (postback)"
curl -sS -o /dev/null "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=$CLICK_B&txid=ORDER-1"
eq "$(rows $CLICK_B)" 1 "replaying ORDER-1 adds no row"
curl -sS -o /dev/null "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=$CLICK_B&txid=ORDER-2"
eq "$(rows $CLICK_B)" 2 "a second transaction id is a second row on the same click"
eq "$(txids $CLICK_B)" "ORDER-1,ORDER-2" "both transaction ids kept on their rows"
curl -sS -o /dev/null "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=$CLICK_B"
eq "$(rows $CLICK_B)" 2 "a postback without an id on a click that already converted adds nothing"

say "pb.php: a click on a different campaign is refused by the campaign scope"
eq "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=$CLICK_C&txid=ORDER-9")" 404 "answers 404 for a click on another campaign"
eq "$(rows $CLICK_C)" 0 "no row for a click that belongs to another campaign"
eq "$(lead $CLICK_C)" 0 "that click is not flagged either"
eq "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=910099999&txid=ORDER-9")" 404 "answers 404 for a subid this install does not have"

say "click ids are exact integers, never cast from a fraction"
eq "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=$CLICK_C.9&txid=FRAC-1")" 404 "pb refuses a fractional subid"
eq "$(rows $CLICK_C)" 0 "and records nothing on the click it would have cast to"
curl -sS -o /dev/null -b "tracking202subid=$CLICK_C.9" "$BASE/tracking202/static/px.php?acip=$((ACIP+1))"
eq "$(rows $CLICK_C)" 0 "px refuses a fractional cookie (records nothing on the truncated click)"

say "cb202.php: one row per ClickBank receipt, payout from the order total"
CBKEY="livepasskey123"
mysql_q "$DB" -e "UPDATE 202_users_pref SET cb_key='$CBKEY' WHERE user_id=1"
cb_post() { # $1 receipt, $2 click, $3 amount, $4 type
  php -r '
    [$key,$receipt,$click,$amount,$type] = array_slice($argv,1);
    $order = json_encode(["transactionType"=>$type,"receipt"=>$receipt,"trackingCodes"=>[$click],"totalAccountAmount"=>$amount]);
    $iv = random_bytes(16);
    $enc = openssl_encrypt($order,"AES-128-CBC",substr(sha1($key),0,32),OPENSSL_RAW_DATA,$iv);
    echo json_encode(["notification"=>base64_encode($enc),"iv"=>base64_encode($iv)]);
  ' "$CBKEY" "$1" "$2" "$3" "$4" > "$OUT/cb.json"
  curl -sS -o "$OUT/cb.out" -w '%{http_code}' -H 'Content-Type: application/json' --data-binary @"$OUT/cb.json" "$BASE/tracking202/static/cb202.php"
}
eq "$(cb_post RCPT-A $CLICK_D 49.95 SALE)" 200 "SALE with a receipt answers 200"
eq "$(rows $CLICK_D)" 1 "one row for receipt RCPT-A"
eq "$(Q "SELECT click_payout FROM 202_conversion_logs WHERE click_id=$CLICK_D AND transaction_id='RCPT-A'")" 49.95000 "the row's payout is the order total"
eq "$(payout $CLICK_D)" 49.95000 "the click's payout follows the order total (replace mode)"
eq "$(cb_post RCPT-A $CLICK_D 49.95 SALE)" 200 "the same receipt again answers 200"
eq "$(rows $CLICK_D)" 1 "a repeated INS delivery of one sale adds no row"
eq "$(cb_post RCPT-B $CLICK_D 10.00 SALE)" 200 "a second receipt answers 200"
eq "$(rows $CLICK_D)" 2 "a second sale on the click is a second row"
eq "$(cb_post RCPT-X 910099999 5.00 SALE)" 404 "a sale naming an unknown click is a 404, not a silent 200"
grep -q 'Unknown tracking code' "$OUT/cb.out" && ok "the 404 names the cause" || bad "the 404 names the cause"
eq "$(cb_post RCPT-F $CLICK_E 5.00 SALE)" 404 "a sale naming another account's click is a 404 (scoped to the key's account)"
eq "$(rows $CLICK_E)" 0 "and nothing was written in the other account"
eq "$(cb_post RCPT-G $CLICK_D.9 5.00 SALE)" 400 "a fractional tracking code is a 400"
eq "$(cb_post '' $CLICK_D 5.00 SALE)" 400 "a sale without a receipt is a 400"
grep -q 'Missing receipt' "$OUT/cb.out" && ok "the 400 names the missing receipt" || bad "the 400 names the missing receipt"
php -r '
  $key="'"$CBKEY"'"; $iv=random_bytes(16);
  $order=json_encode(["transactionType"=>"SALE","receipt"=>"RCPT-H","trackingCodes"=>["'"$CLICK_D"'"]]);
  $enc=openssl_encrypt($order,"AES-128-CBC",substr(sha1($key),0,32),OPENSSL_RAW_DATA,$iv);
  echo json_encode(["notification"=>base64_encode($enc),"iv"=>base64_encode($iv)]);' > "$OUT/cbnototal.json"
eq "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data-binary @"$OUT/cbnototal.json" "$BASE/tracking202/static/cb202.php")" 400 "a sale with no total at all is a 400, not a zero-value sale"
eq "$(rows $CLICK_D)" 2 "and neither wrote anything"
php -r '
  $key="'"$CBKEY"'"; $iv=random_bytes(16);
  $order=json_encode(["transactionType"=>"SALE","receipt"=>"RCPT-Z","trackingCodes"=>["'"$CLICK_D"'"],"totalAccountAmount"=>"lots"]);
  $enc=openssl_encrypt($order,"AES-128-CBC",substr(sha1($key),0,32),OPENSSL_RAW_DATA,$iv);
  echo json_encode(["notification"=>base64_encode($enc),"iv"=>base64_encode($iv)]);' > "$OUT/cbbad.json"
eq "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data-binary @"$OUT/cbbad.json" "$BASE/tracking202/static/cb202.php")" 400 "a non-numeric order total is a 400, not a zero-value sale"
eq "$(rows $CLICK_D)" 2 "and it wrote nothing"

say "server log"
if [ -n "${P202_SERVER_LOG:-}" ] && [ -f "$P202_SERVER_LOG" ]; then
  if grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' "$P202_SERVER_LOG" | grep -E 'static/(px|pb|cb202)\.php|static-endpoint-helpers' > "$OUT/warn"; then
    bad "PHP warnings from the legacy endpoints:"; sed 's/^/    | /' "$OUT/warn"
  else
    ok "no PHP warnings, notices or fatals from the legacy endpoints"
  fi
else
  printf '  (set P202_SERVER_LOG to the php -S log to check for warnings)\n'
fi

mysql_q "$DB" <<SQL
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D);
DELETE FROM 202_clicks      WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D);
DELETE FROM 202_clicks_spy  WHERE click_id IN ($CLICK_A,$CLICK_B,$CLICK_C,$CLICK_D);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP, $((ACIP+1)));
SQL

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
