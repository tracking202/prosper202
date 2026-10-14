#!/bin/bash
# Live pass for where the offer rotator (offrtr.php, the "go to offer" hop a
# landing page makes) sends a click that matched a rule, against the same
# rotator's redirector entry point (rtr.php).
#
# What it pins, each of which was wrong:
#   - a rule's redirects take the click by their weights, as rtr.php splits
#     them (offrtr.php took the query's first row, so a redirect weighted 0
#     took every click and the others none);
#   - a rule that sends to a landing page sends to *that* landing page
#     (offrtr.php sent to the rotator default's landing page, or to an empty
#     Location when the default was not one);
#   - the click records the redirect it was sent to (the backfill wrote the
#     first row's redirect whatever the visitor got);
#   - a rule whose redirects are all weighted 0 sends to its first one from
#     both entry points (the shared chooser returned no redirect, so rtr.php
#     answered an empty page).
# The rules are told apart by browser (the click's User-Agent), so the pass
# does not depend on how a redirect reads the visitor's address.
#
# Additive: it creates its own campaign, landing pages, rotator and tracker
# (named "or-pass <time>") and fires real clicks, and truncates nothing — but
# run it against a scratch instance, since the clicks stay.
#
#   P202_BASE=http://127.0.0.1:8116 P202_DB=p202_wg_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/offer-rotator-routing.sh

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

# api METHOD PATH [JSON-BODY] — the body lands in $OUT/body; the status is printed.
api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# field EXPR — a value out of the last API body (d = the JSON).
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else v)" "$OUT/body" 2>/dev/null; }
must() { # status what
    case "$1" in 2??) ;; *) echo "$2 failed: HTTP $1 $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac
}

FIREFOX='Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0'
CHROME='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
SAFARI='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'
EDGE='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'
OTHER='or-pass-probe/1.0'

TAG="or-pass $(date +%s)"
U="https://example.com/or-$(date +%s)"

say "setup: a campaign, three landing pages, a rotator with four rules ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG\",\"aff_campaign_url\":\"$U/offer\",\"aff_campaign_payout\":\"3\",\"aff_network_id\":$NET}")" "campaign"
CA=$(field "d['data']['aff_campaign_id']")
# The page the clicks start on, the one the rules send to, and the default's.
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/source\",\"landing_page_nickname\":\"$TAG source\",\"aff_campaign_id\":$CA}")" "source landing page"
LPIP=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id=$(field "d['data']['landing_page_id']")")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/chosen\",\"landing_page_nickname\":\"$TAG chosen\",\"aff_campaign_id\":$CA}")" "rule landing page"
LP_RULE=$(field "d['data']['landing_page_id']")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/default\",\"landing_page_nickname\":\"$TAG default\",\"aff_campaign_id\":$CA}")" "default landing page"
LP_DEFAULT=$(field "d['data']['landing_page_id']")

must "$(api POST /rotators "{\"name\":\"$TAG\",\"default_lp\":$LP_DEFAULT}")" "rotator"
RT=$(field "d['data']['id']")
# Firefox: a redirect weighted 0 first, then the landing page with all the
# weight. Chrome: two URLs, even. Safari: the landing page, alone. Edge: two
# URLs, both weighted 0.
must "$(api POST "/rotators/$RT/rules" "{\"rule_name\":\"$TAG weighted\",\"splittest\":1,\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Firefox\"}],\"redirects\":[{\"redirect_url\":\"$U/zero\",\"weight\":0,\"name\":\"zero\"},{\"redirect_lp\":$LP_RULE,\"weight\":100,\"name\":\"all\"}]}")" "weighted rule"
must "$(api POST "/rotators/$RT/rules" "{\"rule_name\":\"$TAG even\",\"splittest\":1,\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Chrome\"}],\"redirects\":[{\"redirect_url\":\"$U/one\",\"weight\":50,\"name\":\"one\"},{\"redirect_url\":\"$U/two\",\"weight\":50,\"name\":\"two\"}]}")" "even rule"
must "$(api POST "/rotators/$RT/rules" "{\"rule_name\":\"$TAG lp\",\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Safari\"}],\"redirects\":[{\"redirect_lp\":$LP_RULE,\"weight\":100,\"name\":\"lp\"}]}")" "landing page rule"
must "$(api POST "/rotators/$RT/rules" "{\"rule_name\":\"$TAG unweighted\",\"splittest\":1,\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Edge\"}],\"redirects\":[{\"redirect_url\":\"$U/first\",\"weight\":0,\"name\":\"first\"},{\"redirect_url\":\"$U/second\",\"weight\":0,\"name\":\"second\"}]}")" "unweighted rule"
RPI=$(Q "SELECT public_id FROM 202_rotators WHERE id=$RT")
# redirect NAME — the id of this rotator's redirect of that name.
redirect() { Q "SELECT rr.id FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules r ON r.id = rr.rule_id WHERE r.rotator_id=$RT AND rr.name='$1'"; }
R_ZERO=$(redirect zero); R_ALL=$(redirect all); R_ONE=$(redirect one); R_TWO=$(redirect two); R_LP=$(redirect lp); R_FIRST=$(redirect first)
[ -n "$R_ZERO" ] && [ -n "$R_ALL" ] && [ -n "$R_ONE" ] && [ -n "$R_TWO" ] && [ -n "$R_LP" ] && [ -n "$R_FIRST" ] \
  || { echo "the rules' redirects were not stored as created" >&2; exit 2; }
eq "$(Q "SELECT GROUP_CONCAT(weight ORDER BY id) FROM 202_rotator_rules_redirects WHERE id IN ($R_ZERO, $R_ALL)")" "0,100" \
  "the weighted rule stored a 0 and a 100 (else what follows proves nothing about weights)"

must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"rotator_id\":$RT}")" "tracker"
TR=$(field "d['data']['tracker_id']")
must "$(api GET "/trackers/$TR/url")" "tracker url"
RTR_URL=$(field "d['data']['direct_url']")
case "$RTR_URL" in *rtr.php*) ;; *) echo "the redirector tracker's URL is not rtr.php: $RTR_URL" >&2; exit 2 ;; esac

# offrtr UA — a landing-page click out through the offer rotator; appends
# "click-id location" to $OUT/offrtr.
n=0
offrtr() {
    n=$((n+1))
    local body subid location
    body=$(curl -sS -A "$1" -H "X-Forwarded-For: 198.51.100.$n" "$BASE/tracking202/static/record_adv.php?lpip=$LPIP&t202kw=or-pass")
    subid=$(printf '%s' "$body" | grep -oE 'var subid =[^;]*' | grep -oE '[0-9]+' | head -1)
    [ -n "$subid" ] || { echo "record_adv.php recorded no click" >&2; exit 2; }
    location=$(curl -sS -o /dev/null -w '%{redirect_url}' -A "$1" -H "X-Forwarded-For: 198.51.100.$n" \
      -b "tracking202subid=$subid" "$BASE/tracking202/redirect/offrtr.php?rpi=$RPI")
    echo "$subid ${location:-none}" >> "$OUT/offrtr-$2"
}
# rtr UA — the same visitor through the redirector tracker; prints where it went.
rtr() {
    n=$((n+1))
    curl -sS -o /dev/null -w '%{redirect_url}\n' -A "$1" -H "X-Forwarded-For: 198.51.100.$n" "$RTR_URL&t202kw=or-pass"
}
# where NAME — the distinct destinations those clicks were sent to.
where() { cut -d' ' -f2 "$OUT/offrtr-$1" | sort -u | paste -sd' ' -; }
# recorded NAME — the distinct redirect ids those clicks recorded, as
# 202_clicks_rotator.rule_redirect_id/202_clicks.rule_id.
recorded() {
    local ids
    ids=$(cut -d' ' -f1 "$OUT/offrtr-$1" | paste -sd, -)
    Q "SELECT GROUP_CONCAT(DISTINCT CONCAT(cr.rule_redirect_id, '/', c.rule_id) ORDER BY cr.rule_redirect_id SEPARATOR ' ') FROM 202_clicks_rotator cr JOIN 202_clicks c USING (click_id) WHERE cr.click_id IN ($ids)"
}

say "a redirect weighted 0 takes none of its rule's clicks"
for _ in 1 2 3 4 5 6; do offrtr "$FIREFOX" weighted; done
eq "$(where weighted)" "$U/chosen" "six offer-rotator clicks all go to the redirect with the weight, a landing page"
eq "$(recorded weighted)" "$R_ALL/$R_ALL" "and each records that redirect, on its rotator row and its click row"
eq "$(for _ in 1 2 3; do rtr "$FIREFOX"; done | sed 's/[?&].*//' | sort -u | paste -sd' ' -)" "$U/chosen" \
  "the redirector tracker sends the same visitors to the same landing page"

say "an even rule splits the offer rotator's clicks"
# Sixteen clicks all taking one of two even redirects is a 1 in 32,768 chance.
for _ in $(seq 1 16); do offrtr "$CHROME" even; done
eq "$(where even)" "$U/one $U/two" "sixteen clicks reach both redirects"
mismatch=$(while read -r id location; do
    want=$R_ONE; [ "$location" = "$U/two" ] && want=$R_TWO
    got=$(Q "SELECT rule_redirect_id FROM 202_clicks_rotator WHERE click_id=$id")
    [ "$got" = "$want" ] || echo "$id:$location:$got"
done < "$OUT/offrtr-even")
eq "$mismatch" "" "each click records the redirect it was sent to"

say "a rule's landing page, not the default's"
for _ in 1 2; do offrtr "$SAFARI" lp; done
eq "$(where lp)" "$U/chosen" "a rule that sends to a landing page sends to that one"
eq "$(recorded lp)" "$R_LP/$R_LP" "and records its redirect"

say "a rule with no weight anywhere sends to its first redirect"
for _ in 1 2 3; do offrtr "$EDGE" unweighted; done
eq "$(where unweighted)" "$U/first" "the offer rotator sends to the rule's first redirect"
eq "$(recorded unweighted)" "$R_FIRST/$R_FIRST" "and records it"
eq "$(for _ in 1 2 3; do rtr "$EDGE"; done | sed 's/[?&].*//' | sort -u | paste -sd' ' -)" "$U/first" \
  "the redirector tracker sends there too"

say "no rule matched"
for _ in 1 2; do offrtr "$OTHER" default; done
eq "$(where default)" "$U/default" "a click no rule matched still goes to the default's landing page"
eq "$(recorded default)" "0/0" "and records no redirect"

# lpclick UA XFF — a landing-page click; prints "click-id public-id".
lpclick() {
    local body
    body=$(curl -sS -A "$1" -H "X-Forwarded-For: $2" "$BASE/tracking202/static/record_adv.php?lpip=$LPIP&t202kw=or-pass")
    printf '%s %s\n' "$(printf '%s' "$body" | grep -oE 'var subid =[^;]*' | grep -oE '[0-9]+' | head -1)" \
      "$(printf '%s' "$body" | grep -oE 'var pci = [^;]*' | grep -oE '[0-9]+' | head -1)"
}

say "off.php takes the click the request names, not the address's last one"
# Two clicks from one address; the visitor leaves with the first one's public
# id and no subid cookie (a visitor the privacy setting holds back has none).
# The address guess replaced the named click with the address's last one.
read -r A_ID A_PCI < <(lpclick "$OTHER" 203.0.113.91)
read -r B_ID _ < <(lpclick "$OTHER" 203.0.113.91)
[ -n "$A_PCI" ] && [ -n "$B_ID" ] || { echo "the landing page recorded no clicks" >&2; exit 2; }
ACIP=$(Q "SELECT aff_campaign_id_public FROM 202_aff_campaigns WHERE aff_campaign_id=$CA")
curl -sS -o /dev/null -A "$OTHER" -H "X-Forwarded-For: 203.0.113.91" "$BASE/tracking202/redirect/off.php?acip=$ACIP&pci=$A_PCI"
eq "$(Q "SELECT CONCAT(click_out, '/', (SELECT click_out FROM 202_clicks_record WHERE click_id=$B_ID)) FROM 202_clicks_record WHERE click_id=$A_ID")" "1/0" \
  "the named click goes out, and the address's last click does not"

say "another account's offer or rotator does not take a click"
# The offer (acip) or the rotator (rpi) and the click (pci, or the subid
# cookie) are named separately; each script then moves the click into that
# campaign or rotator. Another account's must not: a click id is a
# sequential number and a public id is one between two random digits.
OTHER_ACCOUNT=$(Q "SELECT user_id FROM 202_users WHERE user_id <> 1 AND user_deleted = 0 ORDER BY user_id LIMIT 1")
if [ -z "$OTHER_ACCOUNT" ]; then
    must "$(api POST /users "{\"user_name\":\"or$(date +%s)\",\"user_email\":\"or$(date +%s)@example.com\",\"user_pass\":\"or-pass-$(date +%s)\"}")" "a second account"
    OTHER_ACCOUNT=$(field "d['data']['user_id']")
fi
FOREIGN_ACIP=$((970000000 + RANDOM))
Q "INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_url_2, aff_campaign_url_3, aff_campaign_url_4, aff_campaign_url_5, aff_campaign_payout, aff_campaign_cloaking, aff_campaign_time, aff_campaign_rotate, aff_campaign_currency, aff_campaign_foreign_payout, attribution_model_id, payout_mode, identity_signals) SELECT $FOREIGN_ACIP, $OTHER_ACCOUNT, aff_network_id, '$TAG foreign', '$U/foreign', '', '', '', '', 99, 0, aff_campaign_time, 0, aff_campaign_currency, aff_campaign_foreign_payout, attribution_model_id, payout_mode, identity_signals FROM 202_aff_campaigns WHERE aff_campaign_id=$CA"
FOREIGN_CA=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$FOREIGN_ACIP")
FOREIGN_RPI=$((970000000 + RANDOM))
Q "INSERT INTO 202_rotators (public_id, user_id, name, default_url, default_campaign, default_lp, auto_monetizer) VALUES ($FOREIGN_RPI, $OTHER_ACCOUNT, '$TAG foreign', '$U/foreign-rotator', $FOREIGN_CA, NULL, NULL)"
FOREIGN_RT=$(Q "SELECT id FROM 202_rotators WHERE public_id=$FOREIGN_RPI")
[ -n "$FOREIGN_CA" ] && [ -n "$FOREIGN_RT" ] || { echo "the other account's campaign and rotator were not stored" >&2; exit 2; }
read -r C_ID C_PCI < <(lpclick "$OTHER" 203.0.113.92)
# An advanced landing page's click has no campaign until an offer is chosen.
C_BEFORE=$(Q "SELECT CONCAT(aff_campaign_id, '/', click_payout) FROM 202_clicks WHERE click_id=$C_ID")
location=$(curl -sS -o /dev/null -w '%{redirect_url}' -A "$OTHER" -H "X-Forwarded-For: 203.0.113.92" \
  "$BASE/tracking202/redirect/off.php?acip=$FOREIGN_ACIP&pci=$C_PCI")
eq "${location:-none}" "none" "off.php sends no one to another account's offer with this click"
location=$(curl -sS -o /dev/null -w '%{redirect_url}' -A "$OTHER" -H "X-Forwarded-For: 203.0.113.92" \
  -b "tracking202subid=$C_ID" "$BASE/tracking202/redirect/offrtr.php?rpi=$FOREIGN_RPI")
eq "${location:-none}" "none" "offrtr.php sends no one through another account's rotator with this click"
eq "$(Q "SELECT CONCAT(c.aff_campaign_id, '/', c.click_payout, ' ', c.rotator_id, '/', (SELECT COUNT(*) FROM 202_clicks_rotator WHERE click_id=$C_ID)) FROM 202_clicks c WHERE c.click_id=$C_ID")" "$C_BEFORE 0/0" \
  "and the click keeps its campaign and payout, with no rotator"
# The same click through this account's own offer still goes out (the
# refusal above is about the account, not the click).
curl -sS -o /dev/null -A "$OTHER" -H "X-Forwarded-For: 203.0.113.92" "$BASE/tracking202/redirect/off.php?acip=$ACIP&pci=$C_PCI"
eq "$(Q "SELECT click_out FROM 202_clicks_record WHERE click_id=$C_ID")" "1" "its own account's offer takes it"
Q "DELETE FROM 202_rotators WHERE id=$FOREIGN_RT; DELETE FROM 202_aff_campaigns WHERE aff_campaign_id=$FOREIGN_CA"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
