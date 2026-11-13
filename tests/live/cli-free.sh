#!/bin/bash
# Live pass: the Go CLI is free but needs an active free ClickServer licence
# (1.9.80). api/v3/index.php serves the Go CLI's User-Agent when the
# install's ClickServer key is one my.tracking202.com answers as valid, paid
# or not, and refuses it with 402 (category licence) otherwise, as
# CapabilitiesController::cliAccess decides it.
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
# and the built CLI (P202_BIN) on the unpaid key: exit 0 with JSON.
# Without the stub it does nothing: the real service would refuse made-up keys.
#
# Restores the install's ClickServer key and drops the answers it recorded.
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
restore() {
    mysql_q "UPDATE 202_users SET p202_customer_api_key = $( [ -n "$ORIGINAL_KEY" ] && printf "'%s'" "$ORIGINAL_KEY" || echo NULL ) WHERE user_id = $USER_ID" || true
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
expect_refused() { # $1 = case name, $2 = words the message must hold
    local category message
    category=$(printf '%s' "$BODY" | jget category)
    message=$(printf '%s' "$BODY" | jget message)
    if [ "$STATUS" = 402 ] && [ "$category" = licence ] && [[ "$message" == *"$2"* ]] && [[ "$message" != *Pro* ]]; then
        ok "$1: 402, category licence, \"$2\""
    else
        ko "$1: expected 402 + category licence + \"$2\" (and no \"Pro\")" "got $STATUS: $BODY"
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

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
