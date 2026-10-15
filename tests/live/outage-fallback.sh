#!/bin/bash
# Live pass for the click path's MySQL-outage fallback: with the database out
# of reach, dl.php, lp.php and off.php send the visitor to the URL they kept
# in memcache for the campaign while it was up, and a link with nothing kept
# answers the unavailable page.
#
# The connection used to be made unguarded under MYSQLI_REPORT_STRICT, so a
# refused connect was an uncaught exception: every request a 500, the
# fallback never reached (measured, 5 of 6 below failing).
#
# It needs a server whose 202-config.php reaches MySQL through something it
# can take away without stopping MySQL for anyone else, and memcached
# running. A forwarder does both:
#
#   socat TCP-LISTEN:3399,fork,reuseaddr,bind=127.0.0.1 TCP:127.0.0.1:3306 &
#   echo $! > /tmp/outage-socat.pid
#   # 202-config.php: $dbhost = $dbhostro = '127.0.0.1:3399'
#   P202_BASE=http://127.0.0.1:8131 P202_DB=p202_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   P202_OUTAGE='kill $(cat /tmp/outage-socat.pid)' P202_OUTAGE_PORT=3399 \
#   bash tests/live/outage-fallback.sh
#
# Additive: it creates its own records (named "outage <time>") and fires
# real clicks while the database is up. The outage lasts until the
# forwarder is started again.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
OUTAGE=${P202_OUTAGE:-}
OUTAGE_PORT=${P202_OUTAGE_PORT:-}

if [ -z "$P202_API_KEY" ] || [ -z "$OUTAGE" ] || [ -z "$OUTAGE_PORT" ]; then
    echo "P202_API_KEY, P202_OUTAGE and P202_OUTAGE_PORT must be set (see the header)." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
PASS=0; FAIL=0
ok()  { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
api() {
    curl -s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY" \
        ${3:+-H 'Content-Type: application/json' --data "$3"} "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print($1)" "$OUT/body"; }
must() { case "$1" in 2??) ;; *) echo "setup failed: $2 ($1) $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac; }
hit() { curl -s -o "$OUT/page" -w '%{http_code} %{redirect_url}' "$BASE$1"; }
expect() { # what it answered, the pattern it must match, what it says
    case "$1" in $2) ok "$3" ;; *) bad "$3 (got $1 $(head -c 200 "$OUT/page"))" ;; esac
}

T=$(date +%s%N | cut -c1-13)
U="https://outage.example/$T"
say "setup: a campaign, a tracker and a landing page through the API"
must "$(api POST /aff-networks "{\"aff_network_name\":\"outage $T\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"outage $T\",\"aff_campaign_url\":\"$U/offer?s=[[subid]]&c1=[[c1]]\",\"aff_campaign_payout\":\"1\",\"aff_network_id\":$NET}")" "campaign"
CA=$(field "d['data']['aff_campaign_id']")
must "$(api POST /ppc-networks "{\"ppc_network_name\":\"outage $T\"}")" "traffic source"
TS=$(field "d['data']['ppc_network_id']")
must "$(api POST /ppc-accounts "{\"ppc_network_id\":$TS,\"ppc_account_name\":\"outage $T\"}")" "traffic source account"
TSA=$(field "d['data']['ppc_account_id']")
must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"ppc_account_id\":$TSA,\"click_cpc\":\"0.1\"}")" "tracker"
TD=$(field "d['data']['tracker_id_public']")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/lp\",\"landing_page_nickname\":\"outage $T\",\"aff_campaign_id\":$CA}")" "landing page"
LPS=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id=$(field "d['data']['landing_page_id']")")
ACIP=$(Q "SELECT aff_campaign_id_public FROM 202_aff_campaigns WHERE aff_campaign_id=$CA")

say "with the database up, each redirect keeps its campaign's URL"
expect "$(hit "/tracking202/redirect/dl.php?t202id=$TD&c1=one")" "302*$U/offer*" "dl.php redirects"
hit "/tracking202/redirect/lp.php?lpip=$LPS" >/dev/null
hit "/tracking202/redirect/off.php?acip=$ACIP" >/dev/null

say "the database out of reach"
bash -c "$OUTAGE"
sleep 1
if mysql "${MYSQL_ARGS[@]}" -h 127.0.0.1 -P "$OUTAGE_PORT" -e 'SELECT 1' >/dev/null 2>&1; then
    echo "the outage did not land: port $OUTAGE_PORT still answers" >&2
    exit 2
fi
expect "$(hit "/tracking202/redirect/dl.php?t202id=$TD&c1=two")" "302*$U/offer?s=p202&c1=two*" \
    "dl.php sends the visitor to the kept URL, c1 as sent"
expect "$(hit "/tracking202/redirect/lp.php?lpip=$LPS")" "302*$U/offer?s=p202*" "lp.php sends the visitor to the kept URL"
expect "$(hit "/tracking202/redirect/off.php?acip=$ACIP")" "302*$U/offer?s=p202*" "off.php sends the visitor to the kept URL"
expect "$(hit "/tracking202/redirect/dl.php?t202id=9$TD")" "503*" "a tracker with nothing kept is the unavailable page"
expect "$(hit "/tracking202/redirect/cl.php?pci=123")" "[23]0[02]*" "cl.php answers without a connection"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
rm -rf "$OUT"
[ "$FAIL" -eq 0 ]
