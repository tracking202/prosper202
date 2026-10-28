#!/bin/bash
# Live pass: My Account › API Integrations' Landing Page Optimizer card says
# whether the optimizer receives events, from the status of the webhook its
# pairing made. It said "Connected" whatever that webhook's status was.
#
#   1. signed in through 202-login.php's own form; a wildcard webhook made
#      through the API, and the pairing written as the card's Connect writes
#      it (lpo_status active, lpo_bridge_config naming the webhook): the
#      pairing itself calls the hosted optimizer, which this pass does not;
#   2. active: "Connected", nothing more said;
#   3. dead (as the dispatcher marks it): "Not receiving events", the
#      sentence naming the webhook, and a link to LTV Settings that opens;
#   4. deleted through the API: "Not receiving events", and Disconnect then
#      Connect to pair a new one; no LTV Settings link;
#   5. a pairing record naming no webhook: "Status unknown", never Connected.
#
# Puts the account's pairing back as it found it on exit.
#
#   P202_BASE=http://127.0.0.1:8321 P202_DB=p202_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   P202_USER=... P202_PASS=... bash tests/live/lpo-card-webhook-status.sh

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
    echo "P202_API_KEY and P202_PASS are required: this pass makes its webhook through the API and signs in to read the card" >&2
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
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3 (not in the card)"; fi; }
hasnt() { if grep -qF -- "$2" "$1"; then bad "$3 (in the card)"; else ok "$3"; fi; }
api()  { curl -s -H "Authorization: Bearer $P202_API_KEY" -H 'Content-Type: application/json' "$@"; }
json() { "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) ? ($d[$k] ?? null) : null; } echo is_scalar($d) ? $d : json_encode($d);' "$1"; }
# The Landing Page Optimizer card, its entities decoded, into $1.
card() {
    curl -sS -b "$JAR" -c "$JAR" -o "$WORK/page.html" "$BASE/202-account/api-integrations.php"
    "$PHP" -r '$h = (string) file_get_contents($argv[1]); $s = strpos($h, "id=\"lpo\""); if ($s === false) { exit(1); } $e = strpos($h, "</section>", $s); echo html_entity_decode(substr($h, $s, $e - $s), ENT_QUOTES | ENT_HTML5, "UTF-8");' "$WORK/page.html" > "$1" \
        || { echo "the page drew no Landing Page Optimizer card: $(head -c 300 "$WORK/page.html")" >&2; exit 2; }
}

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$P202_USER'")
[[ "$USER_ID" =~ ^[1-9][0-9]*$ ]] || { echo "no user '$P202_USER' in $DB" >&2; exit 2; }
SAVED=$(Q "SELECT CONCAT_WS(CHAR(31), lpo_status, lpo_site_key, IFNULL(lpo_bridge_config, '')) FROM 202_users_pref WHERE user_id = $USER_ID")
HOOK=
cleanup() {
    IFS=$'\x1f' read -r status site config <<< "$SAVED"
    mysql "${MYSQL_ARGS[@]}" "$DB" -e "UPDATE 202_users_pref SET lpo_status = '$status', lpo_site_key = '$site', lpo_bridge_config = '$config' WHERE user_id = $USER_ID"
    [ -n "$HOOK" ] && api -X DELETE "$BASE/api/v3/ltv/webhooks/$HOOK" >/dev/null
    rm -rf "$WORK"
}
trap cleanup EXIT
pair() { Q "UPDATE 202_users_pref SET lpo_status = 'active', lpo_site_key = 'live-pass-site', lpo_bridge_config = '$1' WHERE user_id = $USER_ID"; }

say "1. signed in; a wildcard webhook and the pairing that names it"
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/login.html" "$BASE/202-login.php"
python3 "$HERE/form-body.py" "$WORK/login.html" user_name "user_name=$P202_USER" "user_pass=$P202_PASS" > "$WORK/login.body" || exit 2
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-binary @"$WORK/login.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-login.php"
HOOK=$(api -X POST "$BASE/api/v3/ltv/webhooks" -d '{"url":"https://lpo.example/hook","events":["*"]}' | json data.webhook_id)
[[ "$HOOK" =~ ^[1-9][0-9]*$ ]] || { echo "the API did not make the webhook (got '$HOOK')" >&2; exit 2; }
pair "{\"webhook_id\":$HOOK,\"config\":null,\"fetched_at\":0}"

say "2. the webhook is active"
card "$WORK/card"
has "$WORK/card" 'p202-pill--good">Connected<' 'the card reads Connected'
hasnt "$WORK/card" 'p202-flash' 'and says nothing more'

say "3. the webhook is dead"
Q "UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = $HOOK"
card "$WORK/card"
has "$WORK/card" 'p202-pill--bad">Not receiving events<' 'the card reads Not receiving events'
hasnt "$WORK/card" '>Connected<' 'not Connected'
has "$WORK/card" "The optimizer is not receiving events: its webhook (#$HOOK) was stopped after a delivery to it failed 6 times. Fix the endpoint, then revive the webhook in LTV Settings." 'and says why, and where to revive it'
LINK=$(grep -o 'href="[^"]*tracking202/analyze/ltv.php?view=settings"' "$WORK/card" | head -1 | sed 's/^href="//; s/"$//')
[ -n "$LINK" ] && ok "with a link to LTV Settings ($LINK)" || bad 'no LTV Settings link'
case "$LINK" in http*) URL=$LINK ;; *) URL="$BASE/${LINK#/}" ;; esac
eq "$(curl -sS -b "$JAR" -o "$WORK/ltv.html" -w '%{http_code}' "$URL")" 200 'the link opens'
grep -q 'ltvNav\|ltv.js' "$WORK/ltv.html" && ok 'on the Customer LTV page' || bad 'the link did not open the Customer LTV page'

say "4. the webhook no longer exists"
eq "$(api -X DELETE -o /dev/null -w '%{http_code}' "$BASE/api/v3/ltv/webhooks/$HOOK")" 204 'deleted through the API'
card "$WORK/card"
has "$WORK/card" 'p202-pill--bad">Not receiving events<' 'the card reads Not receiving events'
has "$WORK/card" "its webhook (#$HOOK) no longer exists on this install. Disconnect, then connect again to pair a new one." 'and says to pair again'
hasnt "$WORK/card" 'ltv.php?view=settings' 'with nothing to revive'
HOOK=

say "5. a pairing record that names no webhook"
pair '{"config":null}'
card "$WORK/card"
has "$WORK/card" 'p202-pill--warn">Status unknown<' 'the card reads Status unknown'
hasnt "$WORK/card" '>Connected<' 'not Connected'

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
