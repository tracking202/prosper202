#!/bin/bash
# Live pass for ClickBank's Instant Notification Service (cb202.php) with two
# accounts, each with its own campaign, tracker, click and ClickBank secret
# key, set through the API as a user sets them.
#
# Notifications are encrypted here as ClickBank's INS article describes,
# with the openssl command line and not the code under test: AES-256-CBC,
# the key the first 32 hex digits of SHA-1(secret key) as text, PKCS#7
# padding, the ciphertext and IV base64 in {"notification", "iv"}.
#
# Environment:
#   P202_BASE        the instance (http://127.0.0.1:8097)
#   P202_ADMIN_KEY   an API key of user 1
#   P202_DB / P202_DB_HOST / P202_DB_USER / P202_DB_PASS  its database, read to
#                    check the rows stored
#   P202_SERVER_LOG  the server's log, read for the one line each notification writes

BASE=${P202_BASE:-http://127.0.0.1:8097}
ADMIN_KEY=${P202_ADMIN_KEY:?P202_ADMIN_KEY: an API key of user 1}
DB=${P202_DB:-p202_test}
DB_HOST=${P202_DB_HOST:-127.0.0.1}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}

# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

Q() { MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" --protocol=TCP -u "$DB_USER" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
trap 'rm -rf "$OUT"' EXIT
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3 (no '$2' in $(head -c 300 "$1"))"; fi; }

api() { # api <key> <method> <path> [json] -> body on stdout
    local key="$1" method="$2" path="$3" body="${4:-}"
    if [ -n "$body" ]; then
        curl -sS -X "$method" -H "Authorization: Bearer $key" -H 'Content-Type: application/json' \
            --data-binary "$body" "$BASE/api/v3$path"
    else
        curl -sS -X "$method" -H "Authorization: Bearer $key" "$BASE/api/v3$path"
    fi
}

# --- ClickBank's side: an encrypted notification ------------------------
note() { # note <secret> <order json> [cipher] -> the request body
    local secret="$1" plain="$2" cipher="${3:-aes-256-cbc}" key keyhex ivhex
    key=$(printf '%s' "$secret" | sha1sum | cut -c1-32)
    if [ "$cipher" = aes-128-cbc ]; then key=${key:0:16}; fi
    keyhex=$(printf '%s' "$key" | od -An -tx1 | tr -d ' \n')
    openssl rand 16 > "$OUT/iv"
    ivhex=$(od -An -tx1 "$OUT/iv" | tr -d ' \n')
    printf '{"notification":"%s","iv":"%s"}' \
        "$(printf '%s' "$plain" | openssl enc "-$cipher" -K "$keyhex" -iv "$ivhex" | base64 -w0)" \
        "$(base64 -w0 "$OUT/iv")"
}
order() { # order <type> <receipt> <click> <amount> [time]: an INS 8.0 order, the amount a string as 8.0 sends it
    printf '{"transactionTime":"%s","receipt":"%s","transactionType":"%s","vendor":"testacct",'\
'"affiliate":"affiliate1","role":"AFFILIATE","totalAccountAmount":"%s","paymentMethod":"VISA","currency":"USD",'\
'"trackingCodes":[%s],"customer":{"billing":{"fullName":"Test Buyer","email":"buyer-7pjv@example.com"}},'\
'"version":"8","attemptCount":1}' "${5:-$(TZ=America/Denver date +%Y-%m-%dT%H:%M:%S%:z)}" "$2" "$1" "$4" "$3"
}
post() { # post <body> -> HTTP status; the answer in $OUT/r
    curl -sS -o "$OUT/r" -w '%{http_code}' -H 'Content-Type: application/json' --data-binary "$1" \
        "$BASE/tracking202/static/cb202.php"
}
cb() { # cb <secret> <type> <receipt> <click or ""> <amount>
    local click="$4"
    [ -n "$click" ] && click="\"$click\""
    post "$(note "$1" "$(order "$2" "$3" "$click" "$5")")"
}

rows() { Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$1"; }
row() { Q "SELECT CONCAT_WS(' ', user_id, transaction_id, click_payout, source, pixel_type, IFNULL(reverses_conv_id,'-'), deleted) FROM 202_conversion_logs WHERE click_id=$1 AND transaction_id='$2' ORDER BY conv_id"; }

# --- two accounts, each with a click -------------------------------------
say "setup: a second account, and a campaign, a tracker and a click in each"
RUN=$(date +%s)
CB_A="CBA${RUN: -9}00"; CB_B="CBB${RUN: -9}00"
USER_B=$(api "$ADMIN_KEY" POST /users "{\"user_name\":\"cb$RUN\",\"user_email\":\"cb$RUN@example.com\",\"user_pass\":\"pass-$RUN-cb\"}" | jq -r '.data.user_id')
[ -n "$USER_B" ] && [ "$USER_B" != null ] || { echo "could not create the second account" >&2; exit 2; }
api "$ADMIN_KEY" POST "/users/$USER_B/roles" '{"role_id":2}' > /dev/null
KEY_B=$(api "$ADMIN_KEY" POST "/users/$USER_B/api-keys" '{}' | jq -r '.data.api_key // .data.key')
[ -n "$KEY_B" ] && [ "$KEY_B" != null ] || { echo "could not mint the second account's API key" >&2; exit 2; }
HOSTPORT=${BASE#*://}

click_for() { # click_for <api key> <user id> -> the click id the tracker's redirect hands ClickBank
    local key="$1" user="$2" net camp ppcn ppca trk url loc
    api "$key" PUT "/users/$user/preferences" "{\"user_tracking_domain\":\"$HOSTPORT\"}" > /dev/null
    net=$(api "$key" POST /aff-networks "{\"aff_network_name\":\"ClickBank $RUN\"}" | jq -r '.data.aff_network_id')
    camp=$(api "$key" POST /campaigns "{\"aff_campaign_name\":\"CB $user $RUN\",\"aff_campaign_url\":\"https://acct$user.vendor.hop.clickbank.net/?tid=[[subid]]\",\"aff_campaign_payout\":30,\"aff_network_id\":$net}" | jq -r '.data.aff_campaign_id')
    ppcn=$(api "$key" POST /ppc-networks "{\"ppc_network_name\":\"Source $RUN\"}" | jq -r '.data.ppc_network_id')
    ppca=$(api "$key" POST /ppc-accounts "{\"ppc_account_name\":\"Account $RUN\",\"ppc_network_id\":$ppcn}" | jq -r '.data.ppc_account_id')
    trk=$(api "$key" POST /trackers "{\"aff_campaign_id\":$camp,\"ppc_account_id\":$ppca}" | jq -r '.data.tracker_id')
    url=$(api "$key" GET "/trackers/$trk/url" | jq -r '.data.direct_url')
    loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -A 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0' "$url")
    printf '%s' "$loc" | sed -n 's/.*[?&]tid=\([0-9][0-9]*\).*/\1/p'
}
CLICK_A=$(click_for "$ADMIN_KEY" 1)
CLICK_B=$(click_for "$KEY_B" "$USER_B")
[ -n "$CLICK_A" ] && [ -n "$CLICK_B" ] || { echo "a tracker did not redirect to the HopLink with tid=<click>" >&2; exit 2; }
eq "$(Q "SELECT user_id FROM 202_clicks WHERE click_id=$CLICK_A LIMIT 1")" 1 "click $CLICK_A is account 1's"
eq "$(Q "SELECT user_id FROM 202_clicks WHERE click_id=$CLICK_B LIMIT 1")" "$USER_B" "click $CLICK_B is account $USER_B's"
api "$ADMIN_KEY" PUT /users/1/preferences "{\"cb_key\":\"$CB_A\"}" > /dev/null
api "$KEY_B" PUT "/users/$USER_B/preferences" "{\"cb_key\":\"$CB_B\"}" > /dev/null
eq "$(Q "SELECT cb_key FROM 202_users_pref WHERE user_id=$USER_B")" "$CB_B" "account $USER_B stores its own ClickBank key"

# --- 7PJV: the account is the one whose key decrypts --------------------
say "each account's sale is recorded with its own key"
eq "$(cb "$CB_A" SALE "RA$RUN" "$CLICK_A" 37.62)" 200 "account 1's sale answers 200"
has "$OUT/r" '"outcome":"recorded"' "and says recorded"
eq "$(row "$CLICK_A" "RA$RUN")" "1 RA$RUN 37.62000 clickbank 2 - 0" "one row for account 1, under the receipt, at the amount, source clickbank"
eq "$(cb "$CB_B" SALE "RB$RUN" "$CLICK_B" 12.50)" 200 "account $USER_B's sale answers 200"
eq "$(row "$CLICK_B" "RB$RUN")" "$USER_B RB$RUN 12.50000 clickbank 2 - 0" "one row for account $USER_B"

say "one account's key never records on another's click"
eq "$(cb "$CB_A" SALE "RX$RUN" "$CLICK_B" 5.00)" 404 "account 1's key on account $USER_B's click is a 404"
has "$OUT/r" 'There is no click' "naming no click"
eq "$(cb "$CB_B" SALE "RY$RUN" "$CLICK_A" 5.00)" 404 "and the other way round"
eq "$(rows "$CLICK_A"),$(rows "$CLICK_B")" "1,1" "neither wrote a row"

say "a test marks only that account's key verified"
eq "$(cb "$CB_B" TEST '********' '' 0)" 200 "account $USER_B's TEST answers 200"
has "$OUT/r" '"outcome":"test"' "as a test"
eq "$(api "$KEY_B" GET /system/integrations | jq -r '.data.integrations[] | select(.integration=="clickbank") | .verified')" true "account $USER_B's integrations read verified"
eq "$(api "$ADMIN_KEY" GET /system/integrations | jq -r '.data.integrations[] | select(.integration=="clickbank") | .verified')" false "account 1's do not"

say "replays and refusals"
eq "$(cb "$CB_A" SALE "RA$RUN" "$CLICK_A" 37.62)" 200 "the same sale again answers 200"
has "$OUT/r" '"outcome":"duplicate"' "as a duplicate"
eq "$(cb "$CB_A" SALE "RA$RUN" "$CLICK_A" 40.00)" 409 "the same receipt stating another amount is a 409"
eq "$(rows "$CLICK_A")" 1 "and neither added a row"
eq "$(cb NOBODYSTORESTHIS SALE "RZ$RUN" "$CLICK_A" 5.00)" 403 "a key nobody stores is a 403"
eq "$(post "$(note "$CB_A" "$(order SALE "RQ$RUN" "\"$CLICK_A\"" 5.00)" aes-128-cbc)")" 403 "the AES-128 cb202.php decrypted with is a 403"
eq "$(cb "$CB_A" SALE "RN$RUN" '' 5.00)" 200 "a sale with no tracking code answers 200"
has "$OUT/r" '"outcome":"no_click"' "as no_click"
eq "$(cb "$CB_A" SALE "RF$RUN" "$CLICK_A.9" 5.00)" 400 "a tracking code that is not a click id is a 400"
eq "$(curl -sS -o "$OUT/r" -w '%{http_code}' --data 'ctransaction=SALE&ctransreceipt=X&cverify=1A2B3C4D' "$BASE/tracking202/static/cb202.php")" 415 "a legacy cverify form is a 415"
has "$OUT/r" 'version to 8.0' "saying which version to choose"
eq "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/cb202.php")" 405 "a GET is a 405"
eq "$(rows "$CLICK_A")" 1 "none of them wrote a row"

say "a key two accounts store is refused"
api "$KEY_B" PUT "/users/$USER_B/preferences" "{\"cb_key\":\"$CB_A\"}" > /dev/null
eq "$(cb "$CB_A" SALE "RS$RUN" "$CLICK_A" 5.00)" 409 "a sale decrypted by a key two accounts store is a 409"
has "$OUT/r" '"outcome":"ambiguous_account"' "as ambiguous"
eq "$(rows "$CLICK_A")" 1 "and recorded nothing"
api "$KEY_B" PUT "/users/$USER_B/preferences" "{\"cb_key\":\"$CB_B\"}" > /dev/null
eq "$(cb "$CB_A" SALE "RS$RUN" "$CLICK_A" 5.00)" 200 "once the key is one account's again, the same notification records"

say "a sale is dated when ClickBank says it happened"
CT=$(Q "SELECT click_time FROM 202_clicks WHERE click_id=$CLICK_A ORDER BY click_time LIMIT 1")
sleep 2
AT=$(( $(date +%s) - 1 ))
STATED=$(TZ=America/Denver date -d "@$AT" +%Y-%m-%dT%H:%M:%S%:z)
eq "$(post "$(note "$CB_A" "$(order SALE "RD$RUN" "\"$CLICK_A\"" 1.00 "$STATED")")")" 200 "a sale stating $STATED, after its click at $CT, answers 200"
eq "$(Q "SELECT conv_time FROM 202_conversion_logs WHERE click_id=$CLICK_A AND transaction_id='RD$RUN'")" "$AT" "and is dated then, read with its offset"
eq "$(post "$(note "$CB_A" "$(order SALE "RE$RUN" "\"$CLICK_A\"" 1.00 2026-13-01T00:00:00-06:00)")")" 400 "a time that names no moment is a 400"
has "$OUT/r" 'transactionTime is' "naming the field"

say "server log"
if [ -n "${P202_SERVER_LOG:-}" ] && [ -f "$P202_SERVER_LOG" ]; then
    grep 'ClickBank notification' "$P202_SERVER_LOG" > "$OUT/log" || true
    has "$OUT/log" "recorded (200), SALE (sale), click $CLICK_B, transaction RB$RUN, account $USER_B" "the sale is one line naming its account"
    has "$OUT/log" "accounts 1, $USER_B store the secret key it decrypts with" "the ambiguous key names both accounts"
    if grep -qF -e "$CB_A" -e "$CB_B" -e 'buyer-7pjv@example.com' -e 'Test Buyer' "$OUT/log"; then
        bad "the log holds a key or the buyer's details"
    else
        ok "the log holds no key and none of the buyer's details"
    fi
    if grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' "$P202_SERVER_LOG" | grep -q 'cb202\|PaymentNotification'; then
        bad "PHP warnings from cb202.php"
    else
        ok "no PHP warnings, notices or fatals from cb202.php"
    fi
else
    echo "  (P202_SERVER_LOG not set: log checks skipped)"
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
