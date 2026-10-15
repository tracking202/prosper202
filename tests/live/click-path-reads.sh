#!/bin/bash
# Live pass for the click path's reads of records named by id: each request
# below names, through a link or a stored row, a record of another account,
# which must read as naming nothing. Rows like these were possible before
# ownership was checked on save, and stay in installs.
#
# What it pins, each of which was wrong:
#   - a direct link (dl.php) whose tracker names another account's campaign
#     sent visitors to that campaign and recorded the click under it; one
#     naming another account's text ad or traffic source account copied it
#     into the click;
#   - a redirector link (rtr.php) whose tracker names another account's
#     rotator sent the click through that rotator;
#   - a simple landing page on another account's campaign sent visitors to
#     it (lp.php, lpc.php) and recorded clicks under it (record_simple.php);
#   - a landing page's visit naming another account's tracker (t202id) took
#     its traffic source and cost into the click, and on an advanced landing
#     page its campaign and payout (record_adv.php);
#   - a traffic source account under another account's traffic source gave
#     a landing page that source's variables (get_custom_vars.php,
#     landing.php);
#   - a cloaked click's page (cl.php) used the first account's referer
#     setting for every account's click;
#   - an offer link with no click (off.php) sent nowhere for any account but
#     the first;
#   - lpc.php and off.php without a click turned no campaign's own URL
#     rotation;
#   - a public id with trailing letters (lpip=123abc) named record 123, on
#     record_simple.php, record.php, record_adv.php and pb.php;
#   - a click public id of 0, a click stored without one, named every such
#     click: pci.php marked one as gone, cl.php sent the visitor to one's URL.
#
# Additive: it creates its own records (named "cpr-pass <time>"), including
# rows of a second account written with SQL, and fires real clicks; it
# truncates nothing, but run it against a scratch instance, since the
# clicks stay.
#
#   P202_BASE=http://127.0.0.1:8098 P202_DB=p202_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/click-path-reads.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY must be set: this pass creates its records through the API." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else v)" "$OUT/body" 2>/dev/null; }
must() {
    case "$1" in 2??) ;; *) echo "$2 failed: HTTP $1 $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac
}

T=$(date +%s)
TAG="cpr-pass $T"
U="https://example.com/cpr-$T"
UA="cpr-pass-probe/1.0"
n=0
# get PATH [CURL ARGS...] — the Location it answers with, or "none"; the body in $OUT/page.
get() {
    local path=$1; shift
    n=$((n+1))
    local loc
    loc=$(curl -sS -o "$OUT/page" -w '%{redirect_url}' -A "$UA" -H "X-Forwarded-For: 198.51.100.$((n % 250 + 1))" "$@" "$BASE$path")
    echo "${loc:-none}"
}
ME=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$ME" ] || { echo "no account holds P202_API_KEY" >&2; exit 2; }
since() { Q "SELECT COUNT(*) FROM 202_clicks WHERE user_id = $ME AND click_time >= $T"; }
public() { Q "SELECT $2 FROM $1 WHERE $3"; }

say "setup: this account's records through the API ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG\",\"aff_campaign_url\":\"$U/offer?s=[[subid]]\",\"aff_campaign_payout\":\"3\",\"aff_network_id\":$NET}")" "campaign"
CA=$(field "d['data']['aff_campaign_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG rotating\",\"aff_campaign_url\":\"$U/r1\",\"aff_campaign_url_2\":\"$U/r2\",\"aff_campaign_rotate\":1,\"aff_campaign_payout\":\"1\",\"aff_network_id\":$NET}")" "rotating campaign"
CR=$(field "d['data']['aff_campaign_id']")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/simple\",\"landing_page_nickname\":\"$TAG simple\",\"aff_campaign_id\":$CA}")" "simple landing page"
LPS=$(public 202_landing_pages landing_page_id_public "landing_page_id=$(field "d['data']['landing_page_id']")")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/adv\",\"landing_page_nickname\":\"$TAG adv\",\"aff_campaign_id\":$CA,\"landing_page_type\":1}")" "advanced landing page"
LPA=$(public 202_landing_pages landing_page_id_public "landing_page_id=$(field "d['data']['landing_page_id']")")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/rotating\",\"landing_page_nickname\":\"$TAG rotating\",\"aff_campaign_id\":$CR}")" "rotating campaign's landing page"
LPR=$(public 202_landing_pages landing_page_id_public "landing_page_id=$(field "d['data']['landing_page_id']")")

say "setup: another account's records, and this account's rows naming them (SQL)"
OTHER=$(Q "SELECT user_id FROM 202_users WHERE user_id <> $ME AND user_deleted = 0 ORDER BY user_id LIMIT 1")
if [ -z "$OTHER" ]; then
    must "$(api POST /users "{\"user_name\":\"cpr$T\",\"user_email\":\"cpr$T@example.com\",\"user_pass\":\"cpr-pass-$T\"}")" "a second account"
    OTHER=$(field "d['data']['user_id']")
fi
Q "INSERT IGNORE INTO 202_users_pref (user_id) VALUES ($OTHER), ($ME)"
P=$((960000000 + (T % 1000000) * 10))
Q "INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout) VALUES ($P, $OTHER, $NET, '$TAG foreign', '$U/foreign', 99, $T, 0)"
FCA=$(public 202_aff_campaigns aff_campaign_id "aff_campaign_id_public=$P")
Q "INSERT INTO 202_rotators (public_id, user_id, name, default_url) VALUES ($((P + 1)), $OTHER, '$TAG foreign', '$U/foreign-rotator')"
FRT=$(public 202_rotators id "public_id=$((P + 1))")
Q "INSERT INTO 202_text_ads (user_id, aff_campaign_id, landing_page_id, text_ad_name, text_ad_headline, text_ad_description, text_ad_display_url, text_ad_time) VALUES ($OTHER, 0, 0, '$TAG', '', '', '', $T)"
FTA=$(Q "SELECT MAX(text_ad_id) FROM 202_text_ads WHERE user_id = $OTHER AND text_ad_name = '$TAG'")
Q "INSERT INTO 202_ppc_networks (user_id, ppc_network_name, ppc_network_time) VALUES ($OTHER, '$TAG', $T)"
FN=$(Q "SELECT MAX(ppc_network_id) FROM 202_ppc_networks WHERE user_id = $OTHER AND ppc_network_name = '$TAG'")
Q "INSERT INTO 202_ppc_network_variables (ppc_network_id, name, parameter, placeholder) VALUES ($FN, 'cprvar', 'cprvar', '')"
Q "INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ($OTHER, $FN, '$TAG', $T)"
FPA=$(Q "SELECT MAX(ppc_account_id) FROM 202_ppc_accounts WHERE user_id = $OTHER AND ppc_account_name = '$TAG'")
# This account's traffic source account under the other account's source.
Q "INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ($ME, $FN, '$TAG stray', $T)"
PX=$(Q "SELECT MAX(ppc_account_id) FROM 202_ppc_accounts WHERE user_id = $ME AND ppc_account_name = '$TAG stray'")
tracker() { # USER PUBLIC CAMPAIGN AD ACCOUNT ROTATOR
    Q "INSERT INTO 202_trackers (user_id, tracker_id_public, aff_campaign_id, text_ad_id, ppc_account_id, landing_page_id, rotator_id, click_cpc, click_cloaking, tracker_time) VALUES ($1, $2, $3, $4, $5, 0, $6, 0.11, 0, $T)"
}
FT=$((P + 2)); tracker "$OTHER" "$FT" "$FCA" "$FTA" "$FPA" 0
TX=$((P + 3)); tracker "$ME" "$TX" "$FCA" 0 0 0
TY=$((P + 4)); tracker "$ME" "$TY" "$CA" "$FTA" "$FPA" 0
TZ=$((P + 5)); tracker "$ME" "$TZ" 0 0 0 "$FRT"
TW=$((P + 6)); tracker "$ME" "$TW" "$CA" 0 "$PX" 0
LX=$((P + 7))
Q "INSERT INTO 202_landing_pages (user_id, landing_page_id_public, aff_campaign_id, landing_page_nickname, landing_page_url, landing_page_time) VALUES ($ME, $LX, $FCA, '$TAG stray', '$U/stray-lp', $T)"
if [ -z "$FCA" ] || [ -z "$FRT" ] || [ -z "$FTA" ] || [ -z "$FPA" ] || [ -z "$PX" ]; then
    echo "the other account's records were not stored" >&2
    exit 2
fi

say "a direct link (dl.php)"
before=$(since)
loc=$(get "/tracking202/redirect/dl.php?t202id=$TX")
case "$loc" in "$U/foreign"*) bad "a tracker naming another account's campaign sends no one there (got $loc)" ;; *) ok "a tracker naming another account's campaign sends no one there" ;; esac
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE user_id = $ME AND aff_campaign_id = $FCA AND click_time >= $T")" "0" \
    "and records no click under that campaign"
loc=$(get "/tracking202/redirect/dl.php?t202id=$TY")
case "$loc" in "$U/offer"*) ok "a tracker naming another account's text ad and source account still sends to its own campaign" ;; *) bad "own campaign (got $loc)" ;; esac
eq "$(Q "SELECT CONCAT(a.text_ad_id, '/', c.ppc_account_id) FROM 202_clicks c JOIN 202_clicks_advance a ON a.click_id = c.click_id WHERE c.user_id = $ME AND c.click_time >= $T AND c.aff_campaign_id = $CA ORDER BY c.click_id DESC LIMIT 1")" "0/0" \
    "and records neither of the other account's ids in the click"

say "a redirector link (rtr.php)"
before=$(since)
loc=$(get "/tracking202/redirect/rtr.php?t202id=$TZ")
eq "$loc" "none" "a tracker naming another account's rotator sends no one through it"
eq "$(since)" "$before" "and records no click"

say "a simple landing page on another account's campaign"
eq "$(get "/tracking202/redirect/lp.php?lpip=$LX")" "none" "lp.php sends no one to that campaign"
get "/tracking202/redirect/lpc.php?lpip=$LX" >/dev/null
eq "$(grep -c "$U/foreign" "$OUT/page")" "0" "lpc.php's page names no URL of that campaign"
before=$(since)
get "/tracking202/static/record_simple.php?lpip=$LX&t202kw=cpr" >/dev/null
eq "$(since)" "$before" "record_simple.php records no click under it"

say "a landing page's visit naming another account's tracker"
get "/tracking202/static/record_simple.php?lpip=$LPS&t202id=$FT&t202kw=cpr" >/dev/null
id=$(grep -oE 'var subid =[^;]*' "$OUT/page" | grep -oE '[0-9]+' | head -1)
eq "$(Q "SELECT CONCAT(c.aff_campaign_id, '/', c.ppc_account_id, '/', a.text_ad_id) FROM 202_clicks c JOIN 202_clicks_advance a ON a.click_id = c.click_id WHERE c.click_id = '${id:-0}'")" "$CA/0/0" \
    "a simple landing page keeps its campaign and takes none of the tracker's ids"
get "/tracking202/static/record_adv.php?lpip=$LPA&t202id=$FT&t202kw=cpr" >/dev/null
id=$(grep -oE 'var subid =[^;]*' "$OUT/page" | grep -oE '[0-9]+' | head -1)
eq "$(Q "SELECT CONCAT(aff_campaign_id, '/', click_payout, '/', ppc_account_id) FROM 202_clicks WHERE click_id = '${id:-0}'")" "0/0.00000/0" \
    "an advanced landing page's click is not recorded under that account's campaign or payout"

say "a traffic source account under another account's traffic source"
eq "$(curl -sS "$BASE/tracking202/static/get_custom_vars.php?t202id=$TW")" "[]" "get_custom_vars.php lists none of that source's variables"
eq "$(curl -sS "$BASE/tracking202/static/landing.php?t202id=$TW&lpip=$LPS" | grep -c cprvar)" "0" \
    "nor does a landing page's script"
eq "$(curl -sS "$BASE/tracking202/static/landing.php?t202id=$FT&lpip=$LPS" | grep -c cprvar)" "0" \
    "nor for another account's tracker"

say "a cloaked click's page (cl.php) takes its own account's referer setting"
Q "UPDATE 202_users_pref SET user_pref_cloak_referer = 'origin' WHERE user_id IN (1, $ME)"
Q "UPDATE 202_users_pref SET user_pref_cloak_referer = 'never' WHERE user_id = $OTHER"
FC=$(Q "INSERT INTO 202_clicks_counter SET click_id = DEFAULT; SELECT LAST_INSERT_ID()")
Q "INSERT INTO 202_site_urls (site_domain_id, site_url_address) VALUES (0, '$U/cloaked-$FC')"
FSU=$(Q "SELECT MAX(site_url_id) FROM 202_site_urls WHERE site_url_address = '$U/cloaked-$FC'")
Q "INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, ppc_account_id, click_cpc, click_time) VALUES ($FC, $OTHER, $FCA, 0, 0, $T); INSERT INTO 202_clicks_record (click_id, click_id_public, click_cloaking) VALUES ($FC, 7${FC}7, 1); INSERT INTO 202_clicks_site (click_id, click_redirect_site_url_id) VALUES ($FC, $FSU)"
get "/tracking202/redirect/cl.php?pci=7${FC}7" >/dev/null
eq "$(grep -oE 'name="referrer" content="[^"]*"' "$OUT/page")" 'name="referrer" content="never"' "the second account's click"
if grep -q "url=$U/cloaked-$FC" "$OUT/page"; then ok "and goes to its own URL"; else bad "and goes to its own URL"; fi

say "an offer link without a click (off.php) for an account other than the first"
loc=$(get "/tracking202/redirect/off.php?acip=$P")
case "$loc" in "$U/foreign"*) ok "sends to that account's offer" ;; *) bad "sends to that account's offer (got $loc)" ;; esac

say "a rotating campaign reached without a click turns its own rotation"
Q "DELETE FROM 202_rotations WHERE aff_campaign_id IN (0, $CR)"
get "/tracking202/redirect/lpc.php?lpip=$LPR" >/dev/null
get "/tracking202/redirect/lpc.php?lpip=$LPR" >/dev/null
eq "$(Q "SELECT COUNT(*) FROM 202_rotations WHERE aff_campaign_id = 0")" "0" "lpc.php turns no shared rotation"
eq "$(Q "SELECT COUNT(*) FROM 202_rotations WHERE aff_campaign_id = $CR")" "1" "and turns the campaign's own"
ACR=$(public 202_aff_campaigns aff_campaign_id_public "aff_campaign_id=$CR")
# A cookie that names no click: off.php's branch for a visit without one.
Q "DELETE FROM 202_rotations WHERE aff_campaign_id IN (0, $CR)"
loc=$(get "/tracking202/redirect/off.php?acip=$ACR" -b "tracking202subid=none")
case "$loc" in "$U/r"*) ok "off.php sends to the campaign" ;; *) bad "off.php sends to the campaign (got $loc)" ;; esac
eq "$(Q "SELECT COUNT(*) FROM 202_rotations WHERE aff_campaign_id = 0")" "0" "off.php turns no shared rotation"
eq "$(Q "SELECT COUNT(*) FROM 202_rotations WHERE aff_campaign_id = $CR")" "1" "and turns the campaign's own"

say "an offer link (off.php) whose visitor's last click has no public id"
# A direct link's click without cloaking has none (0). Found by the visitor's
# address, it names no click: 0 matched any of the account's clicks without
# one, and that click was moved into the offer's campaign.
VIA=198.51.100.251
curl -sS -o /dev/null -A "$UA" -H "X-Forwarded-For: $VIA" "$BASE/tracking202/redirect/dl.php?t202id=$TY"
moved=$(Q "SELECT COUNT(*) FROM 202_clicks WHERE user_id = $ME AND aff_campaign_id = $CR")
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -A "$UA" -H "X-Forwarded-For: $VIA" "$BASE/tracking202/redirect/off.php?acip=$ACR")
case "$loc" in "$U/r"*) ok "sends to the campaign" ;; *) bad "sends to the campaign (got ${loc:-none})" ;; esac
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE user_id = $ME AND aff_campaign_id = $CR")" "$moved" \
    "and moves none of the account's clicks into it"

say "a public id is read exactly"
before=$(since)
get "/tracking202/static/record_simple.php?lpip=${LPS}abc&t202kw=cpr" >/dev/null
eq "$(since)" "$before" "lpip=${LPS}abc names no landing page"
get "/tracking202/static/record.php?lpip=${LPA}abc&t202kw=cpr" >/dev/null
get "/tracking202/static/record_adv.php?lpip=${LPA}abc&t202kw=cpr" >/dev/null
eq "$(since)" "$before" "nor through record.php or record_adv.php"
get "/tracking202/static/record_simple.php?lpip=$LPS&t202kw=cpr" >/dev/null
eq "$(since)" "$((before + 1))" "while lpip=$LPS records its click"
id=$(grep -oE 'var subid =[^;]*' "$OUT/page" | grep -oE '[0-9]+' | head -1)
ACIP=$(public 202_aff_campaigns aff_campaign_id_public "aff_campaign_id=$CA")
curl -sS -o /dev/null "$BASE/tracking202/static/pb.php?acip=${ACIP}abc&subid=${id:-0}"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = '${id:-0}'")" "0" "pb.php?acip=${ACIP}abc names no campaign"
curl -sS -o /dev/null "$BASE/tracking202/static/pb.php?acip=$ACIP&subid=${id:-0}"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = '${id:-0}'")" "1" "while acip=$ACIP converts the click"

say "a public id of 0 names no click"
# A click without cloaking is stored with public id 0, its "none".
# pci.php went on to mark the first such click as gone and send the visitor
# to its URL; cl.php sent the visitor to the latest such click's URL.
out0=$(Q "SELECT COUNT(*) FROM 202_clicks_record WHERE click_id_public = 0 AND click_out = 1")
eq "$(get "/tracking202/redirect/pci.php?pci=0")" "none" "pci.php?pci=0 sends no one anywhere"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks_record WHERE click_id_public = 0 AND click_out = 1")" "$out0" \
    "and marks no click without a public id as gone"
loc=$(get "/tracking202/redirect/cl.php?pci=0")
case "$loc$(grep -oE 'url=[^"]*' "$OUT/page" | head -1)" in
    *202-404*) ok "cl.php?pci=0 is the not-found page" ;;
    *) bad "cl.php?pci=0 is the not-found page (got $loc $(grep -oE 'url=[^"]*' "$OUT/page" | head -1))" ;;
esac

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
rm -rf "$OUT"
[ "$FAIL" -eq 0 ]
