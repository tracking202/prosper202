#!/bin/bash
# Live pass for the Overview chart's writes (tracking202/ajax/charts.php):
# the resolution switch, which answers the chart, and the chart builder.
#
#   - the chart is the signed-in account's clicks: another account's clicks
#     today change nothing, the account's own add to it;
#   - a value the page never sends is refused by name (422), nothing stored;
#   - an account with no chart row (one the API creates) is given one, where
#     the switch answered 404 and the builder's save wrote nothing under 200;
#   - a role that is not shown campaign data is drawn no chart (403), as the
#     Overview leaves it out;
#   - a chart whose query fails answers 500 in the Overview's words, where the
#     exception went uncaught;
#   - its points are the window's days or hours (returnRanges()), no more.
#
# It creates two users through the API (named "oc-<time>"), writes rollup
# rows for them and for the signed-in account straight into 202_dataengine
# (marked by click ids from 990700000), renames 202_dataengine for one
# request, and puts all of it back.
#
#   P202_BASE=http://127.0.0.1:8127 P202_DB=p202_wn_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_USER=evalci P202_PASS=... \
#   P202_API_KEY=... bash tests/live/overview-chart.sh

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
    echo "P202_API_KEY and P202_PASS must be set: this pass creates users through the API and signs in as $P202_USER." >&2
    exit 2
fi
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3 ($(head -c 300 "$1"))"; fi; }

api() { # METHOD PATH [JSON-BODY] — body in $OUT/body, status printed
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else v)" "$OUT/body" 2>/dev/null; }

login() { # JAR USER PASS — signs in; the session token for AJAX posts lands in $OUT/token-<jar>
    local jar=$1
    rm -f "$jar"
    curl -sS -c "$jar" -b "$jar" "$BASE/202-login.php" -o "$OUT/login.html"
    local lt
    lt=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
    curl -sS -c "$jar" -b "$jar" -L "$BASE/202-login.php" --data-urlencode "token=$lt" \
        --data-urlencode "user_name=$2" --data-urlencode "user_pass=$3" -o "$OUT/after-login.html"
    curl -sS -c "$jar" -b "$jar" -L "$BASE/tracking202/overview/" -o "$OUT/overview.html"
    grep -oE 'var token = "[0-9a-f]+"' "$OUT/overview.html" | head -1 | sed -E 's/.*"([0-9a-f]+)"/\1/' > "$jar.token"
    [ -s "$jar.token" ]
}
chart() { # JAR OUT [curl args...] — POST to charts.php with the session's token; the status is printed
    local jar=$1 out=$2
    shift 2
    curl -sS -b "$jar" -c "$jar" -o "$out" -w '%{http_code}' --data-urlencode "token=$(cat "$jar.token")" "$@" "$BASE/tracking202/ajax/charts.php"
}
clicks_charted() { # FILE — the sum of the chart's "Clicks" line
    python3 -c "import json,sys; d=json.load(open(sys.argv[1])); s=[x for x in d['json']['series'] if x['name'].startswith('Clicks')]; print(int(sum(float(v or 0) for v in s[0]['data'])) if s else 'no clicks line')" "$1" 2>/dev/null
}

OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
STAMP=$(date +%s)
SAVED_CHART=$(Q "SELECT HEX(data), chart_time_range FROM 202_charts WHERE user_id=$OWNER LIMIT 1")
USERS=()
restore() {
    Q "DELETE FROM 202_dataengine WHERE click_id BETWEEN 990700000 AND 990700099"
    if [ -n "$(Q "SHOW TABLES LIKE '202_dataengine_held'")" ]; then
        Q "RENAME TABLE 202_dataengine_held TO 202_dataengine"
    fi
    if [ -n "$SAVED_CHART" ]; then
        read -r hex range <<< "$SAVED_CHART"
        Q "UPDATE 202_charts SET data=UNHEX('$hex'), chart_time_range='$range' WHERE user_id=$OWNER"
    fi
    for u in "${USERS[@]}"; do
        Q "DELETE FROM 202_charts WHERE user_id=$u; DELETE FROM 202_user_role WHERE user_id=$u"
        api DELETE "/users/$u" >/dev/null
    done
}
trap restore EXIT

login "$OUT/jar" "$P202_USER" "$P202_PASS" || { echo "could not sign in as $P202_USER" >&2; exit 2; }
Q "UPDATE 202_users_pref SET user_pref_time_predefined='today', user_pref_show='all' WHERE user_id=$OWNER"

say "the chart is the signed-in account's clicks"
code=$(chart "$OUT/jar" "$OUT/c0.json" --data-urlencode "chart_time_range=days")
eq "$code" "200" "the switch answers the chart"
BEFORE=$(clicks_charted "$OUT/c0.json")
# Today is one day: the points ran a day (or an hour) past the window.
eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(len(d['categories']), {len(s['data']) for s in d['json']['series']})" "$OUT/c0.json")" \
   "1 {1}" "today by day is one point, and every line has one value"
code=$(chart "$OUT/jar" "$OUT/c0h.json" --data-urlencode "chart_time_range=hours")
eq "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); c=d['categories']; print(c[0][-7:], c[-1][-7:])" "$OUT/c0h.json")" \
   "12:00AM 11:00PM" "today by hour runs from midnight to 11 pm, not to the next midnight"
# Another account's clicks, and then two of this account's, rolled up now.
for u in "oc-a-$STAMP" "oc-b-$STAMP"; do
    code=$(api POST /users "{\"user_name\":\"$u\",\"user_email\":\"$u@example.test\",\"user_pass\":\"ocPass$STAMP\"}")
    [ "$code" = "201" ] || { echo "creating $u answered $code: $(head -c 300 "$OUT/body")" >&2; exit 2; }
    USERS+=("$(field "d['data']['user_id']")")
done
OTHER=${USERS[0]}
NOROLE=${USERS[1]}
row() { echo "($1, $2, UNIX_TIMESTAMP() - 60, 0, 0, 1, 1, 0, 0, 0, 0)"; }
Q "INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, landing_page_id, clicks, click_out, leads, payout, income, cost)
   VALUES $(row "$OTHER" 990700001), $(row "$OTHER" 990700002), $(row "$OTHER" 990700003)"
chart "$OUT/jar" "$OUT/c1.json" --data-urlencode "chart_time_range=days" >/dev/null
eq "$(clicks_charted "$OUT/c1.json")" "$BEFORE" "another account's three clicks today change nothing"
Q "INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, landing_page_id, clicks, click_out, leads, payout, income, cost)
   VALUES $(row "$OWNER" 990700011), $(row "$OWNER" 990700012)"
chart "$OUT/jar" "$OUT/c2.json" --data-urlencode "chart_time_range=days" >/dev/null
eq "$(clicks_charted "$OUT/c2.json")" "$((BEFORE + 2))" "the account's own two add two"

say "a value the page never sends is refused by name"
STORED=$(Q "SELECT HEX(data), chart_time_range FROM 202_charts WHERE user_id=$OWNER")
code=$(chart "$OUT/jar" "$OUT/r1.json" --data-urlencode "chart_time_range=weeks")
eq "$code" "422" "a resolution other than hours or days is 422"
has "$OUT/r1.json" "chart_time_range must be one of: hours, days" "naming the field and what it takes"
code=$(chart "$OUT/jar" "$OUT/r2.json" --data-urlencode "levels[0][id]=0" --data-urlencode "types[0][type]=visits")
eq "$code" "422" "a figure the chart does not draw is 422"
has "$OUT/r2.json" "types[0][type]" "naming the line"
code=$(chart "$OUT/jar" "$OUT/r3.json" --data-urlencode "nothing=1")
eq "$code" "422" "a save with no lines is 422 (it was a TypeError)"
eq "$(Q "SELECT HEX(data), chart_time_range FROM 202_charts WHERE user_id=$OWNER")" "$STORED" "and nothing was stored"
code=$(chart "$OUT/jar" "$OUT/r4.json" --data-urlencode "levels[0][id]=0" --data-urlencode "types[0][type]=net" \
    --data-urlencode "levels[1][id]=0" --data-urlencode "types[1][type]=clicks")
eq "$code" "200" "two lines the page offers are saved"
eq "$(Q "SELECT data FROM 202_charts WHERE user_id=$OWNER")" \
   'a:2:{i:0;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:3:"net";}i:1;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:6:"clicks";}}' \
   "as the lines the Overview reads"

say "an account with no chart row is given one"
Q "INSERT INTO 202_user_role (user_id, role_id) VALUES ($OTHER, 4)"
eq "$(Q "SELECT COUNT(*) FROM 202_charts WHERE user_id=$OTHER")" "0" "(the API made the user with no chart row)"
login "$OUT/jar2" "oc-a-$STAMP" "ocPass$STAMP" || bad "the new user signs in"
code=$(chart "$OUT/jar2" "$OUT/n1.json" --data-urlencode "chart_time_range=hours")
eq "$code" "200" "the switch answers the chart (it was 404)"
eq "$(clicks_charted "$OUT/n1.json")" "3" "of that account's own three clicks"
eq "$(Q "SELECT chart_time_range FROM 202_charts WHERE user_id=$OTHER")" "hours" "and stores the resolution, with the default chart"
Q "DELETE FROM 202_charts WHERE user_id=$OTHER"
code=$(chart "$OUT/jar2" "$OUT/n2.json" --data-urlencode "levels[0][id]=0" --data-urlencode "types[0][type]=leads")
eq "$code" "200" "the builder's save is answered"
eq "$(Q "SELECT data FROM 202_charts WHERE user_id=$OTHER")" \
   'a:1:{i:0;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:5:"leads";}}' "and stored (it was an UPDATE of nothing)"

say "a role that is not shown campaign data is drawn no chart"
login "$OUT/jar3" "oc-b-$STAMP" "ocPass$STAMP" || bad "the role-less user signs in"
code=$(chart "$OUT/jar3" "$OUT/p1.json" --data-urlencode "chart_time_range=days")
eq "$code" "403" "the switch is 403"
has "$OUT/p1.json" "not shown campaign data" "saying why"
eq "$(Q "SELECT COUNT(*) FROM 202_charts WHERE user_id=$NOROLE")" "0" "and stores nothing"

say "a chart whose query fails says so"
Q "RENAME TABLE 202_dataengine TO 202_dataengine_held"
code=$(chart "$OUT/jar" "$OUT/f1.json" --data-urlencode "chart_time_range=days")
Q "RENAME TABLE 202_dataengine_held TO 202_dataengine"
eq "$code" "500" "the switch is 500"
has "$OUT/f1.json" "The chart could not be read; the server log says why. This is not a statement that there were no clicks." \
    "in the words the Overview uses in a chart's place"
eq "$(head -c 1 "$OUT/f1.json")" "{" "as JSON, which the page shows"

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" -eq 0 ]
