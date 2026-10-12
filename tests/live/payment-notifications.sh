#!/bin/bash
# Live pass for the payment platforms' notification URLs: JVZoo
# (tracking202/static/jvzoo.php), Zaxaa (zpn.php), PayKickstart
# (paykickstart.php) and Slack slash commands (slack.php). Every notification
# is signed with the platform's own published code
# (payment-notification-sign.php), posted over HTTP, and judged by the
# status, the body and the ledger rows it left.
#
# Two accounts are seeded straight into the scratch database, each with a
# click and its own secrets, so a notification verified with one account's
# secret is shown never to convert the other's click.

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
SIGN="php $(dirname "${BASH_SOURCE[0]}")/payment-notification-sign.php"
# -----------------------------------------------------------------------

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3 (body: $(head -c 300 "$1"))"; fi; }

# post <name> <url path> <form body> -> status; body in $OUT/<name>
post() { curl -sS -o "$OUT/$1" -w '%{http_code}' -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "$3" "$BASE/tracking202/static/$2"; }
field() { php -r '$j = json_decode(file_get_contents($argv[1]), true); $v = $j; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_bool($v) ? ($v ? "true" : "false") : (string) $v;' "$OUT/$1" "$2"; }
rows() { Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$1 AND deleted=0"; }
amounts() { Q "SELECT GROUP_CONCAT(CONCAT(source, ':', click_payout) ORDER BY conv_id SEPARATOR ' ') FROM 202_conversion_logs WHERE click_id=$1 AND deleted=0"; }

USER_A=$(Q "SELECT user_id FROM 202_users ORDER BY user_id LIMIT 1")
[ -n "$USER_A" ] || { echo "no user in $DB" >&2; exit 2; }
USER_B=920000
NOW=$(date +%s)
CLICK_J=920000001; CLICK_Z=920000002; CLICK_P=920000003; CLICK_B=920000004
CLICKS="$CLICK_J,$CLICK_Z,$CLICK_P,$CLICK_B"
ACIP=920000101

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICKS));
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_spy WHERE click_id IN ($CLICKS);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP, $((ACIP+1)));
DELETE FROM 202_users_pref WHERE user_id = $USER_B;
DELETE FROM 202_users WHERE user_id = $USER_B;
SQL
}
cleanup
SAVED=$(Q "SELECT CONCAT('jvzoo_ipn_secret_key = ', QUOTE(jvzoo_ipn_secret_key), ', zaxaa_api_signature = ', QUOTE(zaxaa_api_signature),
  ', paykickstart_ipn_secret = ', QUOTE(paykickstart_ipn_secret), ', user_slack_signing_secret = ', QUOTE(user_slack_signing_secret),
  ', user_account_currency = ', QUOTE(user_account_currency)) FROM 202_users_pref WHERE user_id=$USER_A")
[ -n "$SAVED" ] || { echo "account $USER_A has no preferences row" >&2; exit 2; }
mysql_q "$DB" <<SQL
INSERT INTO 202_users (user_id, user_name, user_pass, user_email, user_dash_email, user_time_register, install_hash, user_hash, user_active, user_deleted)
  VALUES ($USER_B, 'payment-pass-b', 'x', 'b@example.test', '', $NOW, '', '', 1, 0);
INSERT INTO 202_users_pref (user_id, jvzoo_ipn_secret_key, zaxaa_api_signature, paykickstart_ipn_secret, user_slack_signing_secret)
  VALUES ($USER_B, 'jvz-secret-b', 'zx-signature-b', 'pk-secret-b', 'slack-secret-b');
UPDATE 202_users_pref SET jvzoo_ipn_secret_key = 'jvz-secret', zaxaa_api_signature = 'zx-signature',
  paykickstart_ipn_secret = 'pk-other pk-campaign-secret', user_slack_signing_secret = 'slack-secret-a',
  user_account_currency = 'USD' WHERE user_id = $USER_A;
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout)
  VALUES ($ACIP, $USER_A, 0, 'payment pass A', 'http://example.test/', 5, $NOW, 5),
         ($((ACIP+1)), $USER_B, 0, 'payment pass B', 'http://example.test/', 5, $NOW, 5);
SQL
CAMP_A=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP")
CAMP_B=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$((ACIP+1))")
mysql_q "$DB" <<SQL
INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
  VALUES ($CLICK_J, $USER_A, $CAMP_A, 0, 0, 0, 0, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_Z, $USER_A, $CAMP_A, 0, 0, 0, 0, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_P, $USER_A, $CAMP_A, 0, 0, 0, 0, 0, 0, 0, 0, $((NOW-3600)), 0, 0),
         ($CLICK_B, $USER_B, $CAMP_B, 0, 0, 0, 0, 0, 0, 0, 0, $((NOW-3600)), 0, 0);
INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
  SELECT click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time
  FROM 202_clicks WHERE click_id IN ($CLICKS);
SQL
[ "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id IN ($CLICKS)")" = 4 ] || { echo "seeding the clicks failed" >&2; exit 2; }

jvz() { # jvz <type> <click id> <amount> <receipt>
  printf '{"caffitid":"%s","ccustcc":"US","ccustemail":"joe@test.com","ccustname":"José Smith","ccuststate":"","cproditem":"45","cprodtitle":"Test Product","cprodtype":"STANDARD","ctransaction":"%s","ctransaffiliate":"1","ctransamount":"%s","ctranspaymentmethod":"PYPL","ctransreceipt":"%s","ctranstime":"1613419768","ctransvendor":"4","cupsellreceipt":"","cvendthru":""}' "$2" "$1" "$3" "$4"
}

say "JVZoo"
eq "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/jvzoo.php")" 405 "a GET is refused"
eq "$(post j1 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz SALE $CLICK_J 1000 RCPT-J1)")")" 200 "a signed SALE is accepted"
eq "$(field j1 recorded)" true "and recorded"
eq "$(amounts $CLICK_J)" "jvzoo:10.00000" "at 1000 pennies = 10.00, under source jvzoo"
eq "$(post j2 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz SALE $CLICK_J 1000 RCPT-J1)")")" 200 "the same SALE again"
eq "$(field j2 duplicate)" true "is a duplicate"
eq "$(post j3 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz BILL $CLICK_J 12.50 RCPT-J2)")")" 200 "a rebill with a dollar amount"
eq "$(amounts $CLICK_J)" "jvzoo:10.00000 jvzoo:12.50000" "is its own row, at 12.50"
body=$($SIGN jvzoo jvz-secret "$(jvz SALE $CLICK_J 1000 RCPT-J3)")
eq "$(post j4 jvzoo.php "${body/ctransamount=1000/ctransamount=99900}")" 403 "an amount changed after signing is refused"
eq "$(post j5 jvzoo.php "$($SIGN jvzoo jvz-secret-b "$(jvz SALE $CLICK_J 1000 RCPT-J4)")")" 200 "account B's secret naming account A's click"
eq "$(field j5 reason)" foreign_click "records nothing (foreign_click)"
eq "$(post j6 jvzoo.php "$($SIGN jvzoo jvz-secret-b "$(jvz SALE $CLICK_B 500 RCPT-B1)")")" 200 "account B's secret naming its own click"
eq "$(Q "SELECT user_id FROM 202_conversion_logs WHERE click_id=$CLICK_B")" "$USER_B" "records on account B"
eq "$(post j7 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz RFND $CLICK_J 1000 RCPT-J1)")")" 200 "a signed refund"
eq "$(amounts $CLICK_J)" "jvzoo:10.00000 jvzoo:12.50000 jvzoo:-10.00000" "nets the sale"
eq "$(post j8 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz CGBK $CLICK_J 1000 RCPT-J1)")")" 200 "a chargeback after the refund"
eq "$(field j8 reason)" already_reversed "does not net it twice"
eq "$(post j9 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz CANCEL-REBILL $CLICK_J 0 RCPT-J2)")")" 200 "a cancellation"
eq "$(field j9 reason)" not_a_payment "records nothing"
eq "$(post j10 jvzoo.php "$($SIGN jvzoo jvz-secret "$(jvz SALE twitterweekone 1000 RCPT-J5)")")" 200 "an affiliate's own tid"
eq "$(field j10 reason)" not_a_click_id "is not a click id"
eq "$(post j11 jvzoo.php 'transaction_type=SALE&paykey=PT-1&total=97.00&tid=920000001&cverify=ABCD1234')" 400 "a JVZIPN v2 post is refused"
has "$OUT/j11" "v1 JVZIPN" "and says to use v1"

zpn() { # zpn <type> <custom> <currency> <testmode> <receipt>
  printf '{"trans_type":"%s","trans_testmode":"%s","seller_id":"seller42","trans_receipt":"%s","trans_amount":"47.00","trans_currency":"%s","products":[{"prod_number":"SKU1","amount":"47.00","custom":"%s"}]}' "$1" "$4" "$5" "$3" "$2"
}

say "Zaxaa"
eq "$(post z1 zpn.php "$($SIGN zaxaa zx-signature "$(zpn SALE $CLICK_Z USD 0 ZX-1)")")" 200 "a signed SALE is accepted"
eq "$(field z1 products.0.recorded)" true "and its product recorded"
eq "$(Q "SELECT transaction_id FROM 202_conversion_logs WHERE click_id=$CLICK_Z")" "ZX-1:SKU1" "under the receipt and the product number"
eq "$(amounts $CLICK_Z)" "zaxaa:47.00000" "at 47.00, under source zaxaa"
eq "$(post z2 zpn.php "$($SIGN zaxaa zx-signature "$(zpn SALE $CLICK_Z EUR 0 ZX-2)")")" 422 "a payment in another currency is refused"
has "$OUT/z2" "EUR is not the account currency" "naming the currency"
eq "$(post z3 zpn.php "$($SIGN zaxaa zx-signature "$(zpn SALE $CLICK_Z USD 1 ZX-3)")")" 200 "a test purchase"
eq "$(field z3 reason)" test_mode "records nothing"
eq "$(post z4 zpn.php "$($SIGN zaxaa wrong "$(zpn SALE $CLICK_Z USD 0 ZX-4)")")" 403 "another signature is refused"
eq "$(post z5 zpn.php "$($SIGN zaxaa zx-signature "$(zpn REFUND $CLICK_Z USD 0 ZX-1)")")" 200 "a signed REFUND"
eq "$(amounts $CLICK_Z)" "zaxaa:47.00000 zaxaa:-47.00000" "nets the sale"

pks() { # pks <event> <mode> <click> <transaction> <amount> <name>
  printf '{"event":"%s","mode":"%s","amount":"%s","buyer_first_name":"%s","buyer_last_name":"Müller","transaction_id":"%s","invoice_id":"PK-INV-1","custom_subid":"%s","affiliate_commission_amount":"0","coupon_code":"","verification_code":"e2288202ad23"}' "$1" "$2" "$5" "$6" "$4" "$3"
}

say "PayKickstart"
eq "$(post p1 paykickstart.php "$($SIGN paykickstart pk-campaign-secret "$(pks sales live $CLICK_P PK-T1 9 José)")")" 200 "a sale signed with the second campaign key"
eq "$(field p1 recorded)" true "is recorded"
eq "$(amounts $CLICK_P)" "paykickstart:9.00000" "at 9, under source paykickstart"
eq "$(post p2 paykickstart.php "$($SIGN paykickstart pk-campaign-secret "$(pks sales test $CLICK_P PK-T2 9 Ann)")")" 200 "a test-mode sale"
eq "$(field p2 reason)" test_mode "records nothing"
body=$($SIGN paykickstart pk-campaign-secret "$(pks sales live $CLICK_P PK-T3 9 Ann)")
eq "$(post p3 paykickstart.php "${body/custom_subid=$CLICK_P/custom_subid=$CLICK_J}")" 403 "a click id changed after signing is refused"
eq "$(post p4 paykickstart.php "$($SIGN paykickstart pk-campaign-secret "$(pks refund live $CLICK_P PK-T1 9 José)")")" 200 "a refund of the sale"
eq "$(amounts $CLICK_P)" "paykickstart:9.00000 paykickstart:-9.00000" "nets it"
eq "$(curl -sS -o "$OUT/p5" -w '%{http_code}' -H 'Content-Type: application/json' --data '{"amount":9,"tracking_id":216}' "$BASE/tracking202/static/paykickstart.php")" 400 "the unsigned affiliate IPN is refused"

slack() { # slack <name> <secret> <timestamp> <body>
  curl -sS -o "$OUT/$1" -w '%{http_code}' -H 'Content-Type: application/x-www-form-urlencoded' \
    -H "X-Slack-Request-Timestamp: $3" -H "X-Slack-Signature: $($SIGN slack "$2" "$3" "$4")" --data-binary "$4" "$BASE/tracking202/static/slack.php"
}

say "Slack"
eq "$(slack s1 slack-secret-a "$NOW" 'command=%2Fp202&text=stats+7d&team_id=T1')" 200 "a signed /p202 stats 7d"
eq "$(field s1 response_type)" ephemeral "answers only whoever typed it"
has "$OUT/s1" "last 7 days" "for the last seven days"
has "$OUT/s1" "Clicks:" "with the clicks"
eq "$(slack s2 slack-secret-a "$NOW" 'command=%2Fp202&text=help')" 200 "help"
has "$OUT/s2" "/p202 stats yesterday" "lists the commands under the name it was called by"
eq "$(slack s3 slack-secret-a "$NOW" 'command=%2Fp202&text=stats+90d')" 200 "an unknown period"
has "$OUT/s3" 'Not a period: \"90d\"' "is named"
eq "$(slack s4 wrong "$NOW" 'command=%2Fp202&text=stats')" 401 "another secret is refused"
eq "$(slack s5 slack-secret-a "$((NOW-600))" 'command=%2Fp202&text=stats')" 401 "a request ten minutes old is refused"
eq "$(curl -sS -o /dev/null -w '%{http_code}' --data 'ssl_check=1&token=x' "$BASE/tracking202/static/slack.php")" 200 "Slack's SSL check is acknowledged"

say "server log"
if [ -n "${P202_SERVER_LOG:-}" ] && [ -f "$P202_SERVER_LOG" ]; then
  if grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' "$P202_SERVER_LOG" | grep -E 'static/(jvzoo|zpn|paykickstart|slack)\.php|Integration/' > "$OUT/warn"; then
    bad "PHP warnings from the notification URLs:"; sed 's/^/    | /' "$OUT/warn"
  else
    ok "no PHP warnings, notices or fatals from the notification URLs"
  fi
else
  printf '  (set P202_SERVER_LOG to the php -S log to check for warnings)\n'
fi

cleanup
Q "UPDATE 202_users_pref SET $SAVED WHERE user_id = $USER_A"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
