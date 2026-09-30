#!/bin/bash
# Live pass for the goals engine (PR 4), driven against a running instance
# with the database read back after every step:
#
#   - /goals: definitions validated strictly and by field (a count of "3", an
#     unknown key, a scope id the int cast would rewrite), versions on edit
#     (the same definition again is not one), archive and its preview, the
#     `goals` scope area, and POST /goals/validate and /goals/evaluate as
#     reads — /goals/evaluate answering a cross-language vector exactly;
#   - the CLI (`p202 goal …`, built from this checkout): create from quick
#     flags, update one part, list, versions, campaign payouts, and the error
#     envelope an agent reads for a bad flag;
#   - conversions through the ledger: an install $1 + level 3 $4 campaign
#     reaches exactly two conversions from levels 1, 2, 3 and a replay of 3,
#     worth $5 accumulated (report row included) and $4 replaced; an event
#     that satisfies no goal records nothing; a late earlier purchase
#     re-decides $5, $10 into $1, $5, $10 = $16 with two rows superseded; an
#     untrusted revenue is stored, not credited; a reused event id with other
#     content is refused; every goal conversion is queued for MTA;
#   - re-evaluation: an edit leaves history alone, the preview changes
#     nothing, applying replaces the outcome one for one (funnel count 1) and
#     re-values the click; staged, it is a proposal until applied; a
#     prerequisite that stops matching takes the goal waiting on it along
#     (previewed by goal, conversions deleted), and the next events reach
#     each once again; and a re-evaluation that returns to exactly an
#     outcome an earlier one retired (a prerequisite that matches, stops,
#     and matches again) revives the dependent's deleted conversion in
#     place, so the click is paid for it again, once;
#   - what one request can cost: a sum that repeats without max is refused
#     by field (API and CLI), revenue beyond what a conversion holds is
#     refused by field, and /goals/evaluate refuses an answer of more than
#     10,000 outcomes;
#   - SKAN encodings point at goals: the old shape is refused by name, an
#     encoding names a goal of its app or the account that a device can
#     reach (PR 8: the device evaluates it; a click window is refused), the
#     schema document carries the goals and never shows revenue, the report
#     decodes to the goal's name at the override, and the goal an encoding
#     names can neither become unreachable nor be archived.
#
# Events reach the engine through tests/live/goals-ingest.php — the engine's
# own entry point — because the HTTP paths that feed it (pixels with event=,
# POST /events, the Android intake) are PRs 4b and 5.
#
# Seeds its own campaigns and clicks (ids 940001-940020) and truncates the
# goal and app tables, so it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass drives the REST API as the instance's admin." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
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
# field EXPR — a value out of the last body, as Python reads it (d = the JSON).
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
# ingest CLICK EVENTS-JSON — events through the engine; the answer lands in $OUT/ingest.
ingest() {
    printf '{"user_id": %s, "click_id": %s, "events": %s}' "$OWNER" "$1" "$2" \
      | (cd "$ROOT" && "$PHP" tests/live/goals-ingest.php) > "$OUT/ingest" 2> "$OUT/ingest.err"
}
ingested() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/ingest" 2>/dev/null; }
value() { Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$1"; }
col()   { Q "SELECT GROUP_CONCAT(COALESCE($2,'-') ORDER BY conv_id SEPARATOR ',') FROM 202_conversion_logs WHERE click_id=$1"; }
ev()    { # id name occurred-offset [extra json fields]
    printf '{"event_id":"%s","name":"%s","occurred_at":%s%s}' "$1" "$2" "$((T + $3))" "${4:+,$4}"
}

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'")
[ -n "$OWNER" ] || { echo "the API key is not in $DB" >&2; exit 2; }
T=$(( $(date +%s) - 86400 ))
ACC=940001; REP=940002; MISS=940003; LATE=940004; TRUST=940005; REEV=940006; DEP=940007; BACK=940008
CLICKS="$ACC,$REP,$MISS,$LATE,$TRUST,$REEV,$DEP,$BACK"
ACIP_A=940100; ACIP_R=940101

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICKS));
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICKS);
DELETE FROM 202_dataengine WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_spy WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_tracking WHERE click_id IN ($CLICKS);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_id_public IN ($ACIP_A, $ACIP_R);
TRUNCATE 202_goals; TRUNCATE 202_goal_versions; TRUNCATE 202_campaign_goals; TRUNCATE 202_goal_subjects;
TRUNCATE 202_goal_events; TRUNCATE 202_goal_progress; TRUNCATE 202_goal_outcomes;
TRUNCATE 202_app_registrations; TRUNCATE 202_app_postbacks; TRUNCATE 202_app_skan_encodings; TRUNCATE 202_app_skan_encoding_history;
SQL
}
cleanup
trap cleanup EXIT

NOW=$(date +%s)
mysql_q "$DB" <<SQL
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode)
  VALUES ($ACIP_A, $OWNER, 0, 'goals accumulate', 'http://example.test/', 0, $NOW, 0, 'accumulate'),
         ($ACIP_R, $OWNER, 0, 'goals replace', 'http://example.test/', 0, $NOW, 0, 'replace');
SQL
CAMP_A=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_A")
CAMP_R=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public=$ACIP_R")
[ -n "$CAMP_A" ] && [ -n "$CAMP_R" ] || { echo "seeding campaigns failed" >&2; exit 2; }
seed_click() { # id campaign
  mysql_q "$DB" -e "
    INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
      VALUES ($1, $OWNER, $2, 0, 0, 0.10, 0, 0, 0, 0, 0, $((T - 3600)), 0, 0);
    INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
      VALUES ($1, $OWNER, $2, 0, 0, 0.10, 0, 0, 0, 0, 0, $((T - 3600)));
    INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES ($1, 0, 0, 0, 0);"
}
for c in $ACC $MISS $LATE $TRUST $REEV $DEP $BACK; do seed_click "$c" "$CAMP_A"; done
seed_click $REP "$CAMP_R"
[ "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id IN ($CLICKS)")" = 8 ] || { echo "seeding clicks failed" >&2; exit 2; }

# ─────────────────────────────────────────────────────────────────────
say "goals are validated strictly, and by field"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":\"1e3\",\"definition\":{\"name\":\"X\",\"trigger\":{\"event\":\"x\"}}}")" 422 \
   "a scope id the int cast would rewrite is refused"
eq "$(field "list(d['field_errors'])")" '["scope_id"]' "naming scope_id"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"X\",\"trigger\":{\"event\":\"x\"},\"threshold\":{\"count\":\"3\"}}}")" 422 \
   "a count of \"3\" is refused, not cast"
eq "$(field "list(d['field_errors'])")" '["definition.threshold.count"]' "naming the path inside the definition"
eq "$(api POST /goals "{\"scope\":\"account\",\"colour\":\"red\",\"definition\":{\"name\":\"X\",\"trigger\":{\"event\":\"x\"},\"treshold\":{}}}")" 422 \
   "unknown fields are refused, not ignored"
has "$OUT/body" '"colour"' "the body's unknown field is named"
eq "$(api POST /goals "{\"scope\":\"account\",\"definition\":{\"name\":\"X\",\"trigger\":{\"install\":true,\"where\":[]}}}")" 422 \
   "an install trigger with conditions is refused"

say "campaign goals: install \$1 and level 3 \$4, payable on the campaign"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Install\",\"trigger\":{\"event\":\"first_open\"},\"value\":{\"type\":\"fixed\",\"amount\":\"1.00\"}}}")" 201 \
   "POST /goals creates a campaign goal"
G_INSTALL=$(field "d['data']['goal_id']")
eq "$(field "d['data']['campaigns'][0]['campaign_id']")" "$CAMP_A" "a campaign goal with a value is payable on its campaign"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Level 3\",\"trigger\":{\"event\":\"level_reached\",\"where\":[{\"prop\":\"level\",\"op\":\"gte\",\"value\":3}]},\"value\":{\"type\":\"fixed\",\"amount\":4}}}")" 201 \
   "and a level-3 goal"
G_LEVEL=$(field "d['data']['goal_id']")
eq "$(field "d['data']['definition']['value']['amount']")" "4.00" "stored in canonical form"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"level 3\",\"trigger\":{\"event\":\"other\"}}}")" 409 \
   "a second goal of the same name on the campaign is a 409"
for g in "$G_INSTALL" "$G_LEVEL"; do
  api PUT "/goals/$g/campaigns/$CAMP_R" '{}' > /dev/null
done
eq "$(field "d['field_errors']['campaign_id'] is not None")" "true" "a campaign's own goal cannot be attached to another campaign"
api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_R,\"definition\":{\"name\":\"Install\",\"trigger\":{\"event\":\"first_open\"},\"value\":{\"type\":\"fixed\",\"amount\":1}}}" > /dev/null
api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_R,\"definition\":{\"name\":\"Level 3\",\"trigger\":{\"event\":\"level_reached\",\"where\":[{\"prop\":\"level\",\"op\":\"gte\",\"value\":3}]},\"value\":{\"type\":\"fixed\",\"amount\":4}}}" > /dev/null
eq "$(Q "SELECT COUNT(*) FROM 202_campaign_goals WHERE campaign_id=$CAMP_R")" 2 "the replace campaign has the same two goals"

say "conversions that satisfy goals, through the ledger (accumulate)"
ingest $ACC "[$(ev f1 first_open 1)]"
eq "$(ingested "d['outcomes_written']")" 1 "the install event reaches the install goal"
for lvl in 1 2 3; do ingest $ACC "[$(ev "l$lvl" level_reached $((1 + lvl)) "\"properties\":{\"level\":$lvl}")]"; done
ingest $ACC "[$(ev l3 level_reached 4 '"properties":{"level":3}')]"
eq "$(ingested "d['duplicates']")" '["l3"]' "a replay of level 3 is a duplicate"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$ACC")" 2 "exactly two conversions"
eq "$(col $ACC source)" "goal,goal" "both from goals"
eq "$(col $ACC source_ref)" "goal:$G_INSTALL:1,goal:$G_LEVEL:1" "each naming its goal and version"
eq "$(col $ACC dedupe_key)" "goal:$G_INSTALL:1:1:f1,goal:$G_LEVEL:1:1:l3" "keyed goal:<id>:<version>:<n>:<event>"
eq "$(value $ACC)" "1/5.00000" "the click is worth \$1 + \$4 in accumulate mode"
eq "$(Q "SELECT CONCAT(leads, '/', payout) FROM 202_dataengine WHERE click_id=$ACC")" "1/5.00" "and the report row says so"
eq "$(Q "SELECT COUNT(*) FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id WHERE c.click_id=$ACC")" 2 \
   "both conversions are queued for MTA"
eq "$(api GET "/goals/$G_LEVEL/outcomes?subject_type=click&subject_id=$ACC")" 200 "GET /goals/{id}/outcomes"
eq "$(field "[(o['n'], o['event_id'], o['value'], o['payable']) for o in d['data']]")" '[[1, "l3", "4.00000", true]]' \
   "one outcome: n 1, reached by level 3, \$4, paid"

say "the same events on a replace campaign"
ingest $REP "[$(ev f1 first_open 1), $(ev l1 level_reached 2 '"properties":{"level":1}'), $(ev l3 level_reached 3 '"properties":{"level":3}')]"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$REP")" 2 "two conversions"
eq "$(value $REP)" "1/4.00000" "the latest, \$4, is the click's value"
eq "$(col $REP superseded_reason)" "replace,-" "the install row says the level row replaced it"

say "events that satisfy no goal record nothing"
ingest $MISS "[$(ev m1 level_reached 1 '"properties":{"level":2}'), $(ev m2 unrelated 2)]"
eq "$(ingested "d['accepted']")" '["m1", "m2"]' "the events are stored"
eq "$(ingested "d['outcomes_written']")" 0 "no outcome"
eq "$(Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$MISS")" 0 "no conversion"
eq "$(value $MISS)" "0/0.00000" "and the click is not a lead"
ingest $MISS "[$(ev m1 level_reached 1 '"properties":{"level":5}')]"
eq "$(ingested "d['error']['reason']")" event_conflict "a reused event id with other content is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_events WHERE subject_id=$MISS")" 2 "and nothing more is stored"

say "a late earlier purchase re-decides which purchase is which"
api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Purchase\",\"trigger\":{\"event\":\"purchase\"},\"repeat\":{\"mode\":\"each\"},\"value\":{\"type\":\"from_property\"}}}" > /dev/null
G_BUY=$(field "d['data']['goal_id']")
ingest $LATE "[$(ev p5 purchase 20 '"revenue":5,"revenue_trusted":true')]"
ingest $LATE "[$(ev p10 purchase 30 '"revenue":10,"revenue_trusted":true')]"
eq "$(value $LATE)" "1/15.00000" "\$5 and \$10 in arrival order"
ingest $LATE "[$(ev p1 purchase 15 '"revenue":1,"revenue_trusted":true')]"
eq "$(ingested "d['replayed']")" true "an event earlier than the stored ones is a replay"
eq "$(value $LATE)" "1/16.00000" "the click shows \$1 + \$5 + \$10, not \$31"
eq "$(col $LATE superseded_reason)" "replay,replay,-,-,-" "the two rows the replay moved are superseded"
api GET "/goals/$G_BUY/outcomes?subject_id=$LATE" > /dev/null
eq "$(field "[(o['n'], o['event_id'], o['value']) for o in d['data']]")" '[[1, "p1", "1.00000"], [2, "p5", "5.00000"], [3, "p10", "10.00000"]]' \
   "the live outcomes read \$1, \$5, \$10"

say "an untrusted revenue is stored, not credited"
ingest $TRUST "[$(ev u1 purchase 1 '"revenue":12.5')]"
eq "$(Q "SELECT CONCAT(payable, '/', click_payout) FROM 202_conversion_logs WHERE click_id=$TRUST")" "0/12.50000" "the row carries \$12.50, unpaid"
eq "$(value $TRUST)" "0/0.00000" "and the click is not a lead on it"
eq "$(Q "SELECT value_note FROM 202_goal_outcomes WHERE subject_id=$TRUST")" untrusted_value "the outcome says why"

say "versions and re-evaluation"
ingest $REEV "[$(ev r1 first_open 1), $(ev r3 level_reached 2 '"properties":{"level":3}')]"
eq "$(value $REEV)" "1/5.00000" "the click starts at \$5"
eq "$(api PUT "/goals/$G_LEVEL" '{"definition":{"name":"Level 3","trigger":{"event":"level_reached","where":[{"prop":"level","op":"gte","value":3}]},"value":{"type":"fixed","amount":6}}}')" 200 \
   "editing the goal"
eq "$(field "[d['data']['current_version'], d['data']['version_created']]")" '[2, true]' "is version 2"
eq "$(api PUT "/goals/$G_LEVEL" '{"definition":{"name":"Level 3","trigger":{"event":"level_reached","where":[{"prop":"level","op":"gte","value":3}]},"value":{"type":"fixed","amount":"6.00"}}}')" 200 \
   "sending the same definition again"
eq "$(field "[d['data']['current_version'], d['data']['version_created']]")" '[2, false]' "is not a new version"
eq "$(value $REEV)" "1/5.00000" "an edit never rewrites history"
before=$(Q "SELECT GROUP_CONCAT(CONCAT(conv_id, click_payout, IFNULL(superseded_reason,'-'))) FROM 202_conversion_logs WHERE click_id IN ($ACC,$REEV)")
eq "$(api GET "/goals/$G_LEVEL/reevaluation")" 200 "GET /goals/{id}/reevaluation previews"
eq "$(field "[d['data']['version'], d['data']['applied'], d['data']['totals']['subjects'], d['data']['totals']['retire'], d['data']['totals']['ledger_superseded']]")" \
   '[2, false, 5, 2, 2]' "every click with events on the campaign; two outcomes to retire, two conversions to supersede"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(conv_id, click_payout, IFNULL(superseded_reason,'-'))) FROM 202_conversion_logs WHERE click_id IN ($ACC,$REEV)")" "$before" \
   "and changes nothing"
eq "$(api POST "/goals/$G_LEVEL/reevaluation?staged=1" '{}')" 202 "staged, re-evaluation is a proposal"
CHANGE=$(field "d['data']['change_id']")
eq "$(value $REEV)" "1/5.00000" "that applies nothing"
eq "$(api POST "/staged-changes/$CHANGE/apply" '{}')" 200 "applying the proposal"
eq "$(value $REEV)" "1/7.00000" "re-values the click at \$1 + \$6"
eq "$(value $ACC)" "1/7.00000" "and every click the goal applies to"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_outcomes WHERE goal_id=$G_LEVEL AND subject_id=$REEV AND superseded_at IS NULL")" 1 "the funnel count stays one"
eq "$(Q "SELECT CONCAT(goal_version, '/', superseded_reason) FROM 202_goal_outcomes WHERE goal_id=$G_LEVEL AND subject_id=$REEV AND superseded_at IS NOT NULL")" "1/reevaluation" \
   "the version-1 outcome is retired, not deleted"
eq "$(col $REEV superseded_reason)" "-,reevaluation,-" "its conversion is superseded by the version-2 one"
eq "$(api GET "/goals/$G_LEVEL/versions")" 200 "GET /goals/{id}/versions"
eq "$(field "[v['version'] for v in d['data']]")" '[1, 2]' "lists both"

say "/goals/evaluate runs a cross-language vector exactly, and writes nothing"
python3 - "$ROOT/tests/fixtures/app-sdk-contract/goals/evaluator.json" "$OUT/vector.json" "$OUT/vector.expect" <<'PY'
import json, sys
cases = json.load(open(sys.argv[1]))['cases']
case = next(c for c in cases if c['name'].startswith('a late earlier purchase'))
json.dump({'goals': case['goals'], 'subject': case['subject'], 'events': case['events']}, open(sys.argv[2], 'w'))
json.dump(case['expect']['outcomes'], open(sys.argv[3], 'w'), sort_keys=True)
PY
outcomes_before=$(Q "SELECT COUNT(*) FROM 202_goal_outcomes")
eq "$(api POST /goals/evaluate "$(cat "$OUT/vector.json")")" 200 "POST /goals/evaluate"
python3 -c "import json,sys; print(json.dumps(json.load(open(sys.argv[1]))['data']['outcomes'], sort_keys=True))" "$OUT/body" > "$OUT/vector.got"
eq "$(cat "$OUT/vector.got")" "$(cat "$OUT/vector.expect")" "answers the vector's outcomes exactly"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_outcomes")" "$outcomes_before" "and stores nothing"

say "goals is its own scope area; validate and evaluate are reads"
mint() {
    local code
    code=$(api POST "/users/$OWNER/api-keys" "{\"scope\":\"$1\"}")
    [ "$code" = 201 ] || { echo "    minting a $1 key answered $code: $(cat "$OUT/body")" >&2; return; }
    field "d['data'].get('api_key') or d['data'].get('key')"
}
READ_KEY=$(mint goals:read)
CAMP_KEY=$(mint campaigns:write)
eq "$(api GET /goals '' "$READ_KEY")" 200 "goals:read lists goals"
eq "$(api POST /goals/validate '{"definition":{"name":"A","trigger":{"event":"a"}}}' "$READ_KEY")" 200 "and validates a definition (a read)"
eq "$(api POST /goals/evaluate "$(cat "$OUT/vector.json")" "$READ_KEY")" 200 "and evaluates one (a read)"
eq "$(api POST /goals '{"scope":"account","definition":{"name":"A","trigger":{"event":"a"}}}' "$READ_KEY")" 403 "but cannot create"
eq "$(api GET /goals '' "$CAMP_KEY")" 403 "a campaigns:write key cannot reach /goals"
for k in "$READ_KEY" "$CAMP_KEY"; do [ -n "$k" ] && api DELETE "/users/$OWNER/api-keys/$k" > /dev/null; done

say "archiving: previewed, refused while another goal waits for it"
api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Upsell\",\"trigger\":{\"event\":\"upsell\"},\"after\":[$G_BUY]}}" > /dev/null
G_UP=$(field "d['data']['goal_id']")
eq "$(api DELETE "/goals/$G_BUY?dry_run=1")" 200 "DELETE ?dry_run=1 previews"
eq "$(field "[d['data']['action'], d['data']['blocked_by']['dependents'], d['data']['can_delete']]")" "[\"archive\", [$G_UP], false]" \
   "naming the goal that waits for it"
eq "$(api DELETE "/goals/$G_BUY")" 409 "and the archive is refused"
eq "$(api DELETE "/goals/$G_UP")" 204 "the dependent archives"
eq "$(api PUT "/goals/$G_UP" '{"definition":{"name":"Upsell","trigger":{"event":"upsell"}}}')" 409 "an archived goal cannot be edited"
eq "$(api GET "/goals?include_archived=1")" 200 "archived goals are listed on request"
has "$OUT/body" "\"goal_id\":$G_UP" "with their history"

say "the CLI: p202 goal …"
CLI="$OUT/p202"
if (cd "$ROOT/go-cli" && go build -o "$CLI" .) 2> "$OUT/build.err"; then
  mkdir -p "$OUT/home/.p202"
  printf '{"url": "%s", "api_key": "%s"}' "$BASE" "$P202_API_KEY" > "$OUT/home/.p202/config.json"
  p202() { HOME="$OUT/home" "$CLI" "$@"; }
  p202 goal create --campaign-id "$CAMP_A" --name "Tutorial" --event tutorial_complete --after "$G_INSTALL" --no-value --json > "$OUT/cli.json" 2> "$OUT/cli.err"
  eq "$?" 0 "goal create from quick flags"
  G_TUT=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data']['goal_id'])" "$OUT/cli.json" 2>/dev/null)
  eq "$(Q "SELECT definition FROM 202_goal_versions WHERE goal_id='$G_TUT'")" \
     "{\"name\":\"Tutorial\",\"trigger\":{\"event\":\"tutorial_complete\",\"where\":[]},\"threshold\":{\"count\":1},\"after\":[$G_INSTALL],\"within\":null,\"repeat\":{\"mode\":\"once\"},\"value\":{\"type\":\"none\"}}" \
     "stored as the flags said, in canonical form"
  p202 goal update "$G_TUT" --count 2 --json > /dev/null 2>&1
  eq "$(Q "SELECT CONCAT(current_version, '/', name) FROM 202_goals WHERE goal_id='$G_TUT'")" "2/Tutorial" "goal update changes one part, as a new version"
  eq "$(Q "SELECT JSON_EXTRACT(definition, '\$.after[0]') FROM 202_goal_versions WHERE goal_id='$G_TUT' AND version=2")" "$G_INSTALL" "and keeps the rest"
  p202 goal list --campaign-id "$CAMP_A" --json > "$OUT/cli.json" 2>/dev/null
  eq "$(python3 -c "import json,sys; print(sorted(g['name'] for g in json.load(open(sys.argv[1]))['data']))" "$OUT/cli.json")" \
     "['Install', 'Level 3', 'Purchase', 'Tutorial']" "goal list --campaign-id lists the campaign's live goals"
  p202 goal campaign set "$G_TUT" "$CAMP_A" --payout 0.50 --json > /dev/null 2>&1
  eq "$(Q "SELECT payout FROM 202_campaign_goals WHERE goal_id='$G_TUT'")" "0.50000" "goal campaign set pays for it"
  p202 goal campaign remove "$G_TUT" "$CAMP_A" --force > "$OUT/cli.txt" 2>&1
  has "$OUT/cli.txt" "no longer pays for goal $G_TUT" "goal campaign remove says what it did"
  eq "$(Q "SELECT COUNT(*) FROM 202_campaign_goals WHERE goal_id='$G_TUT'")" 0 "and did it"
  p202 goal versions "$G_LEVEL" --json > "$OUT/cli.json" 2>/dev/null
  eq "$(python3 -c "import json,sys; print([v['version'] for v in json.load(open(sys.argv[1]))['data']])" "$OUT/cli.json")" "[1, 2]" "goal versions"
  p202 goal create --account --name X --event x --where "level like 3" --json > "$OUT/cli.out" 2> "$OUT/cli.err"
  eq "$?" 1 "a bad --where exits 1"
  eq "$(python3 -c "import json,sys; e=json.load(open(sys.argv[1]))['error']; print(e['category'])" "$OUT/cli.err" 2>/dev/null)" validation \
     "with a validation envelope on stderr"
  has "$OUT/cli.err" "eq, neq, gt, gte, lt, lte, in, exists" "that lists the operators"
  eq "$(wc -c < "$OUT/cli.out" | tr -d ' ')" 0 "and nothing on stdout"
  p202 goal create --campaign-id 99999 --name X --event x --json > /dev/null 2> "$OUT/cli.err"
  has "$OUT/cli.err" "p202 campaign list" "a refused campaign id names the command that lists campaigns"
  p202 goal create --account --name Spend --event buy --sum-prop amount --sum-gte 10 --repeat each --json > "$OUT/cli.out" 2> "$OUT/cli.err"
  eq "$?" 1 "a sum that repeats without --repeat-max exits 1"
  eq "$(python3 -c "import json,sys; e=json.load(open(sys.argv[1]))['error']; print(e['category'], '--repeat-max' in e['hint'])" "$OUT/cli.err" 2>/dev/null)" "validation True" \
     "as a validation envelope whose hint names --repeat-max"
  eq "$(Q "SELECT COUNT(*) FROM 202_goals WHERE name='Spend'")" 0 "and nothing is created"
else
  bad "the CLI builds ($(head -3 "$OUT/build.err"))"
fi

say "re-evaluation re-decides the goals waiting on the goal"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Checkout\",\"trigger\":{\"event\":\"checkout\"},\"value\":{\"type\":\"fixed\",\"amount\":2}}}")" 201 \
   "a checkout goal"
G_CO=$(field "d['data']['goal_id']")
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Checkout upsell\",\"trigger\":{\"event\":\"upsell2\"},\"after\":[$G_CO],\"value\":{\"type\":\"fixed\",\"amount\":3}}}")" 201 \
   "and an upsell that waits for it"
G_CU=$(field "d['data']['goal_id']")
ingest $DEP "[$(ev d1 checkout 1 '"properties":{"cart":10}'), $(ev d2 upsell2 2)]"
eq "$(value $DEP)" "1/5.00000" "both reached: \$2 + \$3"
eq "$(api PUT "/goals/$G_CO" '{"definition":{"name":"Checkout","trigger":{"event":"checkout","where":[{"prop":"cart","op":"gte","value":100}]},"value":{"type":"fixed","amount":2}}}')" 200 \
   "the checkout goal now needs a cart of 100"
eq "$(api GET "/goals/$G_CO/reevaluation")" 200 "the preview"
eq "$(field "d['data']['goals']")" "[$G_CO, $G_CU]" "names the goal and the one waiting on it"
eq "$(field "[sorted([r['goal_id'], r['ledger']] for r in s['retire']) for s in d['data']['subjects'] if s['subject_id'] == $DEP]")" \
   "[[[$G_CO, \"delete\"], [$G_CU, \"delete\"]]]" "and retires both on the click, deleting both conversions"
eq "$(api POST "/goals/$G_CO/reevaluation" '{}')" 200 "applying it"
eq "$(Q "SELECT click_lead FROM 202_clicks WHERE click_id=$DEP")" 0 "leaves the click no longer a lead"
eq "$(col $DEP deleted)" "1,1" "both conversions deleted"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_outcomes WHERE subject_id=$DEP AND superseded_at IS NULL")" 0 "and no live outcome on the click"
ingest $DEP "[$(ev d3 checkout 3 '"properties":{"cart":200}')]"
ingest $DEP "[$(ev d4 upsell2 4)]"
eq "$(value $DEP)" "1/5.00000" "a cart of 200 and another upsell reach both again"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(goal_id, ':', goal_version, ':', n, '@', event_id) ORDER BY goal_id) FROM 202_goal_outcomes WHERE subject_id=$DEP AND superseded_at IS NULL")" \
   "$G_CO:2:1@d3,$G_CU:1:1@d4" "once each, never a second live outcome beside a retired one"

say "a re-evaluation that returns to a retired outcome pays for it again"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Order\",\"trigger\":{\"event\":\"order\"},\"value\":{\"type\":\"fixed\",\"amount\":5}}}")" 201 \
   "an order goal (\$5)"
G_OR=$(field "d['data']['goal_id']")
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Order upsell\",\"trigger\":{\"event\":\"order_upsell\"},\"after\":[$G_OR],\"value\":{\"type\":\"fixed\",\"amount\":3}}}")" 201 \
   "and an upsell (\$3) that waits for it"
G_OU=$(field "d['data']['goal_id']")
ingest $BACK "[$(ev b1 order 1 '"properties":{"cart":10}'), $(ev b2 order_upsell 2)]"
eq "$(value $BACK)" "1/8.00000" "both reached: \$5 + \$3"
eq "$(api PUT "/goals/$G_OR" '{"definition":{"name":"Order","trigger":{"event":"order","where":[{"prop":"cart","op":"gte","value":100}]},"value":{"type":"fixed","amount":5}}}')" 200 \
   "the order goal now needs a cart of 100"
eq "$(api POST "/goals/$G_OR/reevaluation" '{}')" 200 "applying it"
eq "$(Q "SELECT click_lead FROM 202_clicks WHERE click_id=$BACK")" 0 "retires both: the click is no longer a lead"
eq "$(col $BACK deleted)/$(col $BACK superseded_reason)" "1,1/reevaluation,reevaluation" "both conversions deleted, each marked as the engine's retirement"
eq "$(api PUT "/goals/$G_OR" '{"definition":{"name":"Order","trigger":{"event":"order"},"value":{"type":"fixed","amount":5}}}')" 200 \
   "the order goal matches any cart again (version 3)"
eq "$(api GET "/goals/$G_OR/reevaluation")" 200 "the preview"
eq "$(field "[sorted(w['goal_id'] for w in s['write']) for s in d['data']['subjects'] if s['subject_id'] == $BACK]")" "[[$G_OR, $G_OU]]" \
   "writes the order under version 3 and the upsell under the version it always had"
eq "$(api POST "/goals/$G_OR/reevaluation" '{}')" 200 "applying it"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(goal_id, ':', goal_version, ':', n, '@', event_id) ORDER BY goal_id) FROM 202_goal_outcomes WHERE subject_id=$BACK AND superseded_at IS NULL")" \
   "$G_OR:3:1@b1,$G_OU:1:1@b2" "both outcomes are live"
eq "$(value $BACK)" "1/8.00000" "and both are paid: the upsell's conversion is revived, not left deleted"
eq "$(Q "SELECT CONCAT(leads, '/', payout) FROM 202_dataengine WHERE click_id=$BACK")" "1/8.00" "the report row says so"
eq "$(col $BACK source_ref)" "goal:$G_OR:1,goal:$G_OU:1,goal:$G_OR:3" "three rows: the retired order, the upsell, the new order"
eq "$(col $BACK deleted)/$(col $BACK superseded_reason)" "1,0,0/reevaluation,-,-" "the upsell's own row is live again, unmarked"
eq "$(Q "SELECT COUNT(*) FROM 202_goal_outcomes WHERE subject_id=$BACK AND goal_id=$G_OU")" 1 "revived in place: one upsell outcome, never a second"

say "what one request can cost is bounded"
eq "$(api POST /goals "{\"scope\":\"campaign\",\"scope_id\":$CAMP_A,\"definition\":{\"name\":\"Every cent\",\"trigger\":{\"event\":\"buy\"},\"threshold\":{\"sum\":{\"prop\":\"\$revenue\",\"gte\":\"0.00001\"}},\"repeat\":{\"mode\":\"each\"}}}")" 422 \
   "a sum that repeats without max is refused"
eq "$(field "list(d['field_errors'])")" '["definition.repeat.max"]' "naming definition.repeat.max"
eq "$(api POST /goals/evaluate '{"goals":[{"goal_id":1,"definition":{"name":"B","trigger":{"event":"buy"}}}],"subject":{"type":"click"},"events":[{"event_id":"e","name":"buy","occurred_at":1,"received_at":1,"revenue":1000000}]}')" 422 \
   "revenue beyond what a conversion holds is refused"
eq "$(field "list(d['field_errors'])")" '["events[0].revenue"]' "naming events[0].revenue"
BIG='{"name":"G","trigger":{"event":"buy"},"threshold":{"sum":{"prop":"$revenue","gte":"0.00001"}},"repeat":{"mode":"each","max":6000}}'
eq "$(api POST /goals/evaluate "{\"goals\":[{\"goal_id\":1,\"definition\":$BIG},{\"goal_id\":2,\"definition\":$BIG}],\"subject\":{\"type\":\"click\"},\"events\":[{\"event_id\":\"e\",\"name\":\"buy\",\"occurred_at\":1,\"received_at\":1,\"revenue\":1}]}")" 422 \
   "an evaluation of 12,000 outcomes is refused"
has "$OUT/body" "more than 10000 outcomes" "saying why"
eq "$(api POST /goals/evaluate "{\"goals\":[{\"goal_id\":1,\"definition\":$BIG}],\"subject\":{\"type\":\"click\"},\"events\":[{\"event_id\":\"e\",\"name\":\"buy\",\"occurred_at\":1,\"received_at\":1,\"revenue\":999999.99999}]}")" 200 \
   "one of 6,000 is answered"
eq "$(field "[len(d['data']['outcomes']), d['data']['progress'][0]['sum']]")" '[6000, "0.06000"]' "reaching max once, the sum held at max x gte"

say "SKAN encodings point at goals"
eq "$(api POST /apps '{"app_key":"990099001","app_name":"Goal App"}')" 201 "an iOS app"
RID=$(field "d['data']['registration_id']")
TOKEN=$(field "d['data']['app_token']")
eq "$(api POST /goals "{\"scope\":\"registration\",\"scope_id\":$RID,\"definition\":{\"name\":\"Purchase\",\"trigger\":{\"event\":\"purchase\"},\"value\":{\"type\":\"fixed\",\"amount\":2}}}")" 201 \
   "a plain event goal of that app"
G_APP=$(field "d['data']['goal_id']")
api POST /goals "{\"scope\":\"registration\",\"scope_id\":$RID,\"definition\":{\"name\":\"Level 5\",\"trigger\":{\"event\":\"level\",\"where\":[{\"prop\":\"level\",\"op\":\"gte\",\"value\":5}]}}}" > /dev/null
G_FANCY=$(field "d['data']['goal_id']")
eq "$(api POST /goals '{"scope":"account","definition":{"name":"Whale","trigger":{"event":"whale"},"value":{"type":"fixed","amount":50}}}')" 201 "and an account goal"
G_ACCT=$(field "d['data']['goal_id']")
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"fine_value\":3,\"event_name\":\"purchase\",\"revenue\":4.99}")" 422 \
   "the old shape (event_name, revenue) is refused"
has "$OUT/body" "goal_id" "saying to send goal_id"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"fine_value\":3,\"goal_id\":$G_APP,\"revenue_override\":4.99}")" 201 \
   "an encoding names the app's goal, with a tiered revenue"
ENC=$(field "d['data']['encoding_id']")
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"coarse_value\":\"high\",\"goal_id\":$G_ACCT}")" 201 "or an account goal"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"fine_value\":4,\"goal_id\":$G_FANCY}")" 201 "a goal with conditions too: the device evaluates it (PR 8)"
api POST /goals "{\"scope\":\"registration\",\"scope_id\":$RID,\"definition\":{\"name\":\"Quick\",\"trigger\":{\"event\":\"buy\"},\"within\":{\"days\":1,\"from\":\"click\"}}}" > /dev/null
G_CLICK=$(field "d['data']['goal_id']")
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"fine_value\":6,\"goal_id\":$G_CLICK}")" 422 "but not one that counts from the click, which a device never knows"
has "$OUT/body" "from the click" "saying why"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":0,\"fine_value\":4,\"goal_id\":$G_APP}")" 422 "an account-wide encoding names an account goal only"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"fine_value\":4,\"goal_id\":\"$G_APP.0\"}")" 422 "a goal id is read raw, not cast"
eq "$(curl -s -o "$OUT/body" -w '%{http_code}' -H "X-P202-App-Token: $TOKEN" "$BASE/api/v3/apps/schema")" 200 "the app's schema document"
eq "$(field "sorted([e['goal_id'], e['fine_value'], e['coarse_value']] for e in d['data']['encodings'])")" \
   "$(python3 -c "import json; print(json.dumps(sorted([[$G_APP, 3, None], [$G_FANCY, 4, None], [$G_ACCT, None, 'high']])))")" "carries each encoded goal's values"
hasnt "$OUT/body" "4.99" "and never shows revenue"
NOWS=$(date +%s)
Q "INSERT INTO 202_app_postbacks (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id, conversion_value,
     postback_sequence_index, did_win, attribution_signature, signature_state, trusted, dedupe_hash, raw_payload, created_at)
   VALUES ($OWNER, $RID, $NOWS, 'skadnetwork', '4.0', 'goals.skadnetwork', 'goals-t1', 990099001, 3, 0, 1, 'x', 'valid', 1, SHA1('goals-t1'), '{}', $NOWS)"
eq "$(api GET "/apps/report?group_by=registration")" 200 "the report"
eq "$(field "d['data']['groups'][0]['events']")" '{"Purchase": {"count": 1, "revenue": 4.99}}' "decodes the value to the goal's name, at the override"
eq "$(api PUT "/goals/$G_APP" '{"definition":{"name":"Purchase","trigger":{"event":"purchase"},"threshold":{"count":2}}}')" 200 \
   "the encoded goal can become any goal a device reaches"
eq "$(api PUT "/goals/$G_APP" '{"definition":{"name":"Purchase","trigger":{"event":"purchase"},"within":{"days":2,"from":"click"}}}')" 422 \
   "but not one counting from the click"
has "$OUT/body" "SKAN encodings $ENC" "naming the encoding"
eq "$(api DELETE "/goals/$G_APP")" 409 "nor be archived under it"
eq "$(api DELETE "/apps/$RID")" 204 "deleting the registration"
eq "$(Q "SELECT archived_at IS NOT NULL FROM 202_goals WHERE goal_id=$G_APP")" 1 "archives the app's goals"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE registration_id=$RID")" 0 "and its encodings go with it"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
