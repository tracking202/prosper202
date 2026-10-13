#!/bin/bash
# Live pass for the Go CLI Pro-licence gate: api/v3/index.php refusing a Go
# CLI request with 402 (category licence) unless the install's ClickServer key
# belongs to a paid account, as CapabilitiesController::cliAccess decides it.
#
# The unit tests cover the parser (ClickServerKeyValidator::interpret), the
# caches and the User-Agent rule one at a time. This pass drives the seam
# between them over HTTP, the way the CLI will: the key the install has, the
# cache the server reads, the User-Agent the request carries, the answer
# my.tracking202.com gives, and what comes back (CLAUDE.md #9, #10).
#
#   1. with a verdict already cached for the install's key: the Go CLI's
#      User-Agent is answered 402 with the licence envelope on a cached "not
#      paid", 200 on a cached "paid"; the PHP CLI's User-Agent and curl's are
#      answered 200 either way; /capabilities reports the verdict as cli and
#      stays open to the Go CLI, as does /system/health
#   2. with the cache holding "the last check went unanswered": the Go CLI
#      is allowed whatever older verdict sits beside it, and nothing is
#      asked; a refusal expires after five minutes, an allow after an hour
#   3. the built Go CLI itself (P202_BIN), when given: exit 6, the licence
#      category on stderr, nothing on stdout, then exit 0 once allowed
#   3b. a sub-user created through the API with a key of their own and no
#      ClickServer key is judged by the account owner's (the one SQL join in
#      the feature), both ways; the sub-user is deleted again
#   4. P202_LICENCE_STUB=1 only — my.tracking202.com is CI's stub
#      (.github/workflows/agent-evals.yml), which answers by the key's
#      suffix: every answer the service can give, and three it never does,
#      each reaching the gate through a real validation call: refused on
#      "valid, not paid", "no such key" and "key refused"; allowed on
#      "paid", on "valid, licence unknown", and on an outage, a gateway's
#      own 404 body, a message the service never sends and a 'paid' that is
#      not a boolean — the answers that must never read as a denial; a
#      redirect to the service itself is followed with the key (both
#      verdicts), one to another host or a redirect loop is unknown
#
# Steps 1-3 never make the server call my.tracking202.com: every key they set
# is a fresh random string with its verdicts (or its hold-off) written to both
# caches first, so this pass can run against a developer's instance without a
# single request leaving the machine. (The first run of this pass seeded only
# the CLI cache, and /capabilities asked the real service for the shell
# verdict: a notice in the body is what showed it.) Step 4 needs the stub, because the real
# service would answer a made-up key with "valid, not paid".
#
# The cache is the server's: CliAccessCache under sys_get_temp_dir(), owned
# by the server's user. This pass writes it through the same class, so run it
# as the user the instance runs as, from an environment with the same TMPDIR
# (a php -S started from this shell, or CI's runner).
#
# Restores the install's key and drops every cache entry it wrote, on any exit.
#
# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
API_KEY=${P202_API_KEY:?P202_API_KEY, the REST API key of the user whose ClickServer key this pass sets, is required}
# The user that key acts as, whose ClickServer key this pass sets; read from
# 202_api_keys when unset.
USER_ID=${P202_USER_ID:-}
# The built Go CLI, configured against $BASE (p202 config set-url/set-key).
BIN=${P202_BIN:-}
# 1 when my.tracking202.com is the CI stub; step 4 runs only then.
STUB=${P202_LICENCE_STUB:-}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ ! -f "$ROOT/vendor/autoload.php" ]; then
    echo "vendor/autoload.php is missing: this pass writes the server's cache through its own classes" >&2
    exit 2
fi

GO_UA='p202-cli/2.0 (Go)'
PHP_UA='p202-cli/1.0'
CURL_UA='curl/8.7.1'

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

# jget <dotted.path> reads one value out of the JSON on stdin; booleans print
# as true/false, a missing path as the empty string.
jget() {
    # shellcheck disable=SC2016  # PHP code, not a shell expression
    php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $v = $d;
        foreach (explode(".", $argv[1]) as $k) {
            $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
        }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : (is_null($v) ? "" : json_encode($v)));
    ' "$1"
}

# cache <op> <key> [arg], through the server's own classes:
#   write 0|1        a verdict        invalidate   drop the entry and the hold-off
#   mark-unknown     the hold-off     age <sec>    make the entry <sec> old
#   recently-unknown prints yes/no
#   shell-write 0|1 / shell-invalidate / shell-mark-unknown   the shell cache
cache() {
    # shellcheck disable=SC2016  # PHP code, not a shell expression
    php -r '
        require $argv[1] . "/vendor/autoload.php";
        [$op, $key] = [$argv[2], $argv[3]];
        $arg = $argv[4] ?? null;
        $cli = Prosper202\License\CliAccessCache::class;
        $shell = Prosper202\License\ShellAccessCache::class;
        switch ($op) {
            case "write": $cli::write($key, $arg === "1"); break;
            case "invalidate": $cli::invalidate($key); break;
            case "mark-unknown": $cli::markUnknown($key); break;
            case "recently-unknown": echo $cli::recentlyUnknown($key) ? "yes" : "no"; break;
            case "age":
                $path = (new ReflectionMethod($cli, "path"))->invoke(null, $key);
                if (!is_string($path) || !touch($path, time() - (int) $arg)) { fwrite(STDERR, "could not age $key\n"); exit(1); }
                break;
            case "shell-write": $shell::write($key, $arg === "1"); break;
            case "shell-invalidate": $shell::invalidate($key); break;
            case "shell-mark-unknown": $shell::markUnknown($key); break;
            default: fwrite(STDERR, "unknown cache op $op\n"); exit(1);
        }
    ' "$ROOT" "$@"
}

# request <user-agent> <path> [api-key] [method] [json-body]: sets STATUS and BODY
request() {
    local out args=(-sS -o - -w $'\n%{http_code}' -H "Authorization: Bearer ${3:-$API_KEY}" -A "$1" -X "${4:-GET}")
    [ -n "${5:-}" ] && args+=(-H 'Content-Type: application/json' --data "$5")
    out=$(curl "${args[@]}" "$BASE/api/v3$2")
    STATUS=${out##*$'\n'}
    BODY=${out%$'\n'*}
}

if [ -z "$USER_ID" ]; then
    USER_ID=$(mysql_q "SELECT user_id FROM 202_api_keys WHERE api_key = '$API_KEY' LIMIT 1")
    if [ -z "$USER_ID" ]; then
        echo "P202_API_KEY is not in 202_api_keys of $DB: the instance at $BASE and the database must be the same install" >&2
        exit 2
    fi
fi
echo "# the API key acts as user $USER_ID; this pass sets that user's ClickServer key"

if ! ORIGINAL_KEY=$(mysql_q "SELECT COALESCE(p202_customer_api_key, '') FROM 202_users WHERE user_id = $USER_ID"); then
    echo "could not read 202_users.p202_customer_api_key for user $USER_ID" >&2
    exit 2
fi

KEYS_TOUCHED=()
SUB_USER=''
set_key() { # $1 = the key the install should hold; its caches start empty
    KEYS_TOUCHED+=("$1")
    cache invalidate "$1"
    cache shell-invalidate "$1"
    mysql_q "UPDATE 202_users SET p202_customer_api_key = '$1' WHERE user_id = $USER_ID"
}
restore() {
    mysql_q "UPDATE 202_users SET p202_customer_api_key = $( [ -n "$ORIGINAL_KEY" ] && printf "'%s'" "$ORIGINAL_KEY" || echo NULL ) WHERE user_id = $USER_ID" || true
    if [ -n "$SUB_USER" ]; then
        request "$CURL_UA" "/users/$SUB_USER" "$API_KEY" DELETE
        [ "$STATUS" = 204 ] || echo "note: deleting the sub-user $SUB_USER answered $STATUS" >&2
    fi
    for k in "${KEYS_TOUCHED[@]}"; do
        cache invalidate "$k"
        cache shell-invalidate "$k"
    done
}
trap restore EXIT

expect_refused() { # $1 = case name; STATUS/BODY from the last request
    local category message
    category=$(printf '%s' "$BODY" | jget category)
    message=$(printf '%s' "$BODY" | jget message)
    if [ "$STATUS" = 402 ] && [ "$category" = licence ] && [[ "$message" == *"Pro licence"* ]] && [[ "$message" == *"trial"* ]]; then
        ok "$1: 402, category licence, the trial named"
    else
        ko "$1: expected 402 + category licence + the trial" "got $STATUS: $BODY"
    fi
}
expect_allowed() { # $1 = case name
    if [ "$STATUS" = 200 ]; then
        ok "$1: 200"
    else
        ko "$1: expected 200" "got $STATUS: $BODY"
    fi
}

echo "# 1. a cached verdict decides, and only for the Go CLI"
K1="live-pass-$(php -r 'echo bin2hex(random_bytes(6));')"
set_key "$K1"
# /capabilities reports the shell verdict too, from its own cache: seeded, or
# the server would ask my.tracking202.com about this made-up key.
cache shell-write "$K1" 1
cache write "$K1" 0
request "$GO_UA" /campaigns;  expect_refused "Go CLI on a cached 'not paid'"
request "$PHP_UA" /campaigns; expect_allowed "PHP CLI on the same key"
request "$CURL_UA" /campaigns; expect_allowed "curl on the same key"
request "$GO_UA" /system/health; expect_allowed "Go CLI, /system/health stays open"
request "$GO_UA" /system
if [ "$STATUS" != 402 ]; then
    ok "Go CLI, /system itself is not gated (answered $STATUS by the router, not 402)"
else
    ko "/system itself should not be gated" "got $STATUS: $BODY"
fi
request "$GO_UA" /systematic
if [ "$STATUS" = 402 ]; then
    ok "Go CLI, a path that merely starts with /system is gated (402 before any 404)"
else
    ko "a path that merely starts with /system should be gated" "got $STATUS: $BODY"
fi
request "$GO_UA" /capabilities
if [ "$STATUS" = 200 ] && [ "$(printf '%s' "$BODY" | jget data.cli)" = false ]; then
    ok "Go CLI, /capabilities stays open and reports cli: false"
else
    ko "Go CLI, /capabilities should answer 200 with cli: false" "got $STATUS: $(printf '%s' "$BODY" | head -c 300)"
fi
cache write "$K1" 1
request "$GO_UA" /campaigns; expect_allowed "Go CLI on a cached 'paid'"
request "$GO_UA" /capabilities
if [ "$(printf '%s' "$BODY" | jget data.cli)" = true ]; then
    ok "/capabilities reports cli: true"
else
    ko "/capabilities should report cli: true" "got $STATUS: $(printf '%s' "$BODY" | head -c 300)"
fi

echo "# 1b. the shell verdict has the same hold-off"
cache shell-invalidate "$K1"
cache shell-mark-unknown "$K1"
request "$GO_UA" /capabilities
if [ "$STATUS" = 200 ] && [ "$(printf '%s' "$BODY" | jget data.shell)" = false ]; then
    ok "shell hold-off with no older verdict: /capabilities reports shell: false without asking"
else
    ko "/capabilities should report shell: false from the hold-off alone" "got $STATUS: $(printf '%s' "$BODY" | head -c 300)"
fi
cache shell-write "$K1" 1
request "$GO_UA" /capabilities
if [ "$(printf '%s' "$BODY" | jget data.shell)" = true ]; then
    ok "a shell verdict ends its hold-off"
else
    ko "/capabilities should report shell: true once a verdict is written" "got $STATUS: $(printf '%s' "$BODY" | head -c 300)"
fi

echo "# 2. an unanswered check allows, whatever was known before, and asks nothing new"
cache invalidate "$K1"
cache mark-unknown "$K1"
request "$GO_UA" /campaigns; expect_allowed "hold-off with no older verdict: allowed"
if [ "$(cache recently-unknown "$K1")" = yes ]; then
    ok "the hold-off is still in place (nothing was asked, nothing was written)"
else
    ko "the hold-off should still be in place: the server asked again and wrote a verdict"
fi
cache write "$K1" 0
cache age "$K1" 7200
cache mark-unknown "$K1"
request "$GO_UA" /campaigns; expect_allowed "hold-off beside an expired 'not paid': an outage never refuses"
cache write "$K1" 1
cache age "$K1" 7200
cache mark-unknown "$K1"
request "$GO_UA" /campaigns; expect_allowed "hold-off beside an expired 'paid': allowed"

echo "# 2b. a refusal expires sooner than an allow"
cache write "$K1" 0
cache age "$K1" 100
request "$GO_UA" /campaigns; expect_refused "a 'not paid' 100 s old still refuses"
cache write "$K1" 0
cache age "$K1" 400
cache mark-unknown "$K1" # the re-check this would trigger is held off, so nothing is asked
request "$GO_UA" /campaigns; expect_allowed "a 'not paid' 400 s old has expired and is re-asked (held off here: allowed)"
cache write "$K1" 1
cache age "$K1" 400
request "$GO_UA" /campaigns; expect_allowed "an allow 400 s old is still an allow"

echo "# 3. the Go CLI itself"
if [ -n "$BIN" ]; then
    cache write "$K1" 0
    out=$("$BIN" user list --json 2>"$ROOT/.cli-licence-stderr"); code=$?
    err=$(cat "$ROOT/.cli-licence-stderr"); rm -f "$ROOT/.cli-licence-stderr"
    if [ $code -eq 6 ] && [ -z "$out" ] && [ "$(printf '%s' "$err" | jget error.category)" = licence ] && [ "$(printf '%s' "$err" | jget error.exit_code)" = 6 ]; then
        ok "p202 user list: exit 6, the licence envelope on stderr, nothing on stdout"
    else
        ko "p202 user list should exit 6 with the licence envelope on stderr and empty stdout" "exit $code; stdout: ${out:0:200}; stderr: ${err:0:300}"
    fi
    cache write "$K1" 1
    if "$BIN" user list --json >/dev/null 2>&1; then
        ok "p202 user list: exit 0 once allowed"
    else
        ko "p202 user list should exit 0 once the key reads as paid"
    fi
else
    echo "skip - P202_BIN not set: the built CLI was not run"
fi

echo "# 3b. a sub-user with no ClickServer key is judged by the account owner's"
RUN_ID=$(php -r 'echo bin2hex(random_bytes(4));')
request "$CURL_UA" /users "$API_KEY" POST "{\"user_name\":\"licgate$RUN_ID\",\"user_email\":\"licgate$RUN_ID@example.test\",\"user_pass\":\"licgate-pass-$RUN_ID\"}"
if [ "$STATUS" = 201 ]; then
    SUB_USER=$(printf '%s' "$BODY" | jget data.user_id)
    request "$CURL_UA" "/users/$SUB_USER/api-keys" "$API_KEY" POST '{}'
    SUB_KEY=$(printf '%s' "$BODY" | jget data.api_key)
fi
if [ -n "${SUB_USER:-}" ] && [ -n "${SUB_KEY:-}" ]; then
    ok "a sub-user $SUB_USER with an API key of their own was created through the API"
    sub_has_key=$(mysql_q "SELECT COALESCE(p202_customer_api_key, '') FROM 202_users WHERE user_id = $SUB_USER")
    if [ -z "$sub_has_key" ]; then
        ok "the sub-user holds no ClickServer key"
    else
        ko "the sub-user should hold no ClickServer key" "has '$sub_has_key'"
    fi
    # /clicks, not /campaigns: since 1.9.77 /campaigns asks for access_to_setup_section,
    # which a new user's role lacks, while /clicks is open to every role and is still
    # behind the licence gate.
    cache write "$K1" 0
    request "$GO_UA" "/clicks?limit=1" "$SUB_KEY"; expect_refused "sub-user, Go CLI, the owner's key 'not paid'"
    request "$PHP_UA" "/clicks?limit=1" "$SUB_KEY"; expect_allowed "sub-user, PHP CLI, same key"
    request "$GO_UA" /capabilities "$SUB_KEY"
    if [ "$STATUS" = 200 ] && [ "$(printf '%s' "$BODY" | jget data.cli)" = false ]; then
        ok "sub-user, /capabilities reports cli: false from the owner's verdict"
    else
        ko "sub-user, /capabilities should report cli: false" "got $STATUS: $(printf '%s' "$BODY" | head -c 300)"
    fi
    cache write "$K1" 1
    request "$GO_UA" "/clicks?limit=1" "$SUB_KEY"; expect_allowed "sub-user, Go CLI, the owner's key 'paid'"
    cache write "$K1" 0
    mysql_q "UPDATE 202_users SET install_hash = '' WHERE user_id = $SUB_USER"
    request "$GO_UA" "/clicks?limit=1" "$SUB_KEY"; expect_allowed "sub-user with no install_hash: no owner to look up, unknown, allowed"
    mysql_q "UPDATE 202_users SET install_hash = (SELECT h FROM (SELECT install_hash AS h FROM 202_users WHERE user_id = $USER_ID) t) WHERE user_id = $SUB_USER"
else
    ko "could not create a sub-user with an API key through the API" "got $STATUS: $(printf '%s' "$BODY" | head -c 300)"
fi

echo "# 4. real answers from my.tracking202.com (the CI stub)"
if [ "$STUB" = 1 ]; then
    stub_case() { # $1 = key suffix, $2 = refused|allowed, $3 = what the stub answers
        local key="live-pass-stub-$1"
        set_key "$key"
        request "$GO_UA" /campaigns
        if [ "$2" = refused ]; then
            expect_refused "$3"
        else
            expect_allowed "$3"
        fi
    }
    stub_case paid     allowed "valid + paid: allowed"
    stub_case unpaid   refused "valid + not paid: refused"
    request "$GO_UA" /campaigns; expect_refused "valid + not paid, again: the denial was cached"
    request "$PHP_UA" /campaigns; expect_allowed "valid + not paid: the PHP CLI is still answered"
    stub_case invalid  refused "no such key: refused"
    stub_case refused  refused "key refused (CPM* required): refused"
    stub_case no-paid  allowed "valid, no paid field (service DB down / edge fallback): allowed"
    stub_case odd-paid allowed "valid, paid is a string: unknown, allowed"
    stub_case stranger allowed "200 with a message the service never sends: unknown, allowed"
    stub_case gateway  allowed "404 without the service's envelope: unknown, allowed"
    stub_case outage   allowed "503 error page: unknown, allowed"
    stub_case paid-redirect   allowed "302 to the service itself over HTTPS: followed with the key, 'paid': allowed"
    stub_case unpaid-redirect refused "302 to the service itself over HTTPS: followed, 'not paid': refused"
    stub_case redirect-away   allowed "302 to another host: not followed, unknown, allowed"
    stub_case redirect-loop   allowed "302 back to itself: three hops followed, then given up, unknown, allowed"
    if [ "$(cache recently-unknown live-pass-stub-outage)" = yes ]; then
        ok "the outage left a hold-off, not a verdict"
    else
        ko "an outage should leave a hold-off marker for the key"
    fi
else
    echo "skip - P202_LICENCE_STUB not set: the validation call was not driven (CI does; the real service would answer a made-up key 'valid, not paid')"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
