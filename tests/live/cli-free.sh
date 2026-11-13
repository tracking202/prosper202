#!/bin/bash
# Live pass: the Go CLI is free but needs an active free ClickServer licence
# (1.9.80). api/v3/index.php serves the Go CLI's User-Agent when the
# install's ClickServer key is one my.tracking202.com confirms: valid, with a
# boolean paid (true or false). It refuses it with 402 (category licence)
# otherwise, as CapabilitiesController::cliAccess decides it; a "Key valid"
# that does not say paid is no answer, as an outage is.
#
# Needs P202_LICENCE_STUB=1: my.tracking202.com is CI's stub
# (tests/fixtures/agent-eval/ci/licence-stub.sh), which answers by the key's
# suffix. For a fresh random key with each suffix:
#   -unpaid   "valid, not paid"            served; /capabilities cli and shell true
#   -invalid  "Key not found"              402 licence, the free licence named
#   -refused  no CPM* newsletter (403)     402 licence, the newsletter named
#   -outage   503, a valid answer 2 days   served (the 30-day grace period)
#             old in 202_licence_answers
#   -outage   503, nothing recorded        402 licence, "could not be checked"
#   -undecided "Key not checked" (to the   402 licence, "could not be checked";
#             install's grace header)      served on a valid answer 2 days old
#   -no-paid  "Key valid", no paid         402 licence, "could not be checked",
#   -odd-paid "Key valid", paid "yes"      and nothing recorded for the key;
#                                          served on a valid answer 2 days old,
#                                          which keeps its time
#   no key on the install at all           402 licence, api-key-required.php
# and the built CLI (P202_BIN) on the unpaid key: exit 0 with JSON.
# Without the stub it does nothing: the real service would refuse the test keys.
#
# Restores the install's ClickServer keys and drops the answers it recorded.
#
# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
API_KEY=${P202_API_KEY:?P202_API_KEY, the REST API key of the user whose ClickServer key this pass sets, is required}
USER_ID=${P202_USER_ID:-}
BIN=${P202_BIN:-}
STUB=${P202_LICENCE_STUB:-}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

GO_UA='p202-cli/2.0 (Go)'

set -o pipefail
pass=0
fail=0
ok() { pass=$((pass + 1)); echo "ok   - $1"; }
ko() { fail=$((fail + 1)); echo "FAIL - $1"; [ -n "${2:-}" ] && echo "       $2"; }

mysql_q() { # $1 = SQL; prints the result rows, tab-separated, no header
    local args=(-u"$DB_USER" -N -s)
    [ -n "$DB_PASS" ] && args+=(-p"$DB_PASS")
    [ -n "$DB_HOST" ] && args+=(-h"$DB_HOST")
    [ -n "$DB_PORT" ] && args+=(-P"$DB_PORT" --protocol=TCP)
    mysql "${args[@]}" "$DB" -e "$1"
}

jget() { # jget <dotted.path> reads one value out of the JSON on stdin
    # shellcheck disable=SC2016  # PHP code, not a shell expression
    php -r '
        $v = json_decode(stream_get_contents(STDIN), true);
        foreach (explode(".", $argv[1]) as $k) {
            $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
        }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : "");
    ' "$1"
}

request() { # request <path>: sets STATUS and BODY, as the Go CLI asks
    local out
    out=$(curl -sS -o - -w $'\n%{http_code}' -H "Authorization: Bearer $API_KEY" -A "$GO_UA" "$BASE/api/v3$1")
    STATUS=${out##*$'\n'}
    BODY=${out%$'\n'*}
}

if [ -z "$USER_ID" ]; then
    USER_ID=$(mysql_q "SELECT user_id FROM 202_api_keys WHERE key_hash = SHA2('$API_KEY', 256) LIMIT 1")
    if [ -z "$USER_ID" ]; then
        echo "P202_API_KEY is not in 202_api_keys of $DB: the instance at $BASE and the database must be the same install" >&2
        exit 2
    fi
fi
if ! ORIGINAL_KEY=$(mysql_q "SELECT COALESCE(p202_customer_api_key, '') FROM 202_users WHERE user_id = $USER_ID"); then
    echo "could not read 202_users.p202_customer_api_key for user $USER_ID" >&2
    exit 2
fi
KEYS_TOUCHED=()
OTHER_KEYS=() # "user_id<TAB>key" of the other users on the install, blanked by the no-key case
restore() {
    mysql_q "UPDATE 202_users SET p202_customer_api_key = $( [ -n "$ORIGINAL_KEY" ] && printf "'%s'" "$ORIGINAL_KEY" || echo NULL ) WHERE user_id = $USER_ID" || true
    for row in "${OTHER_KEYS[@]}"; do
        mysql_q "UPDATE 202_users SET p202_customer_api_key = '${row#*$'\t'}' WHERE user_id = ${row%%$'\t'*}" || true
    done
    for k in "${KEYS_TOUCHED[@]}"; do
        mysql_q "DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2('$k', 256)" || true
    done
}
trap restore EXIT

if [ "$STUB" != 1 ]; then
    echo "skip - P202_LICENCE_STUB not set: the licence answers come from CI's stub"
    exit 0
fi

use_key() { # use_key <suffix>: a fresh key with that suffix becomes the install's
    KEY="live-free-$(php -r 'echo bin2hex(random_bytes(6));')-$1"
    KEYS_TOUCHED+=("$KEY")
    mysql_q "UPDATE 202_users SET p202_customer_api_key = '$KEY' WHERE user_id = $USER_ID"
}
expect_served() { # $1 = case name
    if [ "$STATUS" = 200 ]; then ok "$1: 200"; else ko "$1: expected 200" "got $STATUS: $BODY"; fi
}
names_paid_tier() { # true when $1 offers a paid tier: the word "Pro" or "trial", not "Prosper202"
    [[ "$1" =~ (^|[^[:alnum:]])([Pp][Rr][Oo]|[Tt][Rr][Ii][Aa][Ll][Ss]?)([^[:alnum:]]|$) ]]
}
# The probe sees the word and only the word, or no refusal below is checked.
if ! names_paid_tier 'Start a Pro plan.' || names_paid_tier 'The Prosper202 CLI is free'; then
    echo "names_paid_tier does not tell the word from Prosper202" >&2
    exit 2
fi
expect_refused() { # $1 = case name, $2 = words the message must hold
    local category message
    category=$(printf '%s' "$BODY" | jget category)
    message=$(printf '%s' "$BODY" | jget message)
    if [ "$STATUS" = 402 ] && [ "$category" = licence ] && [[ "$message" == *"$2"* ]] && ! names_paid_tier "$message"; then
        ok "$1: 402, category licence, \"$2\""
    else
        ko "$1: expected 402 + category licence + \"$2\" (and no paid tier named)" "got $STATUS: $BODY"
    fi
}

use_key unpaid
request /campaigns; expect_served "valid, not paid: a Go CLI read"
request /capabilities
if [ "$(printf '%s' "$BODY" | jget data.cli)" = true ] && [ "$(printf '%s' "$BODY" | jget data.shell)" = true ]; then
    ok "valid, not paid: /capabilities reports cli and shell true"
else
    ko "valid, not paid: /capabilities should report cli and shell true" "got $STATUS: $BODY"
fi
if [ -n "$BIN" ]; then
    out=$("$BIN" campaign list --json 2>"$HERE/.cli-free-stderr"); code=$?
    err=$(cat "$HERE/.cli-free-stderr"); rm -f "$HERE/.cli-free-stderr"
    if [ "$code" = 0 ] && printf '%s' "$out" | php -r 'exit(is_array(json_decode(stream_get_contents(STDIN), true)) ? 0 : 1);'; then
        ok "valid, not paid: p202 campaign list exits 0 with JSON"
    else
        ko "valid, not paid: p202 campaign list" "exit $code, stderr: $err"
    fi
fi

use_key invalid
request /campaigns; expect_refused "no such key" "active free ClickServer licence"
request /capabilities
if [ "$STATUS" = 200 ] && [ "$(printf '%s' "$BODY" | jget data.cli)" = false ]; then
    ok "no such key: /capabilities stays open and reports cli false"
else
    ko "no such key: /capabilities should answer 200 with cli false" "got $STATUS: $BODY"
fi

use_key refused
request /campaigns; expect_refused "no CPM* newsletter subscription" "CPM* newsletter"

use_key outage
mysql_q "INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at) VALUES (SHA2('$KEY', 256), 1, UNIX_TIMESTAMP() - 2 * 86400)"
request /campaigns; expect_served "unreachable, a valid answer 2 days old"

use_key outage
request /campaigns; expect_refused "unreachable, nothing recorded" "could not be checked"

use_key undecided
request /campaigns; expect_refused "Key not checked, nothing recorded" "could not be checked"

use_key undecided
mysql_q "INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at) VALUES (SHA2('$KEY', 256), 1, UNIX_TIMESTAMP() - 2 * 86400)"
request /campaigns; expect_served "Key not checked, a valid answer 2 days old"

# "Key valid" that does not say paid: no answer. Never confirmed: refused,
# nothing recorded. Confirmed 2 days ago: served from that answer, whose
# time is kept.
for shape in no-paid odd-paid; do
    use_key "$shape"
    request /campaigns; expect_refused "$shape, never confirmed" "could not be checked"
    rows=$(mysql_q "SELECT COUNT(*) FROM 202_licence_answers WHERE key_sha256 = SHA2('$KEY', 256)")
    if [ "$rows" = 0 ]; then ok "$shape, never confirmed: nothing recorded"; else ko "$shape, never confirmed: nothing recorded" "$rows row(s)"; fi
    use_key "$shape"
    mysql_q "INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at) VALUES (SHA2('$KEY', 256), 1, UNIX_TIMESTAMP() - 2 * 86400)"
    request /campaigns; expect_served "$shape, a valid answer 2 days old"
    age=$(mysql_q "SELECT UNIX_TIMESTAMP() - valid_at FROM 202_licence_answers WHERE key_sha256 = SHA2('$KEY', 256)")
    if [ -n "$age" ] && [ "$age" -ge 172000 ]; then ok "$shape: the earlier answer keeps its time"; else ko "$shape: the earlier answer keeps its time" "age ${age:-none}"; fi
done

# No key: the user's, and every other on the install (the owner's key would
# decide for a user without one).
while IFS= read -r row; do
    [ -n "$row" ] && OTHER_KEYS+=("$row")
done < <(mysql_q "SELECT o.user_id, o.p202_customer_api_key FROM 202_users o JOIN 202_users u ON u.user_id = $USER_ID AND o.install_hash = u.install_hash WHERE o.user_id != $USER_ID AND o.p202_customer_api_key IS NOT NULL AND o.p202_customer_api_key != ''")
for row in "${OTHER_KEYS[@]}"; do
    mysql_q "UPDATE 202_users SET p202_customer_api_key = NULL WHERE user_id = ${row%%$'\t'*}"
done
mysql_q "UPDATE 202_users SET p202_customer_api_key = NULL WHERE user_id = $USER_ID"
request /campaigns; expect_refused "no key on the install" "api-key-required.php"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
