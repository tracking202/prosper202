#!/bin/bash
# Live pass: Account › API integrations shows the signed-in account's own
# Direct Network Integrations and no network's key (CLAUDE.md #27).
#
# The page listed `WHERE user_id = '1'` to every admin, each key in a Reveal
# attribute and the importing ones' keys in the progress poller's inline
# JSON. Seeds a network of user 1's and two of the signed-in account's (rows
# written directly: the hosted DNI service is not reachable from a test
# instance), signs in as that account and reads the page, an edit, the
# edit's keep-the-key and replace-the-key saves, a keep-the-key save naming
# user 1's network, and the progress endpoint.
#
# The saves authenticate with my.tracking202.com. Outside CI that is the
# licence stub (licence-stub.sh), which answers every DNI call 200 `{}`, so a
# save is answered as authenticated: the pass reads what was stored, not
# whether a real network would take the key. Point it at the base tree to see
# the leak, at the fixed tree to see it closed.
#
#   P202_BASE=http://127.0.0.1:8110 P202_DB=p202_yy12_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_USER=second P202_PASS=... \
#   P202_DB_ALLOW_DESTRUCTIVE=yes-i-mean-it bash tests/live/dni-own-account.sh
#
# P202_USER is an account other than user 1 whose role has
# access_to_api_integrations. Writes only its own 202_dni_networks rows
# (networkId yy12-*) and removes them on exit.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-}
P202_PASS=${P202_PASS:-}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
if [ -z "$P202_USER" ] || [ -z "$P202_PASS" ]; then
    echo "P202_USER and P202_PASS must name an account other than user 1 that may open API integrations." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
JAR="$OUT/jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

OWNER_KEY='yy12-owner-secret-key-0001'
MY_KEY='yy12-own-secret-key-0002'
MY_OTHER_KEY='yy12-own-secret-key-0003'
NEW_KEY='yy12-replaced-secret-key-0004'
cleanup() { Q "DELETE FROM 202_dni_networks WHERE networkId LIKE 'yy12-%'"; rm -rf "$OUT"; }
trap cleanup EXIT

ME=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$P202_USER'")
if [ -z "$ME" ] || [ "$ME" = "1" ]; then
    echo "P202_USER must be an existing account other than user 1 (found '$ME')." >&2
    exit 2
fi

say "seed: a network of user 1's and two of $P202_USER's (user $ME)"
Q "DELETE FROM 202_dni_networks WHERE networkId LIKE 'yy12-%'"
Q "INSERT INTO 202_dni_networks (user_id, networkId, name, type, apiKey, affiliateId, time, processed, shortDescription, favIcon) VALUES
   (1, 'yy12-owner', 'YY12 Owner Network', 'HasOffers', '$OWNER_KEY', NULL, UNIX_TIMESTAMP(), 0, 'owner', ''),
   ($ME, 'yy12-mine', 'YY12 My Network', 'HasOffers', '$MY_KEY', NULL, UNIX_TIMESTAMP(), 0, 'mine', ''),
   ($ME, 'yy12-mine2', 'YY12 My Other', 'Cake', '$MY_OTHER_KEY', 555, UNIX_TIMESTAMP(), 1, 'mine too', '')"
OWNERS=$(Q "SELECT id FROM 202_dni_networks WHERE networkId = 'yy12-owner'")
MINE=$(Q "SELECT id FROM 202_dni_networks WHERE networkId = 'yy12-mine'")
ok "seeded (user 1's is $OWNERS, $P202_USER's are $MINE and another)"

say "sign in as $P202_USER"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get() { curl -sS -b "$JAR" -c "$JAR" "$BASE/$1" -o "$2" -w '%{http_code}'; }
CODE=$(get "202-account/api-integrations.php" "$OUT/ai.html")
if [ "$CODE" != "200" ] || grep -q 'name="user_pass"' "$OUT/ai.html"; then
    bad "API integrations opens as $P202_USER (HTTP $CODE)"
    exit 1
fi
ok "API integrations opens as $P202_USER"

nokeys() { # FILE LABEL
    local shown
    shown=$(grep -oE 'yy12-[a-z]+-secret-key-[0-9]+' "$1" | sort -u | paste -sd ',' -)
    if [ -n "$shown" ]; then bad "$2 holds a DNI key: $shown"; else ok "$2 holds no DNI key"; fi
}

say "the list: the account's own networks, no key"
has "$OUT/ai.html" 'YY12 My Network (HasOffers)' "its own network is listed"
has "$OUT/ai.html" 'YY12 My Other (Cake)' "and its other one"
hasnt "$OUT/ai.html" 'YY12 Owner Network' "user 1's is not"
nokeys "$OUT/ai.html" "the page"
hasnt "$OUT/ai.html" 'data-p202-value=' "no key behind Reveal"
has "$OUT/ai.html" 'Saved, ends in 0002' "a key shows as saved, by its last four"
has "$OUT/ai.html" "data-dni-progress=\"$MINE\"" "its importing network shows its progress"
hasnt "$OUT/ai.html" "data-dni-progress=\"$OWNERS\"" "user 1's does not"
hasnt "$OUT/ai.html" 'var processing' "the poller is handed no network data"
hasnt "$OUT/ai.html" "$(Q "SELECT install_hash FROM 202_users WHERE user_id = 1")" "nor the install hash"

say "the edit: no key in the form"
get "202-account/api-integrations.php?edit_dni_network=$MINE" "$OUT/edit.html" >/dev/null
has "$OUT/edit.html" 'name="editing_dni_network_id"' "the edit opens"
nokeys "$OUT/edit.html" "the edit"
has "$OUT/edit.html" 'placeholder="Key saved, ends in 0002; leave empty to keep it"' "the key field says a key is saved"
get "202-account/api-integrations.php?edit_dni_network=$OWNERS" "$OUT/edit-owner.html" >/dev/null
has "$OUT/edit-owner.html" 'That DNI network is not one of yours, or it was removed.' "user 1's network opens no edit"
hasnt "$OUT/edit-owner.html" 'YY12 Owner Network' "and names nothing of it"

# The edit form's own fields. The stub lists no networks, so the select
# carries none: the network and its name are the ones the row holds.
editbody() { # PAGE KEY OVERRIDES...
    local page="$1" key="$2"; shift 2
    python3 "$HERE/form-body.py" "$page" editing_dni_network_id dni_network=yy12-mine dni_network_type=HasOffers \
        "dni_network_name=YY12 Renamed (HasOffers)" "dni_network_api_key=$key" "$@"
}
post() { # BODY OUT
    curl -sS -b "$JAR" -c "$JAR" -L --data-binary "$1" -H 'Content-Type: application/x-www-form-urlencoded' \
        "$BASE/202-account/api-integrations.php?edit_dni_network=$MINE" -o "$2" -w '%{http_code}'
}

say "an edit that leaves the key empty keeps the saved one"
post "$(editbody "$OUT/edit.html" '')" "$OUT/keep.html" >/dev/null
has "$OUT/keep.html" 'DNI network updated.' "the save says so"
eq "$(Q "SELECT CONCAT(name, '|', apiKey) FROM 202_dni_networks WHERE id = $MINE")" "YY12 Renamed|$MY_KEY" "renamed, the key kept"
nokeys "$OUT/keep.html" "the page after the save"

say "an edit with a key replaces it"
get "202-account/api-integrations.php?edit_dni_network=$MINE" "$OUT/edit2.html" >/dev/null
post "$(editbody "$OUT/edit2.html" "$NEW_KEY")" "$OUT/replace.html" >/dev/null
has "$OUT/replace.html" 'DNI network updated.' "the save says so"
eq "$(Q "SELECT apiKey FROM 202_dni_networks WHERE id = $MINE")" "$NEW_KEY" "the new key is stored"
has "$OUT/replace.html" 'Saved, ends in 0004' "and shown by its last four"
nokeys "$OUT/replace.html" "the page after the save"

say "a keep-the-key edit naming user 1's network changes nothing"
BEFORE=$(Q "SELECT CONCAT(name, '|', apiKey, '|', time) FROM 202_dni_networks WHERE id = $OWNERS")
get "202-account/api-integrations.php?edit_dni_network=$MINE" "$OUT/edit3.html" >/dev/null
post "$(editbody "$OUT/edit3.html" '' "editing_dni_network_id=$OWNERS" dni_network=yy12-owner)" "$OUT/foreign.html" >/dev/null
has "$OUT/foreign.html" 'That network was not found; nothing was changed.' "refused in the page's sentence"
eq "$(Q "SELECT CONCAT(name, '|', apiKey, '|', time) FROM 202_dni_networks WHERE id = $OWNERS")" "$BEFORE" "user 1's row is as it was"
nokeys "$OUT/foreign.html" "the refusal"

say "a keep-the-key edit to another network asks for its key"
get "202-account/api-integrations.php?edit_dni_network=$MINE" "$OUT/edit4.html" >/dev/null
post "$(editbody "$OUT/edit4.html" '' dni_network=yy12-elsewhere)" "$OUT/elsewhere.html" >/dev/null
has "$OUT/elsewhere.html" 'A different network needs its own API key' "refused"
has "$OUT/elsewhere.html" 'name="editing_dni_network_id"' "and the edit is still open, not an empty add"
eq "$(Q "SELECT networkId FROM 202_dni_networks WHERE id = $MINE")" "yy12-mine" "the network is unchanged"

say "the progress endpoint reads the account's own networks"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -X POST "$BASE/202-account/ajax/dni.php?getProgress=true" -o "$OUT/progress.txt" -w '%{http_code}')
eq "$CODE" "200" "answered (the service, or its stub, was asked)"
Q "UPDATE 202_dni_networks SET processed = 1 WHERE id = $MINE"
if [ "$(Q "SELECT COUNT(*) FROM 202_dni_networks WHERE user_id = $ME AND processed = 0")" != "0" ]; then
    printf '  \033[33mSKIP\033[0m %s\n' "$P202_USER has importing networks this pass did not seed: the empty answer cannot be told apart"
else
    CODE=$(curl -sS -b "$JAR" -c "$JAR" -X POST "$BASE/202-account/ajax/dni.php?getProgress=true" -o "$OUT/progress-none.txt" -w '%{http_code}')
    eq "$CODE|$(cat "$OUT/progress-none.txt")" '200|{"data":[]}' "with none of its own importing, nothing is asked: user 1's is not its"
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
