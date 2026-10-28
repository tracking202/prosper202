#!/bin/bash
# Live pass for how the redirects read a rule criterion's value
# (Prosper202\Rotator\Criterion): every spelling a writer stored of the value
# that matches every visitor sends a click to the rule, from a redirector link
# (rtr.php) and from a landing page's offer link (offrtr.php) alike.
#
# What it pins: rtr.php read `ALL` and `all` as every visitor and offrtr.php
# only `ALL`, so a rule stored as `all` (the API stored values as sent before
# 1.9.79, and the page stores what is typed) sent the same visitor to the
# rule from one entry point and to the default from the other. Neither read
# `All`, ` all ` or `United States(US), all` (the space kept on the value) as
# every visitor. Each spelling is written straight into the criterion row,
# as an older writer left it, since the writers now store ALL.
#
# Additive: it creates its own campaign, landing page and one redirector per
# spelling (named "cs-pass <time>") and fires real clicks; run it against a
# scratch instance.
#
#   P202_BASE=http://127.0.0.1:8116 P202_DB=p202_wg_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/rotator-criterion-spellings.sh

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
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else v)" "$OUT/body" 2>/dev/null; }
must() { # status what
    case "$1" in 2??) ;; *) echo "$2 failed: HTTP $1 $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac
}

CHROME='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
TAG="cs-pass $(date +%s)"
U="https://example.com/cs-$(date +%s)"

say "setup: a campaign and a landing page ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG\",\"aff_campaign_url\":\"$U/offer\",\"aff_campaign_payout\":\"3\",\"aff_network_id\":$NET}")" "campaign"
CA=$(field "d['data']['aff_campaign_id']")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/source\",\"landing_page_nickname\":\"$TAG source\",\"aff_campaign_id\":$CA}")" "landing page"
LPIP=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id=$(field "d['data']['landing_page_id']")")

n=0
# rtr URL — where a click through the redirector tracker goes (the last path segment).
rtr() {
    n=$((n+1))
    curl -sS -o /dev/null -w '%{redirect_url}' -A "$CHROME" -H "X-Forwarded-For: 198.51.100.$n" "$1&t202kw=cs-pass" | sed 's/[?].*//; s#.*/##'
}
# offrtr RPI — where a landing-page click out through the offer rotator goes.
offrtr() {
    n=$((n+1))
    local body subid
    body=$(curl -sS -A "$CHROME" -H "X-Forwarded-For: 198.51.100.$n" "$BASE/tracking202/static/record_adv.php?lpip=$LPIP&t202kw=cs-pass")
    subid=$(printf '%s' "$body" | grep -oE 'var subid =[^;]*' | grep -oE '[0-9]+' | head -1)
    [ -n "$subid" ] || { echo "record_adv.php recorded no click" >&2; exit 2; }
    curl -sS -o /dev/null -w '%{redirect_url}' -A "$CHROME" -H "X-Forwarded-For: 198.51.100.$n" \
      -b "tracking202subid=$subid" "$BASE/tracking202/redirect/offrtr.php?rpi=$1" | sed 's/[?].*//; s#.*/##'
}

# spelling NAME STATEMENT VALUE WANT [TYPE] — a redirector whose one rule (to
# $U/rule, default $U/default) has a TYPE (country) criterion stored as VALUE;
# a click through each entry point goes to WANT.
spelling() {
    must "$(api POST /rotators "{\"name\":\"$TAG $1\",\"default_url\":\"$U/default\"}")" "rotator $1"
    local rt rpi url stored
    rt=$(field "d['data']['id']")
    must "$(api POST "/rotators/$rt/rules" "{\"rule_name\":\"$1\",\"criteria\":[{\"type\":\"country\",\"statement\":\"is\",\"value\":\"ALL\"}],\"redirects\":[{\"redirect_url\":\"$U/rule\"}]}")" "rule $1"
    Q "UPDATE 202_rotator_rules_criteria SET type='${5:-country}', statement='$2', value='$3' WHERE rotator_id=$rt"
    stored=$(Q "SELECT CONCAT(statement, ' \"', value, '\"') FROM 202_rotator_rules_criteria WHERE rotator_id=$rt")
    eq "$stored" "$2 \"$3\"" "the criterion is stored as an older writer left it: $stored"
    rpi=$(Q "SELECT public_id FROM 202_rotators WHERE id=$rt")
    must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"rotator_id\":$rt}")" "tracker $1"
    must "$(api GET "/trackers/$(field "d['data']['tracker_id']")/url")" "tracker url $1"
    url=$(field "d['data']['direct_url']")
    eq "$(rtr "$url")" "$4" "rtr.php: $stored sends the visitor to the $4"
    eq "$(offrtr "$rpi")" "$4" "offrtr.php: $stored sends the visitor to the $4"
}

say "every spelling of every visitor, through both entry points"
spelling upper is 'ALL' rule
spelling lower is 'all' rule
spelling capital is 'All' rule
spelling spaced is ' all ' rule
spelling listed is 'United States(US), all' rule
spelling negated is_not 'all' rule
# ALL matches every visitor whatever the type, an IP criterion's included.
spelling ip is ' All ' rule ip

say "a value that only resembles ALL matches nobody"
spelling resembling is 'Mall' default

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" = 0 ]
