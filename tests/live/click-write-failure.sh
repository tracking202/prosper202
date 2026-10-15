#!/bin/bash
# Live pass for a click the database refuses to store: a direct link
# (dl.php), a redirector link (rtr.php) and a landing page's script
# (record_simple.php, record_adv.php) each write the click in one
# transaction, and a failure there is the database error page, recorded in
# 202_mysql_errors under "recording ... click <id>" with the server's words,
# and no row of the click stored. HeldCacheWritesTest reads the shape of
# these calls; this runs them.
#
# A trigger refuses the 202_clicks rows of the records this pass makes (its
# own campaign and landing pages), so other clicks are untouched; it is
# dropped on exit. Needs a user that may create triggers.
#
#   P202_BASE=http://127.0.0.1:8131 P202_DB=p202_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/click-write-failure.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY must be set (see the header)." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
TRIGGER=p202_click_write_failure_pass
cleanup() { Q "DROP TRIGGER IF EXISTS $TRIGGER" >/dev/null 2>&1; rm -rf "$OUT"; }
trap cleanup EXIT

PASS=0; FAIL=0
ok()  { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()  { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
api() {
    curl -s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY" \
        ${3:+-H 'Content-Type: application/json' --data "$3"} "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print($1)" "$OUT/body"; }
must() { case "$1" in 2??) ;; *) echo "setup failed: $2 ($1) $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac; }
get() { curl -s -o "$OUT/page" -w '%{http_code}' "$BASE$1"; }

T=$(date +%s%N | cut -c1-13)
TAG="cwf $T"
U="https://cwf.example/$T"
say "setup: a campaign, its links and landing pages through the API ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG\",\"aff_campaign_url\":\"$U/offer?s=[[subid]]\",\"aff_campaign_payout\":\"1\",\"aff_network_id\":$NET}")" "campaign"
CA=$(field "d['data']['aff_campaign_id']")
must "$(api POST /ppc-networks "{\"ppc_network_name\":\"$TAG\"}")" "traffic source"
TS=$(field "d['data']['ppc_network_id']")
must "$(api POST /ppc-accounts "{\"ppc_network_id\":$TS,\"ppc_account_name\":\"$TAG\"}")" "traffic source account"
TSA=$(field "d['data']['ppc_account_id']")
must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"ppc_account_id\":$TSA,\"click_cpc\":\"0.1\"}")" "direct link"
TD=$(field "d['data']['tracker_id_public']")
must "$(api POST /rotators "{\"name\":\"$TAG\",\"default_campaign\":$CA}")" "redirector"
RT=$(field "d['data']['id']")
must "$(api POST /trackers "{\"aff_campaign_id\":0,\"rotator_id\":$RT,\"ppc_account_id\":$TSA,\"click_cpc\":\"0.1\"}")" "redirector link"
TR=$(field "d['data']['tracker_id_public']")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/simple\",\"landing_page_nickname\":\"$TAG simple\",\"aff_campaign_id\":$CA}")" "simple landing page"
LS=$(field "d['data']['landing_page_id']")
LPS=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id = $LS")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/adv\",\"landing_page_nickname\":\"$TAG adv\",\"aff_campaign_id\":$CA,\"landing_page_type\":1}")" "advanced landing page"
LA=$(field "d['data']['landing_page_id']")
LPA=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id = $LA")

say "the database refuses these records' clicks"
Q "DROP TRIGGER IF EXISTS $TRIGGER"
# The body holds semicolons: the client is told another delimiter.
mysql "${MYSQL_ARGS[@]}" -N --delimiter='//' "$DB" -e "CREATE TRIGGER $TRIGGER BEFORE INSERT ON 202_clicks FOR EACH ROW
   IF NEW.aff_campaign_id = $CA OR NEW.landing_page_id IN ($LS, $LA) THEN
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the click write failure pass';
   END IF" || { echo "could not create the trigger" >&2; exit 2; }

errors() { Q "SELECT COUNT(*) FROM 202_mysql_errors WHERE mysql_error_text LIKE '%refused by the click write failure pass%' AND mysql_error_sql LIKE 'recording%click%'"; }
clicks() { Q "SELECT COUNT(*) FROM 202_clicks WHERE aff_campaign_id = $CA OR landing_page_id IN ($LS, $LA)"; }

for path in "/tracking202/redirect/dl.php?t202id=$TD" "/tracking202/redirect/rtr.php?t202id=$TR" \
    "/tracking202/static/record_simple.php?lpip=$LPS" "/tracking202/static/record_adv.php?lpip=$LPA"; do
    script=${path%%\?*}
    script=${script##*/}
    before=$(errors)
    get "$path" >/dev/null
    if grep -q 'A database error has occurred, and it has been recorded' "$OUT/page"; then
        ok "$script answers the database error page, saying it was recorded"
    else
        bad "$script answers the database error page (got $(head -c 200 "$OUT/page"))"
    fi
    eq "$(errors)" "$((before + 1))" "$script records the error with the server's words, under the click"
    eq "$(clicks)" "0" "$script stores no row of the click"
    id=$(Q "SELECT mysql_error_sql FROM 202_mysql_errors ORDER BY mysql_error_id DESC LIMIT 1" | grep -oE '[0-9]+$')
    rows=0
    for table in 202_clicks_advance 202_clicks_record 202_clicks_site 202_clicks_tracking 202_clicks_spy; do
        rows=$((rows + $(Q "SELECT COUNT(*) FROM $table WHERE click_id = '${id:-0}'")))
    done
    eq "${id:+named}/$rows" "named/0" "and none of its other rows: the transaction rolled back"
done

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
