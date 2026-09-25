#!/bin/bash
# Live pass for the iOS SDK update (PR 8), driven against a running instance
# with the database read back after every step:
#
#   - the SDK contract fixtures over HTTP: every definitions.json case through
#     POST /goals/validate and every evaluator.json case through
#     POST /goals/evaluate answer as the vectors say — the same files the
#     Swift evaluator's suite runs, so the device and the running server are
#     held to one specification;
#   - SKAN encodings name full goals now that the device evaluates them: a
#     funnel (after + where), a cumulative sum and an install goal are
#     encoded; a goal counting from the click, directly or through `after`,
#     is refused, and an encoded goal cannot be edited into one;
#   - GET /apps/schema serves the goals an encoding names with every
#     prerequisite, every version, and no value, plus the encodings; `p202
#     app schema` (built from this checkout) shows the same document;
#   - the Swift SDK itself, against that live document (when a Swift
#     toolchain is given): it fetches, decodes, evaluates every served goal,
#     and sets the value each step of a funnel should — the real fetch path,
#     not a fixture;
#   - encoding versions with the 35-day horizon: an edit and a delete keep the
#     meaning they replace; the report decodes a postback from before an edit
#     under the old meaning, one inside the horizon as ambiguous_encoding
#     (credited to neither goal), one after it under the new meaning, and a
#     deleted encoding keeps decoding inside the horizon;
#   - Setup › Mobile Apps says so when a rule is edited.
#
# Needs a scratch database (it truncates the goal and app tables).
#
#   P202_BASE, P202_DB, P202_DB_USER, P202_DB_PASS  the instance and its database
#   P202_API_KEY                                    the admin's REST key
#   P202_USER, P202_PASS                            the admin's login (Setup page step)
#   P202_SWIFT                                      a `swift` binary (the SDK step); unset = reported as not run

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
SWIFT=${P202_SWIFT:-}

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
PASS=0; FAIL=0; NOTRUN=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
notrun() { NOTRUN=$((NOTRUN+1)); printf '  \033[33mNOT RUN\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }

api() {
    local key=${4-$P202_API_KEY}
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $key")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
schema() { curl -s -o "$OUT/body" -D "$OUT/head" -w '%{http_code}' -H "X-P202-App-Token: $TOKEN" "$@" "$BASE/api/v3/apps/schema"; }
header() { grep -i "^$1:" "$OUT/head" | head -1 | cut -d' ' -f2- | tr -d '\r'; }

mysql_q "$DB" -e "TRUNCATE 202_app_registrations; TRUNCATE 202_app_skan_encodings; TRUNCATE 202_app_skan_encoding_history;
  TRUNCATE 202_app_postbacks; TRUNCATE 202_goals; TRUNCATE 202_goal_versions;"
OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key = '$P202_API_KEY' LIMIT 1")
[ -n "$OWNER" ] || OWNER=1

say "the SDK contract fixtures, answered by the running server"
python3 - "$ROOT/tests/fixtures/app-sdk-contract/goals" "$BASE/api/v3" "$P202_API_KEY" > "$OUT/vectors.txt" <<'PY'
import json, sys, urllib.request, urllib.error
root, base, key = sys.argv[1:4]
def post(path, body):
    req = urllib.request.Request(base + path, data=json.dumps(body).encode(), method='POST',
        headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)
bad = 0
defs = json.load(open(root + '/definitions.json'))['cases']
for c in defs:
    status, body = post('/goals/validate', {'definition': c['definition']})
    if c['valid']:
        good = status == 200 and body['data']['definition'] == c['canonical']
    else:
        paths = sorted(p[len('definition.'):] if p.startswith('definition.') else p for p in body.get('field_errors', {}))
        good = status == 422 and paths == sorted(c['errors'])
    if not good:
        bad += 1
        print('definition FAIL', c['name'], status, json.dumps(body)[:300])
cases = json.load(open(root + '/evaluator.json'))['cases']
for c in cases:
    status, body = post('/goals/evaluate', {'goals': c['goals'], 'subject': c['subject'], 'events': c['events']})
    good = status == 200 and body['data']['outcomes'] == c['expect']['outcomes'] and body['data']['disabled'] == c['expect']['disabled']
    if not good:
        bad += 1
        print('evaluator FAIL', c['name'], status, json.dumps(body)[:300])
print('definitions', len(defs), 'evaluator', len(cases), 'failures', bad)
PY
cat "$OUT/vectors.txt" | tail -5 | sed 's/^/    | /'
eq "$(tail -1 "$OUT/vectors.txt" | awk '{print $6}')" 0 "every definition and evaluator vector, over HTTP"
eq "$(tail -1 "$OUT/vectors.txt" | awk '{print ($2 >= 40 && $4 >= 40) ? "enough" : "too few"}')" enough "and there were enough of them to mean something"

say "SKAN encodings name full goals: the device evaluates them"
eq "$(api POST /apps '{"app_key":"990088801","app_name":"Summit Run"}')" 201 "an iOS app"
RID=$(field "d['data']['registration_id']")
TOKEN=$(field "d['data']['app_token']")
goal() { # name definition-json -> goal id in $GID
    api POST /goals "{\"scope\":\"registration\",\"scope_id\":$RID,\"definition\":$2}" > "$OUT/goal.status"
    GID=$(field "d['data']['goal_id']")
    eq "$(cat "$OUT/goal.status")" 201 "goal: $1"
}
goal "install (worth \$1)" '{"name":"Install","trigger":{"install":true},"value":{"type":"fixed","amount":1}}'; G_INSTALL=$GID
goal "tutorial" '{"name":"Tutorial","trigger":{"event":"tutorial_complete"}}'; G_TUT=$GID
goal "level 3 after the tutorial, in the first week" "{\"name\":\"Level 3\",\"trigger\":{\"event\":\"level_reached\",\"where\":[{\"prop\":\"level\",\"op\":\"gte\",\"value\":3}]},\"after\":[$G_TUT],\"within\":{\"days\":7,\"from\":\"install\"},\"value\":{\"type\":\"fixed\",\"amount\":4}}"; G_L3=$GID
goal "\$20 of purchases" '{"name":"Spender","trigger":{"event":"purchase"},"threshold":{"sum":{"prop":"$revenue","gte":20}},"value":{"type":"from_property"}}'; G_SPEND=$GID
goal "a purchase within a day of the click" '{"name":"Fast buyer","trigger":{"event":"purchase"},"within":{"days":1,"from":"click"}}'; G_CLICK=$GID
goal "a goal waiting for that one" "{\"name\":\"Repeat buyer\",\"trigger\":{\"event\":\"purchase\"},\"after\":[$G_CLICK]}"; G_AFTERCLICK=$GID

enc() { api POST /apps/skan-encodings "{\"registration_id\":$RID,$1}"; }
eq "$(enc "\"fine_value\":1,\"goal_id\":$G_INSTALL")" 201 "the install goal is encoded"
eq "$(enc "\"coarse_value\":\"low\",\"goal_id\":$G_INSTALL")" 201 "with a coarse value too"
eq "$(enc "\"fine_value\":20,\"goal_id\":$G_L3")" 201 "a funnel goal (after + where + install window) is encoded"
ENC_L3=$(field "d['data']['encoding_id']")
eq "$(enc "\"coarse_value\":\"medium\",\"goal_id\":$G_L3")" 201 "with a coarse value too"
eq "$(enc "\"fine_value\":40,\"goal_id\":$G_SPEND,\"revenue_override\":20")" 201 "a cumulative-sum goal is encoded"
eq "$(enc "\"fine_value\":41,\"goal_id\":$G_CLICK")" 422 "a goal counting from the click is refused"
has "$OUT/body" "never tells an app which click" "saying why a device can never reach it"
eq "$(enc "\"fine_value\":42,\"goal_id\":$G_AFTERCLICK")" 422 "and so is a goal waiting for one"
has "$OUT/body" "waits for goal $G_CLICK" "naming the goal it waits for"
eq "$(api PUT "/goals/$G_TUT" '{"definition":{"name":"Tutorial","trigger":{"event":"tutorial_complete"},"within":{"days":1,"from":"click"}}}')" 422 \
   "a goal an encoded goal waits for cannot be edited to count from the click"
has "$OUT/body" "which waits for this one" "saying which encoded goal depends on it"
eq "$(Q "SELECT current_version FROM 202_goals WHERE goal_id=$G_TUT")" 1 "and no version was written"

say "GET /apps/schema: the goals, evaluation-only, and the encodings"
eq "$(schema)" 200 "the iOS document"
cp "$OUT/body" "$OUT/schema.json"
eq "$(field "[g['goal_id'] for g in d['data']['goals']]")" "[$G_INSTALL, $G_TUT, $G_L3, $G_SPEND]" \
   "every encoded goal, and the tutorial it waits for (not encoded itself)"
eq "$(field "sorted([e['goal_id'], e['fine_value'], e['coarse_value']] for e in d['data']['encodings'])")" \
   "$(python3 -c "import json; print(json.dumps(sorted([[$G_INSTALL, 1, 'low'], [$G_L3, 20, 'medium'], [$G_SPEND, 40, None]])))")" "the encodings, per goal"
eq "$(field "any('value' in v['definition'] for g in d['data']['goals'] for v in g['versions'])")" false "no definition carries its value"
hasnt "$OUT/body" "revenue_override" "no revenue override"
hasnt "$OUT/body" '"amount"' "and no goal amount"
hasnt "$OUT/body" "from_property" "not even the kind of value"
eq "$(field "d['data']['goals'][2]['versions'][0]['definition']['within']")" '{"days": 7, "from": "install"}' "the window is served"
ETAG=$(header ETag)

CLI="$OUT/p202"
if (cd "$ROOT/go-cli" && go build -o "$CLI" .) 2> "$OUT/build.err"; then
  mkdir -p "$OUT/home/.p202"
  printf '{"url": "%s", "api_key": "%s"}' "$BASE" "$P202_API_KEY" > "$OUT/home/.p202/config.json"
  HOME="$OUT/home" "$CLI" app schema "$RID" --json > "$OUT/cli-schema.json" 2> "$OUT/cli.err"
  eq "$(python3 -c "import json,sys; a=json.load(open(sys.argv[1])); b=json.load(open(sys.argv[2])); [x.get('data', x).pop('generated_at', None) for x in (a, b)]; print(a.get('data', a) == b.get('data', b))" "$OUT/cli-schema.json" "$OUT/schema.json")" True \
     "p202 app schema shows the same document"
  HOME="$OUT/home" "$CLI" app encoding create --registration-id "$RID" --fine-value 43 --goal-id "$G_CLICK" --json > /dev/null 2> "$OUT/cli.err"
  eq "$?" 1 "p202 app encoding create refuses the click goal (exit 1)"
  has "$OUT/cli.err" '\"from\": \"click\"' "with a hint naming the rule, not the retired plain-goal one"
else
  bad "the CLI builds ($(head -3 "$OUT/build.err"))"
fi

say "the Swift SDK against the live document"
if [ -n "$SWIFT" ] && [ -x "$SWIFT" ]; then
  # A fresh device: configure (the first launch is the install), fetch, then
  # the funnel. Level 3 before the tutorial reaches nothing; the tutorial is
  # not encoded; level 2 is not level 3; level 3 sets 20; purchases sum to
  # $20 on the third and set 40.
  EXPECT='[["level_reached",{"level":3},null],["tutorial_complete",{},null],["level_reached",{"level":2},null],["level_reached",{"level":3},20],["level_reached",{"level":4},null]]'
  (cd "$ROOT/sdk/ios-attribution" && P202ATTRIBUTION_LIVE_ENDPOINT="$BASE" P202ATTRIBUTION_LIVE_TOKEN="$TOKEN" P202ATTRIBUTION_LIVE_EXPECT="$EXPECT" \
     "$SWIFT" test ${P202_SWIFT_SCRATCH:+--scratch-path "$P202_SWIFT_SCRATCH"} --filter LiveServerIntegrationTests) > "$OUT/swift.txt" 2>&1
  eq "$?" 0 "swift test --filter LiveServerIntegrationTests passes against this instance"
  eq "$(grep -cE "Test Case '.*LiveServerIntegrationTests.*' passed" "$OUT/swift.txt")" 2 "both live tests ran and passed (none skipped)"
  hasnt "$OUT/swift.txt" "skipped" "nothing was skipped"
  tail -3 "$OUT/swift.txt" | sed 's/^/    | /'
else
  notrun "P202_SWIFT is not set to a swift binary: the SDK's own live suite did not run"
fi

say "encoding versions: every edit and delete keeps the meaning it replaced"
eq "$(api PUT "/apps/skan-encodings/$ENC_L3" "{\"goal_id\":$G_SPEND}")" 200 "fine 20 now means the spender goal"
EDIT_AT=$(field "d['data']['effective_at']")
eq "$(Q "SELECT CONCAT(encoding_id, '/', goal_id, '/', fine_value, '/', retired_at) FROM 202_app_skan_encoding_history")" "$ENC_L3/$G_L3/20/$EDIT_AT" \
   "the old meaning is kept, retired when the new one took effect"
eq "$(schema -H "If-None-Match: $ETAG")" 200 "the document changed"
eq "$(api PUT "/apps/skan-encodings/$ENC_L3" '{"effective_at":1}')" 422 "effective_at is the server's to set"

# The meaning fine 20 had (level 3) started long ago, and the edit was made
# EDIT days ago: move both back in the database so the report has a before,
# a during and an after to decode. The controller stamps real times; only
# the clock is moved, not the rows' meaning.
EDIT=$(( $(date +%s) - 10 * 86400 ))
Q "UPDATE 202_app_skan_encoding_history SET effective_at = $EDIT - 400 * 86400, retired_at = $EDIT WHERE encoding_id = $ENC_L3"
Q "UPDATE 202_app_skan_encodings SET effective_at = $EDIT WHERE encoding_id = $ENC_L3"
H=$((35 * 86400))
pb() { # n received_at fine
  Q "INSERT INTO 202_app_postbacks (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id,
       conversion_value, postback_sequence_index, conversion_type, redownload, did_win, attribution_signature, signature_state, trusted,
       dedupe_hash, raw_payload, remote_ip, created_at)
     VALUES ($OWNER, $RID, $2, 'skadnetwork', '4.0', 'ios-sdk.skadnetwork', 'ios-sdk-$1', 990088801, $3, 0, 'download', 0, 1, 'sig', 'valid', 1,
       SHA1('ios-sdk-$1'), '{}', '198.51.100.8', $2)"
}
pb 1 $((EDIT - 3 * 86400)) 20      # before the edit: level 3
pb 2 $((EDIT + 2 * 86400)) 20      # inside the horizon: ambiguous
pb 3 $((EDIT + H - 1)) 20          # still inside
pb 4 $((EDIT + H)) 20              # after it: spender
pb 5 $((EDIT + 2 * 86400)) 1       # never edited: install
pb 6 $((EDIT + 2 * 86400)) 55      # never meant anything
eq "$(api GET "/apps/report?group_by=registration&time_from=0&time_to=$((EDIT + 400 * 86400))")" 200 "the report"
eq "$(field "[d['data']['groups'][0][k] for k in ('measurable', 'decoded', 'ambiguous_encoding', 'undecoded')]")" "[6, 3, 2, 1]" \
   "decoded before the edit and after the horizon, ambiguous inside it, undecoded where nothing ever applied"
# Spender's value is from_property, so a decode through an encoding with no
# override is worth nothing: there is no fixed amount to report.
eq "$(field "sorted([k, v['count'], v['revenue']] for k, v in d['data']['groups'][0]['events'].items())")" \
   '[["Install", 1, 1], ["Level 3", 1, 4], ["Spender", 1, 0]]' "each decode credited to its own meaning; the ambiguous ones to none"
has "$OUT/body" "ambiguous_encoding and is credited to no goal" "the notes say what ambiguous_encoding is"

eq "$(api DELETE "/apps/skan-encodings/$ENC_L3")" 204 "deleting the encoding"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encoding_history WHERE encoding_id = $ENC_L3")" 2 "keeps its last meaning too"
Q "UPDATE 202_app_skan_encoding_history SET retired_at = $((EDIT + 5 * 86400)) WHERE encoding_id = $ENC_L3 AND goal_id = $G_SPEND"
pb 7 $((EDIT + 5 * 86400 + H - 1)) 20   # set before the delete, arriving inside its horizon
eq "$(api GET "/apps/report?group_by=registration&time_from=$((EDIT + 5 * 86400 + H - 10))&time_to=$((EDIT + 5 * 86400 + H))")" 200 "the report after the delete"
eq "$(field "[d['data']['groups'][0][k] for k in ('decoded', 'ambiguous_encoding')]")" "[1, 0]" "a postback set before the delete still decodes"
eq "$(field "list(d['data']['groups'][0]['events'])")" '["Spender"]' "under the meaning it had"

say "Setup › Mobile Apps says so when a rule is edited"
if [ -n "$P202_PASS" ]; then
  JAR=$(mktemp)
  curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
  LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
  curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
    --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o /dev/null
  RULE=$(Q "SELECT encoding_id FROM 202_app_skan_encodings WHERE registration_id = $RID AND fine_value = 40")
  curl -sS -b "$JAR" -c "$JAR" "$BASE/tracking202/setup/mobile_apps.php?app=$RID&rule_edit=$RULE" -o "$OUT/page.html"
  TOK=$(grep -oE 'name="csrf_token" value="[^"]+"' "$OUT/page.html" | head -1 | sed 's/.*value="//; s/"//')
  curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/mobile_apps.php" --data-urlencode "csrf_token=$TOK" \
    --data-urlencode action=rule_save --data-urlencode "registration_id=$RID" --data-urlencode "rule_id=$RULE" \
    --data-urlencode kind=fine --data-urlencode fine_value=40 --data-urlencode event_name=big_purchase --data-urlencode revenue=25 -o "$OUT/edited.html"
  has "$OUT/edited.html" "Conversion value changed." "the edit is confirmed"
  has "$OUT/edited.html" "reported as ambiguous_encoding" "with what it does to the report"
  has "$OUT/edited.html" "$(date -u -d "@$(( $(date +%s) + H ))" '+%-d %b %Y')" "and the date it is exact again"
  eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encoding_history WHERE encoding_id = $RULE")" 1 "the Setup page's edit kept the old meaning too"

  # The page's copy-paste snippet is code someone will paste into an app:
  # compile it against the SDK in this checkout.
  python3 - "$OUT/edited.html" > "$OUT/snippet.swift" <<'PY'
import html, re, sys
page = open(sys.argv[1]).read()
m = re.search(r'data-p202-copy="([^"]*P202Attribution[^"]*)"', page)
if m:
    print(html.unescape(m.group(1)))
PY
  has "$OUT/snippet.swift" "appToken:" "the Setup page's Swift snippet uses the SDK's appToken parameter"
  if [ -z "$(tr -d '[:space:]' < "$OUT/snippet.swift")" ]; then
    bad "the Setup page carries a Swift snippet to copy"
  elif [ -n "$SWIFT" ] && [ -x "$SWIFT" ]; then
    mkdir -p "$OUT/snippet/Sources/Snippet"
    printf '// swift-tools-version:5.9\nimport PackageDescription\nlet package = Package(name: "Snippet", dependencies: [.package(path: "%s")],\n  targets: [.executableTarget(name: "Snippet", dependencies: [.product(name: "P202Attribution", package: "ios-attribution")])])\n' \
      "$ROOT/sdk/ios-attribution" > "$OUT/snippet/Package.swift"
    { echo 'import Foundation'; echo 'import P202Attribution'; echo 'func pasted() throws {'; cat "$OUT/snippet.swift"; echo '}'; } > "$OUT/snippet/Sources/Snippet/main.swift"
    (cd "$OUT/snippet" && "$SWIFT" build) > "$OUT/snippet.txt" 2>&1
    eq "$?" 0 "and compiles against it as pasted"
  else
    notrun "P202_SWIFT is not set: the snippet was not compiled"
  fi
else
  notrun "P202_PASS is not set: the Setup page step did not run"
fi

printf '\n\033[1m%d passed, %d failed, %d not run\033[0m\n' "$PASS" "$FAIL" "$NOTRUN"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
