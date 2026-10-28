#!/bin/bash
# Live pass: LTV Settings reads the row a button names exactly, and refuses
# anything else in its own words. Delete read webhook_id with (int), so
# "12abc" deleted webhook 12: one of the account's own, not the one named.
#
#   1. signed in through 202-login.php's own form; two webhooks, a field and
#      an integration made through the API;
#   2. Delete with the first webhook's id as Nabc, 0, -1, empty and absent:
#      each refused with "Pick the webhook to delete from the list.", both
#      webhooks still listed by GET /ltv/webhooks; then N deletes that one
#      and leaves the other;
#   3. Revive (the other, marked dead here) with Nabc refused and still dead,
#      then N active; the field's and the integration's Delete the same;
#   4. a delivery log asked for as Nabc refused, and no log shown;
#   5. the prefs form submitted with cparam=1abc refused and the c-param left
#      as stored; the webhook form submitted with no event ticked refused and
#      no webhook made.
#
# A button's POST is the one its script makes (ltvSettingsAction: action, the
# page's token, the id); a form's is its own fields (form-body.py) with one
# changed. Deletes what it made on exit.
#
#   P202_BASE=http://127.0.0.1:8321 P202_DB=p202_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   P202_USER=... P202_PASS=... bash tests/live/ltv-settings-posted-ids.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
PHP=${P202_PHP:-php}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ -z "$P202_API_KEY" ] || [ -z "$P202_PASS" ]; then
    echo "P202_API_KEY and P202_PASS are required: this pass makes its rows through the API and signs in to press the buttons" >&2
    exit 2
fi
for tool in curl mysql python3; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
done

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

PASS=0; FAIL=0
WORK=$(mktemp -d)
JAR="$WORK/jar"
PAGE="$BASE/tracking202/ajax/ltv_settings.php"
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
json() { "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) ? ($d[$k] ?? null) : null; } echo is_bool($d) ? var_export($d, true) : (is_scalar($d) ? $d : json_encode($d));' "$1"; }
# The flash the page drew, its entities decoded.
flash() { "$PHP" -r '$h = (string) file_get_contents($argv[1]); preg_match_all("#p202-flash__body\">(.*?)</div>#s", $h, $m); echo html_entity_decode(strip_tags(implode(" | ", $m[1])), ENT_QUOTES | ENT_HTML5, "UTF-8");' "$1"; }

HOOK_A=; HOOK_B=; FIELD=; INTEGRATION=
cleanup() {
    for id in $HOOK_A $HOOK_B; do api -X DELETE "$BASE/api/v3/ltv/webhooks/$id" >/dev/null; done
    [ -n "$FIELD" ] && api -X DELETE "$BASE/api/v3/ltv/fields/$FIELD" >/dev/null
    [ -n "$INTEGRATION" ] && api -X DELETE "$BASE/api/v3/ltv/integrations/$INTEGRATION" >/dev/null
    rm -rf "$WORK"
}
trap cleanup EXIT

# A button's POST: the action, the page's token, the id as given ("-" for none).
press() { # $1 action, $2 id field, $3 value or -, $4 output
    local body="action=$1&token=$TOKEN"
    # `--`: a value such as -1 is the page's to read, not an option of php's.
    [ "$3" != "-" ] && body="$body&$2=$("$PHP" -r 'echo rawurlencode($argv[1]);' -- "$3")"
    printf '%s' "$body" > "$4.sent"
    curl -sS -b "$JAR" -c "$JAR" -o "$4" --data "$body" "$PAGE"
}
hook_status() { api "$BASE/api/v3/ltv/webhooks" | "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] ?? [] as $h) { if ((int) $h["webhook_id"] === (int) $argv[1]) { echo $h["status"]; exit; } } echo "gone";' "$1"; }

say "1. signed in; two webhooks, a field and an integration through the API"
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/login.html" "$BASE/202-login.php"
python3 "$HERE/form-body.py" "$WORK/login.html" user_name "user_name=$P202_USER" "user_pass=$P202_PASS" > "$WORK/login.body" || exit 2
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-binary @"$WORK/login.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-login.php"
HOOK_A=$(api -X POST "$BASE/api/v3/ltv/webhooks" -d '{"url":"https://hooks.example/a","events":["revenue.recorded"]}' | json data.webhook_id)
HOOK_B=$(api -X POST "$BASE/api/v3/ltv/webhooks" -d '{"url":"https://hooks.example/b","events":["revenue.recorded"]}' | json data.webhook_id)
FIELD=$(api -X POST "$BASE/api/v3/ltv/fields" -d '{"field_key":"posted_ids_pass","label":"Pass","field_type":"text"}' | json data.field_id)
INTEGRATION=$(api -X POST "$BASE/api/v3/ltv/integrations" -d '{"provider":"postedidspass","name":"Pass"}' | json data.integration_id)
for v in "$HOOK_A" "$HOOK_B" "$FIELD" "$INTEGRATION"; do
    [[ "$v" =~ ^[1-9][0-9]*$ ]] || { echo "the API did not make the rows (got '$HOOK_A' '$HOOK_B' '$FIELD' '$INTEGRATION')" >&2; exit 2; }
done
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/settings.html" "$PAGE"
TOKEN=$(grep -o "token: \"[0-9a-f]*\"" "$WORK/settings.html" | head -1 | sed 's/token: "//; s/"//')
[ -n "$TOKEN" ] || { echo "the settings page did not render signed in: $(head -c 300 "$WORK/settings.html")" >&2; exit 2; }
grep -q "'delete_webhook', 'webhook_id', $HOOK_A," "$WORK/settings.html" \
    && ok "webhook #$HOOK_A's row has its Delete button" || bad "no Delete button for webhook #$HOOK_A"

say "2. Delete reads the webhook id exactly"
for v in "${HOOK_A}abc" 0 -1 '' -; do
    press delete_webhook webhook_id "$v" "$WORK/out.html"
    eq "$(flash "$WORK/out.html")" 'Pick the webhook to delete from the list.' "webhook_id '$v' ($(cat "$WORK/out.html.sent")) is refused in the page's words"
done
eq "$(hook_status "$HOOK_A")" active "webhook #$HOOK_A is still there"
eq "$(hook_status "$HOOK_B")" active "and webhook #$HOOK_B"
press delete_webhook webhook_id "$HOOK_A" "$WORK/out.html"
eq "$(flash "$WORK/out.html")" 'Webhook deleted.' "webhook_id '$HOOK_A' deletes it"
eq "$(hook_status "$HOOK_A")" gone "webhook #$HOOK_A is gone"
eq "$(hook_status "$HOOK_B")" active "webhook #$HOOK_B is not"
HOOK_A=

say "3. Revive, and the field's and the integration's Delete, read their ids the same way"
Q "UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = $HOOK_B"
press revive_webhook webhook_id "${HOOK_B}abc" "$WORK/out.html"
eq "$(flash "$WORK/out.html")" 'Pick the webhook to revive from the list.' "revive '${HOOK_B}abc' is refused"
eq "$(hook_status "$HOOK_B")" dead 'and the webhook is still dead'
press revive_webhook webhook_id "$HOOK_B" "$WORK/out.html"
eq "$(hook_status "$HOOK_B")" active "revive '$HOOK_B' makes it active"
press delete_field field_id "${FIELD}abc" "$WORK/out.html"
eq "$(flash "$WORK/out.html")" 'Pick the field to delete from the list.' "field_id '${FIELD}abc' is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_customer_fields WHERE field_id = $FIELD")" 1 'and the field stays'
press delete_field field_id "$FIELD" "$WORK/out.html"
eq "$(Q "SELECT COUNT(*) FROM 202_customer_fields WHERE field_id = $FIELD")" 0 "field_id '$FIELD' deletes it"
FIELD=
press delete_integration integration_id "${INTEGRATION}abc" "$WORK/out.html"
eq "$(flash "$WORK/out.html")" 'Pick the integration to delete from the list.' "integration_id '${INTEGRATION}abc' is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_ltv_integrations WHERE integration_id = $INTEGRATION")" 1 'and the integration stays'
press delete_integration integration_id "$INTEGRATION" "$WORK/out.html"
eq "$(Q "SELECT COUNT(*) FROM 202_ltv_integrations WHERE integration_id = $INTEGRATION")" 0 "integration_id '$INTEGRATION' deletes it"
INTEGRATION=

say "4. a delivery log is shown only for the webhook named"
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/out.html" --data "show_deliveries=${HOOK_B}abc" "$PAGE"
eq "$(flash "$WORK/out.html")" 'Pick the webhook whose log to show from the list.' "show_deliveries '${HOOK_B}abc' is refused"
grep -q 'Delivery Log' "$WORK/out.html" && bad 'a log was shown' || ok 'no log is shown'
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/out.html" --data "show_deliveries=$HOOK_B" "$PAGE"
grep -q "webhook #$HOOK_B, most recent 25" "$WORK/out.html" && ok "show_deliveries '$HOOK_B' shows its log" || bad "no log for '$HOOK_B'"

say "5. the forms: the c-param and the events ticked"
STORED=$(Q "SELECT user_ltv_customer_cparam FROM 202_users_pref p JOIN 202_users u USING (user_id) WHERE u.user_name = '$P202_USER'")
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/settings.html" "$PAGE"
python3 "$HERE/form-body.py" "$WORK/settings.html" p13n_fields "cparam=1abc" > "$WORK/prefs.body" || exit 2
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/out.html" --data-binary @"$WORK/prefs.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$PAGE"
eq "$(flash "$WORK/out.html")" 'Customer c-param must be Off or c1–c4.' "the prefs form with cparam '1abc' is refused"
eq "$(Q "SELECT user_ltv_customer_cparam FROM 202_users_pref p JOIN 202_users u USING (user_id) WHERE u.user_name = '$P202_USER'")" "$STORED" 'and the stored c-param is unchanged'
HOOKS=$(Q "SELECT COUNT(*) FROM 202_ltv_webhooks")
# The form as a browser sends it with every box unticked: no webhook_events at all.
python3 "$HERE/form-body.py" "$WORK/settings.html" webhook_url "webhook_url=https://hooks.example/c" \
    | python3 -c 'import sys, urllib.parse as u; print(u.urlencode([kv for kv in u.parse_qsl(sys.stdin.read().strip(), keep_blank_values=True) if kv[0] != "webhook_events[]"]))' > "$WORK/hook.body"
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/out.html" --data-binary @"$WORK/hook.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$PAGE"
eq "$(flash "$WORK/out.html")" 'Tick the events this webhook is sent.' 'the webhook form with no event ticked is refused'
eq "$(Q "SELECT COUNT(*) FROM 202_ltv_webhooks")" "$HOOKS" 'and no webhook is made'

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
