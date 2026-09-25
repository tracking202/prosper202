#!/bin/bash
# Live pass for the conversion ledger (PR 1): every path that sets a click's
# value now writes a ledger row, and the click's value is derived from its
# rows. Driven over HTTP against a running instance, with the database read
# back after every step:
#
#   - the global postback (gpb.php): a row per transaction id, replayed as a
#     duplicate; the latest wins in a replace campaign; the rows add up in an
#     accumulate campaign, where an id-less conversion happens once;
#   - reversals: status=reversed nets a sale to $0, a replay is a duplicate,
#     a second different reversal is a 422 naming the first, an unknown sale
#     a 404;
#   - the traffic-source pixel fires after recording, once, with the
#     conversion's own transaction id and amount;
#   - the revenue CSV upload (logged in, token-carrying POSTs): one row per
#     line, summed per file, the next file replacing the last; the old GET
#     apply link and a token-less POST write nothing;
#   - the subid upload and delete-subids pages: rows written and cleared
#     through the ledger;
#   - the MTA outbox holds a row for every conversion.
#
# Seeds its own campaigns, traffic source and clicks (ids 930001-930020)
# and removes them at both ends, so it can run repeatedly.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_API_KEY=${P202_API_KEY:-}
P202_PASS=${P202_PASS:-}
CAPTURE_PORT=${P202_CAPTURE_PORT:-8099}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass logs in as $P202_USER and needs its password." >&2
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
JAR="$OUT/jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF "$2" "$1"; then ok "$3"; else bad "$3"; fi; }

USER_ID=$(Q "SELECT user_id FROM 202_users ORDER BY user_id LIMIT 1")
[ -n "$USER_ID" ] || { echo "no user in $DB" >&2; exit 2; }
NOW=$(date +%s)
API=930008; R=930001; RA=930002; ACC=930003; UP=930004; UP2=930005; SUB=930006; DEL=930007
CLICKS="$R,$RA,$ACC,$UP,$UP2,$SUB,$DEL,$API"
ACIP_R=930100; ACIP_A=930101

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICKS));
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_spy WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_tracking WHERE click_id IN ($CLICKS);
DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN (SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name = 'ledger-pass-source');
DELETE FROM 202_ppc_accounts WHERE ppc_account_name = 'ledger-pass-source';
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP_R, $ACIP_A);
DELETE FROM 202_conversion_uploads WHERE file_name LIKE 'ledger-pass-%';
SQL
}
cleanup

# A capture server for the traffic-source postback: it logs every request.
CAPTURE_DIR="$OUT/capture"; mkdir -p "$CAPTURE_DIR"; : > "$CAPTURE_DIR/pb"
( cd "$CAPTURE_DIR" && exec python3 -m http.server "$CAPTURE_PORT" --bind 127.0.0.1 ) 2> "$OUT/capture.log" &
CAPTURE_PID=$!
trap 'kill $CAPTURE_PID 2>/dev/null; cleanup' EXIT
sleep 1

mysql_q "$DB" <<SQL
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode)
  VALUES ($ACIP_R, $USER_ID, 0, 'ledger replace', 'http://example.test/', 7.50, $NOW, 7.50, 'replace'),
         ($ACIP_A, $USER_ID, 0, 'ledger accumulate', 'http://example.test/', 4.00, $NOW, 4.00, 'accumulate');
INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ($USER_ID, 0, 'ledger-pass-source', $NOW);
SQL
CAMP_R=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_R")
CAMP_A=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_A")
PPC=$(Q "SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name='ledger-pass-source'")
[ -n "$CAMP_R" ] && [ -n "$CAMP_A" ] && [ -n "$PPC" ] || { echo "seeding campaigns failed" >&2; exit 2; }
Q "INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('http://127.0.0.1:$CAPTURE_PORT/pb?tx=[[transactionid]]&pay=[[payout]]&sub=[[subid]]', 4, $PPC)"

seed_click() { # id campaign payout ppc
  mysql_q "$DB" -e "
    INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
      VALUES ($1, $USER_ID, $2, 0, $4, 0.10, $3, 0, 0, 0, 0, $((NOW-3600)), 0, 0);
    INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
      VALUES ($1, $USER_ID, $2, 0, $4, 0.10, $3, 0, 0, 0, 0, $((NOW-3600)));
    INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES ($1, 0, 0, 0, 0);"
}
seed_click $R "$CAMP_R" 7.50 "$PPC"
seed_click $RA "$CAMP_R" 7.50 0
seed_click $ACC "$CAMP_A" 4.00 0
seed_click $UP "$CAMP_R" 7.50 0
seed_click $UP2 "$CAMP_R" 7.50 0
seed_click $SUB "$CAMP_R" 7.50 0
seed_click $DEL "$CAMP_R" 7.50 0
seed_click $API "$CAMP_R" 7.50 0
[ "$(Q "SELECT COUNT(*) FROM 202_clicks_tracking WHERE click_id IN ($CLICKS)")" = 8 ] || { echo "seeding clicks failed" >&2; exit 2; }

value()  { Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$1"; }
rows()   { Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$1 AND deleted=0"; }
col()    { Q "SELECT GROUP_CONCAT(COALESCE($2,'-') ORDER BY conv_id SEPARATOR ',') FROM 202_conversion_logs WHERE click_id=$1"; }
gpb()    { curl -sS -o "$OUT/gpb.out" -w '%{http_code}' "$BASE/tracking202/static/gpb.php?$1"; }

say "global postback, replace campaign: the latest conversion is the click's value"
eq "$(gpb "subid=$R&amount=2&txid=T1")" 202 "a postback for a click with a server-to-server pixel answers 202"
eq "$(value $R)" "1/2.00000" "the click is a lead worth the postback's \$2"
eq "$(col $R source)" "postback" "the row says what produced it"
eq "$(col $R dedupe_key)" "tx:T1" "and is keyed by the network's transaction id"
gpb "subid=$R&amount=9&txid=T1" >/dev/null
eq "$(rows $R)" 1 "a replay of T1 adds no row"
eq "$(value $R)" "1/2.00000" "and changes nothing"
gpb "subid=$R&amount=5&txid=T2" >/dev/null
eq "$(value $R)" "1/5.00000" "a second transaction replaces the first (replace mode)"
eq "$(col $R superseded_reason)" "replace,-" "the first row says it was replaced"

say "the traffic source hears each new conversion once, after it is recorded"
sleep 1
grep -oE 'GET /pb\?[^ ]+' "$OUT/capture.log" > "$OUT/fired.txt" || true
eq "$(wc -l < "$OUT/fired.txt" | tr -d ' ')" 2 "two postbacks fired: T1 and T2, not the replay"
has "$OUT/fired.txt" "tx=T1&pay=2" "T1 carried its own transaction id and amount"
has "$OUT/fired.txt" "tx=T2&pay=5" "T2 carried its own transaction id and amount"

say "reversals net a sale, once"
eq "$(gpb "subid=$R&txid=T2&status=reversed")" 200 "status=reversed with T2's id answers 200, with no pixel fired"
eq "$(value $R)" "1/0.00000" "the click nets to \$0 and is still a converting click"
eq "$(col $R source_ref)" "-,-,conv:$(Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$R AND dedupe_key='tx:T2'")" "the reversal names the sale it reverses"
gpb "subid=$R&txid=T2&status=reversed" >/dev/null
eq "$(rows $R)" 3 "a replayed reversal adds no row"
eq "$(gpb "subid=$R&txid=T2&status=reversed&reversal_id=R-2")" 422 "a second, different reversal of one sale is a 422"
grep -q "already reversed by conversion" "$OUT/gpb.out" && ok "and the 422 names the reversal on file" || bad "the 422 names the reversal on file"
eq "$(gpb "subid=$R&txid=NOPE&status=reversed")" 404 "reversing an unknown sale is a 404"
eq "$(rows $R)" 3 "neither refusal wrote a row"
sleep 1
eq "$(grep -cE 'GET /pb\?' "$OUT/capture.log")" 2 "the reversal was not announced to the traffic source as a conversion"

say "accumulate campaign: the rows add up; an id-less conversion happens once"
gpb "subid=$ACC&amount=5&txid=A1" >/dev/null
gpb "subid=$ACC&amount=3&txid=A2" >/dev/null
eq "$(value $ACC)" "1/8.00000" "\$5 + \$3"
gpb "subid=$ACC" >/dev/null
eq "$(value $ACC)" "1/12.00000" "an id-less postback adds the campaign payout (\$4), not the click's running total"
gpb "subid=$ACC" >/dev/null
eq "$(value $ACC)" "1/12.00000" "a second id-less postback is the same conversion"
eq "$(col $ACC dedupe_key)" "tx:A1,tx:A2,conversion" "keyed tx:A1, tx:A2 and the one plain conversion"

say "the v3 API records through the ledger too"
if [ -n "$P202_API_KEY" ]; then
  api() { curl -sS -o "$OUT/api.out" -w '%{http_code}' -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' -X POST "$BASE/api/v3/conversions" --data "$1"; }
  eq "$(api "{\"click_id\": $API, \"payout\": \"abc\"}")" 422 "a payout that is not a number is a 422, not a \$0 conversion"
  eq "$(rows $API)" 0 "and writes nothing"
  eq "$(api "{\"click_id\": $API, \"payout\": 12.5, \"transaction_id\": \"API-1\"}")" 201 "a conversion with a transaction id"
  eq "$(value $API)" "1/12.50000" "is the click's value"
  eq "$(col $API source)" "api" "and says the API produced it"
  eq "$(api "{\"click_id\": $API, \"transaction_id\": \"API-1\", \"status\": \"reversed\"}")" 201 "reversing it through the API"
  eq "$(value $API)" "1/0.00000" "nets the click to \$0"
  eq "$(api "{\"click_id\": $API, \"transaction_id\": \"API-1\", \"status\": \"reversed\", \"reversal_id\": \"X\"}")" 422 "a second, different reversal is a 422"
  eq "$(api "{\"click_id\": $API, \"transaction_id\": \"NOPE\", \"status\": \"reversed\"}")" 404 "a reversal of an unknown sale is a 404"
  eq "$(api "{\"click_id\": $API, \"status\": \"approved\"}")" 422 "an unknown status is a 422"
else
  echo "  (set P202_API_KEY to drive the API cases)"
fi

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/update/upload.php" -o "$OUT/up0.html"
if grep -q 'name="user_pass"' "$OUT/up0.html"; then bad "session established"; else ok "session established"; fi
TOKEN=$(grep -oE 'name="token" value="[^"]+"' "$OUT/up0.html" | head -1 | sed 's/.*value="//; s/"//')
[ -n "$TOKEN" ] && ok "the upload form carries the session token" || bad "the upload form carries the session token"

say "revenue upload: one row per line, summed per file, the next file replaces it"
printf 'subid,commission\n%s,$1.00\n%s,2.50\n%s,4\n999999999,3\n%s,pending\n' $UP $UP $UP2 $UP2 > "$OUT/ledger-pass-a.csv"
LOC=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -F "token=$TOKEN" -F "csv=@$OUT/ledger-pass-a.csv" "$BASE/tracking202/update/upload.php")
FILE=$(printf '%s' "$LOC" | sed -n 's/.*[?&]file=\([^&]*\).*/\1/p')
[ -n "$FILE" ] && ok "the uploaded file is kept for the column picker ($FILE)" || bad "upload redirected to the column picker (got '$LOC')"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/upload.php?case=2&file=$FILE&click_id=0&click_payout=1" -o "$OUT/getapply.html"
eq "$(rows $UP)" 0 "the old GET apply link writes nothing"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/upload.php?case=2" --data-urlencode "file=$FILE" \
  --data-urlencode click_id=0 --data-urlencode click_payout=1 -o "$OUT/notoken.html"
eq "$(rows $UP)" 0 "a POST without the session token writes nothing"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/upload.php?case=2" --data-urlencode "token=$TOKEN" --data-urlencode "file=$FILE" \
  --data-urlencode click_id=0 --data-urlencode click_payout=1 -o "$OUT/apply.html"
has "$OUT/apply.html" "3 line(s) recorded, 2 skipped" "the result counts recorded and skipped lines"
has "$OUT/apply.html" "no click with this subid in your account" "an unknown subid is listed with its reason"
has "$OUT/apply.html" "the commission is not a number" "an unreadable amount is listed with its reason"
eq "$(value $UP)" "1/3.50000" "two lines for one subid are summed (\$1 + \$2.50)"
eq "$(value $UP2)" "1/4.00000" "the other subid gets its own line"
eq "$(col $UP source)" "revenue_upload,revenue_upload" "each line is its own row"
printf 'subid,commission\n%s,6\n' $UP > "$OUT/ledger-pass-b.csv"
LOC=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -F "token=$TOKEN" -F "csv=@$OUT/ledger-pass-b.csv" "$BASE/tracking202/update/upload.php")
FILE=$(printf '%s' "$LOC" | sed -n 's/.*[?&]file=\([^&]*\).*/\1/p')
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/upload.php?case=2" --data-urlencode "token=$TOKEN" --data-urlencode "file=$FILE" \
  --data-urlencode click_id=0 --data-urlencode click_payout=1 -o "$OUT/apply2.html"
eq "$(value $UP)" "1/6.00000" "the next file replaces the last"
eq "$(col $UP superseded_reason)" "batch,batch,-" "the earlier file's lines say a newer upload replaced them"
eq "$(value $UP2)" "1/4.00000" "a subid the new file does not name keeps its value"

say "a report larger than 100 KB is read to the end"
{ printf 'subid,commission\n'; for _ in $(seq 1 6000); do printf '%s,0.01\n' 999999998; done; printf '%s,7\n' $UP2; } > "$OUT/ledger-pass-big.csv"
LOC=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -F "token=$TOKEN" -F "csv=@$OUT/ledger-pass-big.csv" "$BASE/tracking202/update/upload.php")
FILE=$(printf '%s' "$LOC" | sed -n 's/.*[?&]file=\([^&]*\).*/\1/p')
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/upload.php?case=2" --data-urlencode "token=$TOKEN" --data-urlencode "file=$FILE" \
  --data-urlencode click_id=0 --data-urlencode click_payout=1 -o "$OUT/apply3.html"
eq "$(value $UP2)" "1/7.00000" "the last line of a $(wc -c < "$OUT/ledger-pass-big.csv" | tr -d ' ')-byte file was applied"

say "subid upload and delete pages go through the ledger"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/subids.php" --data-urlencode "subids=$SUB" -o "$OUT/sub-notoken.html"
eq "$(rows $SUB)" 0 "the subid upload without the token writes nothing"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/subids.php" -o "$OUT/sub0.html"
ST=$(grep -oE 'name="token" value="[^"]+"' "$OUT/sub0.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/subids.php" --data-urlencode "token=$ST" --data-urlencode "subids=$SUB" -o "$OUT/sub1.html"
eq "$(value $SUB)" "1/7.50000" "marked as converted at the click's value"
eq "$(col $SUB source)" "subid_upload" "with its own source"
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/subids.php" --data-urlencode "token=$ST" --data-urlencode "subids=$SUB" -o "$OUT/sub2.html"
eq "$(rows $SUB)" 1 "marking it again adds nothing"
gpb "subid=$DEL&amount=3&txid=D1" >/dev/null
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/delete-subids.php" -o "$OUT/del0.html"
DT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/del0.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/update/delete-subids.php" --data-urlencode "token=$DT" --data-urlencode "subids=$DEL" -o "$OUT/del1.html"
eq "$(value $DEL | cut -d/ -f1)" 0 "a deleted subid is no longer a lead"
eq "$(rows $DEL)" 0 "and its conversion rows are deleted, not left counting"

say "every conversion is queued for attribution"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs cl LEFT JOIN 202_attribution_pending p USING (conv_id) WHERE cl.click_id IN ($CLICKS) AND p.conv_id IS NULL")" 0 "no conversion is missing its outbox row"

say "server log"
if [ -n "${P202_SERVER_LOG:-}" ]; then
  # One warning predates the ledger and is not about it: PHP's built-in
  # server never sets SERVER_ADDR, which client_ip() and systemHash() read.
  if grep -E "PHP (Fatal|Warning|Notice|Deprecated)" "$P202_SERVER_LOG" | grep -v 'Undefined array key "SERVER_ADDR"' > "$OUT/warnings.txt"; then
    bad "the server logged PHP warnings: $(head -3 "$OUT/warnings.txt")"
  else
    ok "no PHP warnings in the server log"
  fi
else
  echo "  (set P202_SERVER_LOG to the php -S log to check for warnings)"
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
