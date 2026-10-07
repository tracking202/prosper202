#!/bin/bash
# Live pass for Overview › Rotator Breakdown against every rotator entry
# point: clicks routed by a redirector tracker (rtr.php) and clicks a landing
# page sends through its offer rotator (record_adv.php, then offrtr.php), each
# matching a rule, another rule, or no rule (the default), are counted on the
# page by the rule that matched and agree with GET /rotators/{id}/stats.
#
# What it pins, each of which was wrong:
#   - a rule with several redirects is counted whole (the page counted
#     202_clicks.rule_id, which rtr.php fills with the redirect's id);
#   - an offer-rotator click is in its rotator's figures (offrtr.php never
#     wrote 202_clicks.rotator_id, which the page counted) and on its click
#     row (GET /clicks serves that column);
#   - an offer-rotator click no rule matched is the default, rule 0
#     (offrtr.php credited it to the last rule it tried);
#   - an offer-rotator click whose rule or default is a URL is in the API's
#     figures at once (only the campaign branch rolled the click up, so the
#     dataengine row had no rotator until the hourly job ran).
# The rules are told apart by browser (the click's User-Agent), so the pass
# does not depend on how a redirect reads the visitor's address.
#
# Additive: it creates its own campaign, landing page, rotators and trackers
# (named "rb-pass <time>") and fires real clicks, and truncates nothing — but
# run it against a scratch instance, since the clicks stay.
#
#   P202_BASE=http://127.0.0.1:8116 P202_DB=p202_wg_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_USER=evalci P202_PASS=... \
#   P202_API_KEY=... bash tests/live/rotator-breakdown.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}

if [ -z "$P202_API_KEY" ] || [ -z "$P202_PASS" ]; then
    echo "P202_API_KEY and P202_PASS must be set: this pass creates records through the API and reads the page as $P202_USER." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
OUT=$(mktemp -d)
JAR="$OUT/jar"
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

TAG="rb-pass $(date +%s)"
START=$(($(date +%s) - 5))

say "setup: a campaign, a landing page, two rotators and a redirector tracker ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG A\",\"aff_campaign_url\":\"https://example.com/rb-a\",\"aff_campaign_payout\":\"3\",\"aff_network_id\":$NET}")" "campaign A"
CA=$(field "d['data']['aff_campaign_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG B\",\"aff_campaign_url\":\"https://example.com/rb-b\",\"aff_campaign_payout\":\"2\",\"aff_network_id\":$NET}")" "campaign B"
CB=$(field "d['data']['aff_campaign_id']")
must "$(api POST /landing-pages "{\"landing_page_url\":\"https://example.com/rb-lp\",\"landing_page_nickname\":\"$TAG\",\"aff_campaign_id\":$CA}")" "landing page"
LP=$(field "d['data']['landing_page_id']")
LPIP=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id=$LP")

# Campaign rotator: rule A (Firefox) splits over three redirects, rule B
# (Chrome) has one, everything else is the default campaign.
must "$(api POST /rotators "{\"name\":\"$TAG campaigns\",\"default_campaign\":$CB}")" "rotator"
RC=$(field "d['data']['id']")
must "$(api POST "/rotators/$RC/rules" "{\"rule_name\":\"$TAG rule A\",\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Firefox\"}],\"redirects\":[{\"redirect_campaign\":$CA,\"weight\":34,\"name\":\"a1\"},{\"redirect_campaign\":$CB,\"weight\":33,\"name\":\"a2\"},{\"redirect_url\":\"https://example.com/rb-a3\",\"weight\":33,\"name\":\"a3\"}]}")" "rule A"
must "$(api POST "/rotators/$RC/rules" "{\"rule_name\":\"$TAG rule B\",\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Chrome\"}],\"redirects\":[{\"redirect_campaign\":$CB,\"weight\":100,\"name\":\"b1\"}]}")" "rule B"
RULE_A=$(Q "SELECT id FROM 202_rotator_rules WHERE rotator_id=$RC AND rule_name='$TAG rule A'")
RULE_B=$(Q "SELECT id FROM 202_rotator_rules WHERE rotator_id=$RC AND rule_name='$TAG rule B'")
RPI_C=$(Q "SELECT public_id FROM 202_rotators WHERE id=$RC")

# URL rotator: a rule and a default that send to URLs (offrtr.php's
# non-campaign branches).
must "$(api POST /rotators "{\"name\":\"$TAG urls\",\"default_url\":\"https://example.com/rb-default\"}")" "url rotator"
RU=$(field "d['data']['id']")
must "$(api POST "/rotators/$RU/rules" "{\"rule_name\":\"$TAG url rule\",\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Firefox\"}],\"redirects\":[{\"redirect_url\":\"https://example.com/rb-url\",\"weight\":100,\"name\":\"u1\"}]}")" "url rule"
RULE_U=$(Q "SELECT id FROM 202_rotator_rules WHERE rotator_id=$RU")
RPI_U=$(Q "SELECT public_id FROM 202_rotators WHERE id=$RU")

must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"rotator_id\":$RC}")" "tracker"
TR=$(field "d['data']['tracker_id']")
must "$(api GET "/trackers/$TR/url")" "tracker url"
RTR_URL=$(field "d['data']['direct_url']")
case "$RTR_URL" in *rtr.php*) ;; *) echo "the redirector tracker's URL is not rtr.php: $RTR_URL" >&2; exit 2 ;; esac

say "clicks"
n=0
rtr() { # ua
    n=$((n+1))
    curl -sS -o /dev/null -A "$1" -H "X-Forwarded-For: 198.51.100.$n" "$RTR_URL&t202kw=rb-pass"
}
offrtr() { # rpi ua — prints the click id
    n=$((n+1))
    local body subid
    body=$(curl -sS -A "$2" -H "X-Forwarded-For: 198.51.100.$n" "$BASE/tracking202/static/record_adv.php?lpip=$LPIP&t202kw=rb-pass")
    subid=$(printf '%s' "$body" | grep -oE 'var subid =[^;]*' | grep -oE '[0-9]+' | head -1)
    [ -n "$subid" ] || { echo "record_adv.php recorded no click" >&2; exit 2; }
    curl -sS -o /dev/null -A "$2" -H "X-Forwarded-For: 198.51.100.$n" -b "tracking202subid=$subid" "$BASE/tracking202/redirect/offrtr.php?rpi=$1"
    echo "$subid"
}
# Ten through rule A: all ten taking one of its three redirects is a 1 in
# 20,000 chance, and the first check below says so if it happens.
for _ in 1 2 3 4 5 6 7 8 9 10; do rtr "$FIREFOX"; done
for _ in 1 2; do rtr "$CHROME"; done
rtr "$SAFARI"
OFF_A1=$(offrtr "$RPI_C" "$FIREFOX")
OFF_A2=$(offrtr "$RPI_C" "$FIREFOX")
OFF_B=$(offrtr "$RPI_C" "$CHROME")
OFF_D=$(offrtr "$RPI_C" "$SAFARI")
OFF_U=$(offrtr "$RPI_U" "$FIREFOX")
OFF_UD=$(offrtr "$RPI_U" "$SAFARI")
OFFRTR="$OFF_A1,$OFF_A2,$OFF_B,$OFF_D,$OFF_U,$OFF_UD"
END=$(($(date +%s) + 5))

say "what the entry points recorded"
eq "$(Q "SELECT COUNT(DISTINCT cr.rule_redirect_id) > 1 FROM 202_clicks_rotator cr JOIN 202_clicks c USING (click_id) WHERE cr.rotator_id=$RC AND cr.rule_id=$RULE_A AND c.landing_page_id=0")" "1" \
  "rtr.php spread rule A's clicks over more than one redirect (else this pass proves nothing about rule ids)"
eq "$(Q "SELECT GROUP_CONCAT(c.rotator_id ORDER BY c.click_id) FROM 202_clicks c WHERE c.click_id IN ($OFFRTR)")" "$RC,$RC,$RC,$RC,$RU,$RU" \
  "every offer-rotator click names its rotator on its click row"
eq "$(Q "SELECT GROUP_CONCAT(rule_id ORDER BY click_id) FROM 202_clicks_rotator WHERE click_id IN ($OFFRTR)")" "$RULE_A,$RULE_A,$RULE_B,0,$RULE_U,0" \
  "offer-rotator clicks carry the rule that matched, and 0 when none did"
eq "$(Q "SELECT GROUP_CONCAT(IFNULL(rotator_id, 'none') ORDER BY click_id) FROM 202_dataengine WHERE click_id IN ($OFFRTR)")" "$RC,$RC,$RC,$RC,$RU,$RU" \
  "every offer-rotator click's rollup row has its rotator at once, URL branches included"

say "GET /clicks shows the offer-rotator clicks' rotator"
must "$(api GET "/clicks?time_from=$START&time_to=$END&limit=500")" "clicks"
eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); ids=set(sys.argv[2].split(',')); print(','.join(str(c['rotator_id']) for c in sorted(d['data'], key=lambda c: int(c['click_id'])) if str(c['click_id']) in ids))" "$OUT/body" "$OFFRTR")" "$RC,$RC,$RC,$RC,$RU,$RU" \
  "the API's click rows name the rotator"

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"

say "the page and GET /rotators/{id}/stats, rule by rule"
# The page's window is whole days; the API's the seconds this pass fired in.
# Both hold every click of these rotators, which this pass created.
FROM_DAY=$(date -u -d @$((START - 172800)) +%F); TO_DAY=$(date -u -d @$((END + 172800)) +%F)
VIEW=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote('range=custom&from=' + sys.argv[1] + '&to=' + sys.argv[2] + '&user_pref_show=all', safe=''))" "$FROM_DAY" "$TO_DAY")
code=$(curl -sS -c "$JAR" -b "$JAR" -o "$OUT/rotators.html" -w '%{http_code}' "$BASE/tracking202/ajax/sort_rotator.php?view=$VIEW")
eq "$code" "200" "the rotator breakdown answers"
python3 "$HERE/html-table.py" "$OUT/rotators.html" rotator-table > "$OUT/rows.tsv" || { bad "the page has no rotator table"; }

# page ROTATOR-NAME — "label=clicks" for the rotator's row, its rules and its default.
page() {
    python3 - "$OUT/rows.tsv" "$1" <<'PY'
import sys
rows = [l.rstrip('\n').split('\t') for l in open(sys.argv[1]) if not l.startswith('#')]
out, inside = [], False
for r in rows:
    label, clicks = r[1], r[2]
    if label == sys.argv[2]:
        inside = True
        out.append('totals=' + clicks)
        continue
    if inside:
        if label.startswith('Default'):
            out.append('default=' + clicks)
            break
        out.append(label.split(' Criteria and redirects')[0] + '=' + clicks)
print(';'.join(out))
PY
}
# apistats ROTATOR — the same shape from the API.
apistats() {
    must "$(api GET "/rotators/$1/stats?time_from=$START&time_to=$END")" "stats"
    python3 -c "
import json,sys
d=json.load(open(sys.argv[1]))['data']
out=['totals=%d' % d['totals']['total_clicks']]
out+=['%s=%d' % (r['rule_name'] if not r['deleted'] else 'Deleted rule', r['total_clicks']) for r in d['rules']]
out.append('default=%d' % d['default']['total_clicks'])
print(';'.join(out))" "$OUT/body"
}
eq "$(page "$TAG campaigns")" "totals=17;$TAG rule A=12;$TAG rule B=3;default=2" "the campaign rotator on the page: 10+2 rtr and 2+1 offrtr by rule, 1+1 default"
eq "$(apistats "$RC")" "totals=17;$TAG rule A=12;$TAG rule B=3;default=2" "the campaign rotator through the API, without the dataengine job"
eq "$(page "$TAG urls")" "totals=2;$TAG url rule=1;default=1" "the URL rotator on the page"
eq "$(apistats "$RU")" "totals=2;$TAG url rule=1;default=1" "the URL rotator through the API, without the dataengine job"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
