#!/bin/bash
# Live pass for the breakdown reads (PR 1b): a click's value explained row by
# row, and the reports that group income by what generated it. Several
# conversions are recorded on each click through the real paths — the global
# postback (several transaction ids), the REST API (a sale, a reversal, a
# soft delete), and goal outcomes through the goals engine (two paid goals
# and an unpaid one) — and then every read is checked against the ledger:
#
#   - GET /api/v3/clicks/{id}/conversions: every row, counted or not, with
#     the reason; the counted rows add up to the click's value; goals,
#     reversals and API keys are named; both scopes are required;
#   - GET /api/v3/conversions?click_id=&source=&goal=: the filters, the
#     provenance columns, and a bad filter refused by field;
#   - `p202 click conversions` and `p202 conversion list` (Go CLI), and
#     `click:conversions` / `conversion:list` (PHP CLI), from the same rows;
#   - the click history (Visitors): a row with conversions opens a breakdown
#     that shows the same rows and adds up to the click's value;
#   - Group Overview grouped by campaign then Transaction ID, and then Goal /
#     source: each ledger group sums its rows, a campaign's groups add up to
#     the campaign, and the campaign's figures are what they are without the
#     ledger level — drawn under the page's own view even after another tab
#     has changed the stored filters; and four levels deep, Goal / source
#     under Transaction ID, where every group at every depth adds up and the
#     page's download labels each row by both levels.
#
# Seeds its own campaigns (ids 950100-950101, fixed so that no report row a
# truncated campaigns table left behind can share one), goals and clicks
# (ids 950001-950003), and removes them at both ends, so it can run
# repeatedly. It truncates the goal tables,
# so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}
P202_BIN=${P202_BIN:-p202}
# The PHP CLI, as a command line (a partial vendor/ needs a prepend; see
# CLAUDE.md). Empty skips its checks, and the pass says so.
P202_PHP_CLI=${P202_PHP_CLI-"$PHP bin/p202"}

if [ -z "$P202_API_KEY" ] || [ -z "$P202_PASS" ]; then
    echo "P202_API_KEY and P202_PASS must be set: this pass drives the API as the admin and logs in as $P202_USER." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
OUT=$(mktemp -d)
JAR="$OUT/jar"
PASS=0; FAIL=0; SKIPPED=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
skip() { SKIPPED=$((SKIPPED+1)); printf '  \033[33mSKIP\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }

# api METHOD PATH [JSON-BODY] [KEY] — the body lands in $OUT/body; the status is printed.
api() {
    local key=${4-$P202_API_KEY}
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $key")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# pyf FILE EXPR — a value out of a JSON file (d = the JSON), printed as the pass compares it.
pyf() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$2; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$1" 2>/dev/null; }
field() { pyf "$OUT/body" "$1"; }
ingest() { # click events-json
    printf '{"user_id": %s, "click_id": %s, "events": %s}' "$OWNER" "$1" "$2" \
      | (cd "$ROOT" && "$PHP" tests/live/goals-ingest.php) > "$OUT/ingest" 2> "$OUT/ingest.err"
}
gpb() { curl -sS -o "$OUT/gpb.out" -w '%{http_code}' "$BASE/tracking202/static/gpb.php?$1"; }
get() { curl -sS -c "$JAR" -b "$JAR" -o "$2" -w '%{http_code}' "$BASE/$1"; }
value() { Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$1"; }
table() { python3 "$HERE/html-table.py" "$1" "$2"; }

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }
NOW=$(date +%s)
T=$((NOW - 600))
R=950001; A=950002; N=950003
CLICKS="$R,$A,$N"
ACIP_R=950100; ACIP_A=950101

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICKS));
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICKS);
DELETE FROM 202_dataengine WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_spy WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_tracking WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_record WHERE click_id IN ($CLICKS);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN ($ACIP_R, $ACIP_A) OR aff_campaign_id_public IN ($ACIP_R, $ACIP_A);
DELETE FROM 202_api_keys WHERE api_key LIKE 'breakdown-pass-%';
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
SQL
}
cleanup
trap cleanup EXIT

mysql_q "$DB" <<SQL
INSERT INTO 202_aff_campaigns (aff_campaign_id, aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode)
  VALUES ($ACIP_R, $ACIP_R, $OWNER, 0, 'breakdown replace', 'http://example.test/', 0, $NOW, 0, 'replace'),
         ($ACIP_A, $ACIP_A, $OWNER, 0, 'breakdown accumulate', 'http://example.test/', 0, $NOW, 0, 'accumulate');
SQL
CAMP_R=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_R")
CAMP_A=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_A")
[ -n "$CAMP_R" ] && [ -n "$CAMP_A" ] || { echo "seeding campaigns failed" >&2; exit 2; }
seed_click() { # id campaign
  mysql_q "$DB" -e "
    INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
      VALUES ($1, $OWNER, $2, 0, 0, 0.25, 0, 0, 0, 0, 0, $T, 0, 0);
    INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
      VALUES ($1, $OWNER, $2, 0, 0, 0.25, 0, 0, 0, 0, 0, $T);
    INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES ($1, 0, 0, 0, 0);
    INSERT INTO 202_clicks_record (click_id, click_id_public) VALUES ($1, $1);
    INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id, landing_page_id, clicks, click_out, leads, payout, income, cost)
      VALUES ($OWNER, $1, $T, 0, $2, 0, 1, 0, 0, 0, 0, 0.25);"
}
seed_click $R "$CAMP_R"
seed_click $A "$CAMP_A"
seed_click $N "$CAMP_R"
[ "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id IN ($CLICKS)")" = 3 ] || { echo "seeding clicks failed" >&2; exit 2; }

# ─────────────────────────────────────────────────────────────────────
say "record several conversions per click, through the real paths"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Install\",\"trigger\":{\"event\":\"first_open\"},\"value\":{\"type\":\"fixed\",\"amount\":\"1.00\"}}}")" 201 "a paid Install goal"
G_INSTALL=$(field "d['data']['goal_id']")
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Level 3\",\"trigger\":{\"event\":\"level_reached\"},\"value\":{\"type\":\"fixed\",\"amount\":\"4.00\"}}}")" 201 "a paid Level 3 goal"
G_LEVEL=$(field "d['data']['goal_id']")
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Tutorial\",\"trigger\":{\"event\":\"tutorial_complete\"},\"value\":{\"type\":\"none\"}}}")" 201 "a tracked, unpaid Tutorial goal"
G_TUT=$(field "d['data']['goal_id']")
ingest $A "[{\"event_id\":\"f1\",\"name\":\"first_open\",\"occurred_at\":$((T + 1))},{\"event_id\":\"t1\",\"name\":\"tutorial_complete\",\"occurred_at\":$((T + 2))},{\"event_id\":\"l1\",\"name\":\"level_reached\",\"occurred_at\":$((T + 3))}]"
eq "$(pyf "$OUT/ingest" "d['outcomes_written']")" 3 "three outcomes: install, tutorial, level 3"
eq "$(gpb "subid=$A&amount=5&txid=A-1")" 200 "postback A-1 \$5 on the accumulate click"
eq "$(gpb "subid=$A&amount=2&txid=A-2")" 200 "postback A-2 \$2"
eq "$(api POST /conversions "{\"click_id\":$A,\"transaction_id\":\"A-1\",\"status\":\"reversed\"}")" 201 "A-1 reversed through the API"
eq "$(gpb "subid=$R&amount=4&txid=R-1")" 200 "postback R-1 \$4 on the replace click"
eq "$(gpb "subid=$R&amount=6&txid=R-2")" 200 "postback R-2 \$6 replaces it"
eq "$(api POST /conversions "{\"click_id\":$R,\"transaction_id\":\"R-API\",\"payout\":\"3\"}")" 201 "an API sale R-API \$3 replaces that"
API_R=$(field "d['data']['conv_id']")
eq "$(field "d['data']['source']")" api "an API conversion says it came from the API"
eq "$(Q "SELECT source_ref FROM 202_conversion_logs WHERE conv_id=$API_R")" "apikey:$(printf '%s' "$P202_API_KEY" | sha256sum | cut -c1-16)" \
   "and names the key that wrote it by a digest, not by the key"
eq "$(api DELETE /conversions/$API_R)" 204 "then R-API is deleted"
eq "$(value $R)" "1/6.00000" "the replace click is worth R-2's \$6 again"
eq "$(value $A)" "1/7.00000" "the accumulate click is worth 1 + 4 + 5 + 2 - 5"
eq "$(value $N)" "0/0.00000" "the third click never converted"

# ─────────────────────────────────────────────────────────────────────
say "GET /clicks/{id}/conversions explains every row"
eq "$(api GET /clicks/$A/conversions)" 200 "the accumulate click's breakdown"
cp "$OUT/body" "$OUT/bd-a.json"
eq "$(field "len(d['data'])")" "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$A")" "every ledger row of the click is listed"
eq "$(field "[r['conv_id'] for r in d['data']]")" "$(Q "SELECT CONCAT('[', GROUP_CONCAT(conv_id ORDER BY conv_id SEPARATOR ', '), ']') FROM 202_conversion_logs WHERE click_id=$A")" "oldest first, by conversion id"
eq "$(field "[(r['source'], r['amount'], r['counted'], r['not_counted_reason']) for r in d['data']]")" \
   '[["goal", "1.00000", true, null], ["goal", "0.00000", false, "unpaid"], ["goal", "4.00000", true, null], ["postback", "5.00000", true, null], ["postback", "2.00000", true, null], ["api", "-5.00000", true, null]]' \
   "goals, the unpaid one left out, both sales and the reversal netting A-1"
eq "$(field "[r['linked_to']['label'] for r in d['data'] if r['source'] == 'goal']")" '["Goal \"Install\" v1", "Goal \"Tutorial\" v1", "Goal \"Level 3\" v1"]' "each goal row is named with its version"
eq "$(field "[r['event_name'] for r in d['data'] if r['source'] == 'goal']")" '["first_open", "tutorial_complete", "level_reached"]' "with the event that reached it"
eq "$(field "d['data'][5]['linked_to']['label']")" "Reverses conversion $(Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$A AND dedupe_key='tx:A-1'")" "the reversal names the sale it nets"
eq "$(field "d['data'][5]['transaction_id']")" "A-1" "and carries its transaction id"
eq "$(field "d['data'][1]['explanation']")" "Tracked, not paid: an outcome the campaign does not pay for, or an event recorded for visibility." "the unpaid row says why in a sentence"
eq "$(field "[d['click']['payout_mode'], d['click']['click_payout'], d['click']['ledger_value'], d['click']['matches_click'], d['click']['counted_rows'], d['click']['rows']]")" \
   '["accumulate", "7.00000", "7.00000", true, 5, 6]' "the click's value is what its counted rows add up to"
eq "$(field "str(sum(round(float(r['amount'])*100000) for r in d['data'] if r['counted']))")" \
   "$(Q "SELECT CAST(ROUND(click_payout*100000) AS SIGNED) FROM 202_clicks WHERE click_id=$A")" "summed here, the counted amounts are the click's value to the last digit"

eq "$(api GET /clicks/$R/conversions)" 200 "the replace click's breakdown"
cp "$OUT/body" "$OUT/bd-r.json"
eq "$(field "[(r['transaction_id'], r['counted'], r['not_counted_reason'], r['superseded_reason']) for r in d['data']]")" \
   '[["R-1", false, "superseded", "replace"], ["R-2", true, null, null], ["R-API", false, "deleted", null]]' \
   "R-1 superseded by R-2, R-API deleted, R-2 counts"
eq "$(field "d['data'][0]['superseded_by']")" "$(Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$R AND transaction_id='R-2'")" "R-1 names the row that replaced it"
eq "$(field "d['data'][2]['linked_to']['label']")" "API key created $(Q "SELECT DATE(FROM_UNIXTIME(created_at)) FROM 202_api_keys WHERE api_key='$P202_API_KEY'" | tr -d '\n')" "the API row names its key by when it was created"
hasnt "$OUT/body" "$P202_API_KEY" "and never by its value"
eq "$(api GET /clicks/$N/conversions)" 200 "a click with no conversions has an empty breakdown"
eq "$(field "[d['data'], d['click']['lead'], d['click']['ledger_value'], d['click']['matches_click']]")" '[[], false, null, true]' "not a lead, nothing to add up"
eq "$(api GET /clicks/999999999/conversions)" 404 "an unknown click is a 404"

say "the breakdown needs read scope on clicks and on conversions"
mysql_q "$DB" -e "INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES
  ($OWNER, 'breakdown-pass-clicks', 'clicks:read', $NOW), ($OWNER, 'breakdown-pass-both', 'clicks:read,conversions:read', $NOW),
  ($OWNER, 'breakdown-pass-conv', 'conversions:read', $NOW)"
eq "$(api GET /clicks/$A/conversions '' breakdown-pass-clicks)" 403 "a clicks:read key cannot read a click's conversions"
has "$OUT/body" "conversions:read" "and is told which scope it lacks"
eq "$(api GET /clicks/$A/conversions '' breakdown-pass-conv)" 403 "neither can a conversions:read key (the path is the clicks area)"
eq "$(api GET /clicks/$A/conversions '' breakdown-pass-both)" 200 "a key with both reads it"

# ─────────────────────────────────────────────────────────────────────
say "GET /conversions filters by click, source and goal, with provenance"
eq "$(api GET "/conversions?click_id=$A&limit=50")" 200 "?click_id"
eq "$(field "sorted(r['conv_id'] for r in d['data'])")" "$(Q "SELECT CONCAT('[', GROUP_CONCAT(conv_id ORDER BY conv_id SEPARATOR ', '), ']') FROM 202_conversion_logs WHERE click_id=$A AND deleted=0")" "exactly the click's live rows"
eq "$(api GET "/conversions?click_id=$A&source=goal")" 200 "?source=goal"
eq "$(field "sorted((r['goal_id'], r['goal_version'], r['payable']) for r in d['data'])")" "[[$G_INSTALL, 1, true], [$G_LEVEL, 1, true], [$G_TUT, 1, false]]" "the goal rows, with their goal, version and whether paid"
eq "$(api GET "/conversions?goal=$G_LEVEL")" 200 "?goal"
eq "$(field "[(r['click_id'], r['source_ref'], r['event_name']) for r in d['data']]")" "[[$A, \"goal:$G_LEVEL:1\", \"level_reached\"]]" "only that goal's outcomes"
eq "$(api GET "/conversions?click_id=$R&source=api")" 200 "?source=api on the replace click"
eq "$(field "d['pagination']['total']")" 0 "the deleted API row is not listed"
eq "$(api GET "/conversions?click_id=abc")" 422 "a click id that is not one is refused"
eq "$(field "list(d['field_errors'])")" '["click_id"]' "naming click_id"
eq "$(api GET "/conversions?source=webhook&goal=0")" 422 "an unknown source and a zero goal are refused"
eq "$(field "sorted(d['field_errors'])")" '["goal", "source"]' "naming both"
has "$OUT/body" "legacy_baseline" "the source refusal lists what is accepted"

# ─────────────────────────────────────────────────────────────────────
say "p202 click conversions and p202 conversion list (Go CLI)"
CLIHOME="$OUT/clihome"; mkdir -p "$CLIHOME"
p202() { HOME="$CLIHOME" "$P202_BIN" "$@"; }
p202 config set-url "$BASE" >/dev/null && p202 config set-key "$P202_API_KEY" >/dev/null
p202 click conversions $A > "$OUT/go-a.txt" 2> "$OUT/go-a.err"
eq "$?" 0 "p202 click conversions exits 0"
has "$OUT/go-a.txt" "unpaid" "the table names the unpaid row's reason"
has "$OUT/go-a.txt" 'Goal "Level 3" v1' "and the goal it came from"
has "$OUT/go-a.txt" "Click $A: 7.00000 (accumulate mode), 5 of 6 conversions counted." "and ends with the click's value"
p202 click conversions $R > "$OUT/go-r.txt" 2>&1
has "$OUT/go-r.txt" "superseded (replace)" "the superseded row says how"
has "$OUT/go-r.txt" "deleted" "the deleted row says so"
p202 --json click conversions $A > "$OUT/go-a.json" 2>/dev/null
eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1])) == json.load(open(sys.argv[2])))" "$OUT/go-a.json" "$OUT/bd-a.json")" True \
   "--json is the API's answer unchanged"
p202 --json conversion list --click_id $A --source goal > "$OUT/go-list.json" 2>/dev/null
eq "$(pyf "$OUT/go-list.json" "len(d['data'])")" 3 "conversion list --click_id --source sends both filters"
p202 --json click conversions 12x > /dev/null 2> "$OUT/go-bad.err"
eq "$?" 1 "a bad click id exits 1 (validation)"
eq "$(pyf "$OUT/go-bad.err" "[d['error']['category'], 'p202 click list' in d['error']['hint']]")" '["validation", true]' "with a validation envelope and a hint naming click list"
p202 --json click conversions 999999999 > /dev/null 2> "$OUT/go-404.err"
eq "$(pyf "$OUT/go-404.err" "[d['error']['http_status'], 'p202 click get 999999999' in d['error']['hint']]")" '[404, true]' "an unknown click is a 404 whose hint checks the id"

say "click:conversions and conversion:list (PHP CLI)"
if [ -z "$P202_PHP_CLI" ]; then
  skip "P202_PHP_CLI is empty: the PHP CLI was not exercised"
else
  PCLIHOME="$OUT/pclihome"; mkdir -p "$PCLIHOME"
  pcli() { (cd "$ROOT" && HOME="$PCLIHOME" $P202_PHP_CLI "$@"); }
  pcli config:set-url "$BASE" >/dev/null && pcli config:set-key "$P202_API_KEY" >/dev/null
  pcli click:conversions $A > "$OUT/php-a.txt" 2>&1
  eq "$?" 0 "click:conversions exits 0"
  has "$OUT/php-a.txt" "Click $A: 7.00000 (accumulate mode), 5 of 6 conversions counted." "and ends with the same line as the Go CLI"
  has "$OUT/php-a.txt" "unpaid" "with the same reasons"
  pcli click:conversions $A --json > "$OUT/php-a.json" 2>&1
  eq "$(python3 -c "import json,sys; print(json.load(open(sys.argv[1])) == json.load(open(sys.argv[2])))" "$OUT/php-a.json" "$OUT/bd-a.json")" True "--json is the API's answer unchanged"
  pcli conversion:list --click_id=$A --source=goal --json > "$OUT/php-list.json" 2>&1
  eq "$(pyf "$OUT/php-list.json" "len(d['data'])")" 3 "conversion:list --click_id --source sends both filters"
  pcli conversion:list --source=webhook > "$OUT/php-bad.txt" 2>&1
  eq "$?" 1 "an unknown source is refused before any request"
  has "$OUT/php-bad.txt" "--source must be one of pixel, postback" "naming what is accepted"
fi

# ─────────────────────────────────────────────────────────────────────
say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(python3 "$HERE/form-body.py" "$OUT/login.html" user_pass | tr '&' '\n' | sed -n 's/^token=//p')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "tracking202/visitors/?range=last7&user_pref_show=all" "$OUT/visitors.html" > /dev/null
hasnt "$OUT/visitors.html" 'name="user_pass"' "session established (no login form)"

say "the click history opens a click's breakdown"
FRAG=$(python3 -c "import html,re,sys; m=re.search(r'data-p202-report=\"([^\"]+)\"', open(sys.argv[1]).read()); print(html.unescape(m.group(1)) if m else '')" "$OUT/visitors.html")
[ -n "$FRAG" ] && ok "the Visitors page names its fragment" || bad "the Visitors page names its fragment"
curl -sS -c "$JAR" -b "$JAR" -o "$OUT/history.html" --data 'offset=0' "$(python3 -c "import sys,urllib.parse; print(urllib.parse.urljoin(sys.argv[1], sys.argv[2]))" "$BASE/" "$FRAG")"
python3 - "$OUT/history.html" "$A" "$R" "$N" > "$OUT/history-buttons.txt" <<'PY'
import re, sys
page = open(sys.argv[1]).read()
for click in sys.argv[2:]:
    row = re.search(r'<tr data-click-id="%s".*?</tr>' % click, page, re.S)
    if not row:
        print(click, 'absent'); continue
    b = re.search(r'<button[^>]*data-p202-breakdown="([^"]+)"[^>]*>([^<]*)</button>', row.group(0))
    print(click, b.group(2).strip() if b else 'no-button', b.group(1).replace('&amp;', '&') if b else '')
PY
eq "$(awk -v c=$A '$1==c{print $2" "$3}' "$OUT/history-buttons.txt")" "6 conversions" "the accumulate click's row offers its 6 conversions"
eq "$(awk -v c=$R '$1==c{print $2" "$3}' "$OUT/history-buttons.txt")" "3 conversions" "the replace click's row offers its 3 (deleted and superseded included)"
eq "$(awk -v c=$N '$1==c{print $2}' "$OUT/history-buttons.txt")" "no-button" "a click with none offers nothing"
has "$OUT/history.html" 'id="p202-click-conversions"' "the modal the button opens is drawn with the table"
BD_URL=$(awk -v c=$A '$1==c{print $4}' "$OUT/history-buttons.txt")
eq "$(get "${BD_URL#/}" "$OUT/ui-a.html")" 200 "the breakdown fragment answers"
table "$OUT/ui-a.html" click-conversions-table > "$OUT/ui-a.tsv"
eq "$(grep -c '^0	#' "$OUT/ui-a.tsv")" 6 "it lists all six conversions"
eq "$(awk -F'\t' '$2 ~ /^#/ {print $4 "|" substr($5,1,index($5" "," ")-1)}' "$OUT/ui-a.tsv" | tr '\n' ' ')" \
   '$1.00|counted $0.00|unpaid $4.00|counted $5.00|counted $2.00|counted ($-5.00)|counted ' "with the API's amounts and verdicts, in order"
eq "$(awk -F'\t' '$2 == "Counted toward the click" {print $4}' "$OUT/ui-a.tsv")" '$7.00' "and adds up to the click's value"
has "$OUT/ui-a.html" 'Goal &quot;Level 3&quot; v1' "each goal is named"
get "tracking202/ajax/click_conversions.php?click_id=$R" "$OUT/ui-r.html" > /dev/null
has "$OUT/ui-r.html" "superseded · replace" "the replace click's first sale says it was replaced"
has "$OUT/ui-r.html" "Replaced by conversion $(Q "SELECT conv_id FROM 202_conversion_logs WHERE click_id=$R AND transaction_id='R-2'")." "and by which conversion"
has "$OUT/ui-r.html" 'p202-pill p202-pill--bad">deleted' "the deleted API sale is marked deleted"
eq "$(get "tracking202/ajax/click_conversions.php?click_id=12x" "$OUT/ui-bad.html")" 400 "a click id that is not one is refused"
has "$OUT/ui-bad.html" "That is not a click id." "in words"
eq "$(get "tracking202/ajax/click_conversions.php?click_id=999999999" "$OUT/ui-404.html")" 404 "an unknown click is a 404"
has "$OUT/ui-404.html" "There is no click 999999999 in your account." "in words"
eq "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/ajax/click_conversions.php?click_id=$A")" 302 "without a session it redirects to sign-in"

# ─────────────────────────────────────────────────────────────────────
# group_overview POST as p202-overview.js does, under the page's own view.
overview() { # query-string out-prefix
  get "tracking202/overview/group-overview.php?$1" "$2.page.html" > /dev/null
  local frag
  frag=$(python3 -c "import html,re,sys; m=re.search(r'data-p202-report=\"([^\"]+)\"', open(sys.argv[1]).read()); print(html.unescape(m.group(1)) if m else '')" "$2.page.html")
  # Another tab changes the stored grouping between the page and its fragment.
  Q "UPDATE 202_users_pref SET user_pref_group_2 = 0 WHERE user_id = $OWNER"
  curl -sS -c "$JAR" -b "$JAR" -o "$2.html" --data 'offset=0' "$(python3 -c "import sys,urllib.parse; print(urllib.parse.urljoin(sys.argv[1], sys.argv[2]))" "$BASE/" "$frag")"
  table "$2.html" group-overview-table > "$2.tsv"
}
# campaign-rows CAMPAIGN TSV — the campaign's row, then its level-1 rows, as "label|clicks|leads|income|cost".
group_rows() {
  awk -F'\t' -v c="$1" '
    $1 == "#" { for (i = 2; i <= NF; i++) h[$i] = i; next }
    $1 == "0" { inside = ($2 == c) }
    inside { print $2 "|" $(h["Clicks"]) "|" $(h["Leads"]) "|" $(h["Income"]) "|" $(h["Cost"]) }' "$2"
}
CAMPAIGN=4; LANDINGPAGE=5; TRANSACTION=35; GOALSOURCE=37
# The accumulate click four levels deep: campaign, landing page, transaction,
# then what produced each transaction's rows.
FOUR_ACCUMULATE='breakdown accumulate|1|1|$7.00|$0.25 [No Landing Page]|1|1|$7.00|$0.25 [No transaction ID]|0|0|$5.00|$0.00 Goal: Install|0|0|$1.00|$0.00 Goal: Level 3|0|0|$4.00|$0.00 A-1|0|0|$0.00|$0.00 API|0|0|($-5.00)|$0.00 Postback|0|0|$5.00|$0.00 A-2|1|1|$2.00|$0.25 Postback|1|1|$2.00|$0.25 '
FOUR_DOWNLOAD='A-1|API A-1|Postback A-2|Postback [No transaction ID]|Goal: Install [No transaction ID]|Goal: Level 3'

say "Group Overview's Transaction ID level sums the rows"
overview "group_1=$CAMPAIGN&group_2=0&group_3=0&group_4=0&range=last7&user_pref_show=all" "$OUT/go-plain"
overview "group_1=$CAMPAIGN&group_2=$TRANSACTION&group_3=0&group_4=0&range=last7&user_pref_show=all" "$OUT/go-tx"
has "$OUT/go-tx.page.html" "group_2%3D$TRANSACTION" "the page hands its fragment the grouping in its view"
eq "$(group_rows 'breakdown accumulate' "$OUT/go-tx.tsv" | tr '\n' ' ')" \
   'breakdown accumulate|1|1|$7.00|$0.25 [No transaction ID]|0|0|$5.00|$0.00 A-1|0|0|$0.00|$0.00 A-2|1|1|$2.00|$0.25 ' \
   "the accumulate click: goals \$5 with no id, A-1 netted to \$0 by its reversal, A-2 \$2 carrying the click and its lead"
eq "$(group_rows 'breakdown replace' "$OUT/go-tx.tsv" | tr '\n' ' ')" \
   'breakdown replace|2|1|$6.00|$0.50 [Not converted]|1|0|$0.00|$0.25 R-2|1|1|$6.00|$0.25 ' \
   "the replace click shows only R-2, not the replaced R-1 or the deleted R-API, and the unconverted click has its own row"
eq "$(group_rows 'breakdown accumulate' "$OUT/go-tx.tsv" | head -1)" "$(group_rows 'breakdown accumulate' "$OUT/go-plain.tsv" | head -1)" \
   "the campaign's figures are the ones it has without the Transaction ID level"
eq "$(group_rows 'breakdown replace' "$OUT/go-tx.tsv" | head -1)" "$(group_rows 'breakdown replace' "$OUT/go-plain.tsv" | head -1)" \
   "for both campaigns"
eq "$(awk -F'\t' '$2 == "Totals for report"' "$OUT/go-tx.tsv" | cut -f3-)" "$(awk -F'\t' '$2 == "Totals for report"' "$OUT/go-plain.tsv" | cut -f3-)" \
   "and the whole report's totals are unchanged"
has "$OUT/go-tx.html" "data-p202-ledger-note" "the page says how a ledger level splits a click"
hasnt "$OUT/go-plain.html" "data-p202-ledger-note" "and says nothing when none is chosen"

say "Group Overview's Goal / source level"
overview "group_1=$CAMPAIGN&group_2=$GOALSOURCE&group_3=0&group_4=0&range=last7&user_pref_show=all" "$OUT/go-src"
eq "$(group_rows 'breakdown accumulate' "$OUT/go-src.tsv" | tr '\n' ' ')" \
   'breakdown accumulate|1|1|$7.00|$0.25 API|0|0|($-5.00)|$0.00 Goal: Install|0|0|$1.00|$0.00 Goal: Level 3|0|0|$4.00|$0.00 Postback|1|1|$7.00|$0.25 ' \
   "each paid goal on its own row, the unpaid Tutorial nowhere, the postbacks \$7 and the API reversal -\$5"
eq "$(group_rows 'breakdown replace' "$OUT/go-src.tsv" | tr '\n' ' ')" \
   'breakdown replace|2|1|$6.00|$0.50 [Not converted]|1|0|$0.00|$0.25 Postback|1|1|$6.00|$0.25 ' \
   "the replace click's value is its postback's"

# Every group_N offers every level (tests/Report/GroupingLevelOffersTest),
# so Goal / source can be the fourth, under Transaction ID at the third.
say "Goal / source as the fourth level"
overview "group_1=$CAMPAIGN&group_2=$LANDINGPAGE&group_3=$TRANSACTION&group_4=$GOALSOURCE&range=last7&user_pref_show=all" "$OUT/go-four"
has "$OUT/go-four.page.html" "group_4%3D$GOALSOURCE" "the page hands its fragment the fourth level in its view"
eq "$(group_rows 'breakdown accumulate' "$OUT/go-four.tsv" | tr '\n' ' ')" \
   "$FOUR_ACCUMULATE" \
   "the accumulate click by transaction, then by what produced it: the goals under no id, A-1's postback and its API reversal, A-2's postback"
eq "$(group_rows 'breakdown accumulate' "$OUT/go-four.tsv" | head -1)" "$(group_rows 'breakdown accumulate' "$OUT/go-plain.tsv" | head -1)" \
   "the campaign's figures are the ones it has with no ledger level"
eq "$(awk -F'\t' '$2 == "Totals for report"' "$OUT/go-four.tsv" | cut -f3-)" "$(awk -F'\t' '$2 == "Totals for report"' "$OUT/go-plain.tsv" | cut -f3-)" \
   "and so are the report's totals"
eq "$(python3 "$HERE/group-sums.py" "$OUT/go-four.tsv")" "ok" "every group at every depth is the sum of the groups under it"
# The download is the page's own link, under the page's view.
DL=$(python3 -c "import html,re,sys; m=re.search(r'href=\"([^\"]*group_overview_download\.php[^\"]*)\"', open(sys.argv[1]).read()); print(html.unescape(m.group(1)) if m else '')" "$OUT/go-four.page.html")
eq "$(curl -sS -c "$JAR" -b "$JAR" -o "$OUT/go-four.xls" -w '%{http_code}' "$(python3 -c "import sys,urllib.parse; print(urllib.parse.urljoin(sys.argv[1], sys.argv[2]))" "$BASE/" "$DL")")" 200 "the page's download answers"
eq "$(python3 - "$OUT/go-four.xls" "breakdown accumulate" <<'PY'
import sys
lines = [l.rstrip('\n').split('\t') for l in open(sys.argv[1], encoding='utf-8')]
head = next(l for l in lines if 'Transaction ID' in l)
tx, gs, camp = head.index('Transaction ID'), head.index('Goal / source'), head.index('Campaign Name')
print(' '.join(sorted(l[tx] + '|' + l[gs] for l in lines[lines.index(head) + 1:] if len(l) > gs and l[camp] == sys.argv[2])))
PY
)" "$FOUR_DOWNLOAD" "the download labels each leaf by its transaction and by what produced it"

printf '\n%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIPPED"
[ "$FAIL" -eq 0 ]
