#!/usr/bin/env bash
#
# The chat box's pass (202-account/ajax/chat-pass.php) through the page
# itself: a pass the portal gave is kept in the session and handed out again
# for the same request; refresh=1 asks the portal again; a portal that gives
# no pass answers 502 and drops the kept one, so the next request asks again.
# The decisions are ChatPassClient's (tests/Messaging/ChatPassClientTest.php);
# this pass proves the page wires them.
#
# The instance must have been started with
#   CHAT_PASS_URL=http://127.0.0.1:$CHAT_STUB_PORT/api/v3/chat/pass
# in its environment (connect.php reads it; http is allowed only on
# loopback), and must hold a customer API key and an install hash (an
# instance from tests/fixtures/agent-eval/ci/install-instance.sh does). This
# script serves that stand-in portal itself, so it runs on the instance's host.
#
#   P202_BASE (http://127.0.0.1:8000)  P202_USER (evalci)  P202_PASS (required)
#   CHAT_STUB_PORT (8787)
set -uo pipefail

BASE=${P202_BASE:-http://127.0.0.1:8000}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
PORT=${CHAT_STUB_PORT:-8787}
if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass signs in as $P202_USER and needs its password." >&2
    exit 2
fi

OUT=$(mktemp -d)
JAR="$OUT/jar"
STUB="$OUT/stub"
mkdir -p "$STUB"
PASSED=0
FAILED=0
ok() { PASSED=$((PASSED + 1)); printf 'ok   %s\n' "$1"; }
bad() { FAILED=$((FAILED + 1)); printf 'FAIL %s\n' "$1"; }

# The stand-in portal: counts the pass requests it answers, and answers a
# numbered pass, or HTTP 500 while $STUB/fail exists.
cat > "$STUB/router.php" <<'PHP'
<?php
$dir = __DIR__;
$n = (int) @file_get_contents("$dir/count") + 1;
file_put_contents("$dir/count", (string) $n);
header('Content-Type: application/json');
if (is_file("$dir/fail")) {
    http_response_code(500);
    echo '{"error":"stand-in failure"}';
    return;
}
echo json_encode(['ok' => true, 'pass' => "cht1.stand-in.pass$n", 'chat_url' => 'http://127.0.0.1:9', 'expires_at_s' => time() + 86400]);
PHP
php -S "127.0.0.1:$PORT" "$STUB/router.php" > "$OUT/stub.log" 2>&1 &
STUB_PID=$!
trap 'kill "$STUB_PID" 2>/dev/null; rm -rf "$OUT"' EXIT
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
    php -r 'usleep(100000);'
done
: > "$STUB/count"

asked() { cat "$STUB/count" 2>/dev/null || echo 0; }

curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
    --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/signed-in.html"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-account/account.php" -o "$OUT/account.html"
# template_bottom() writes window.P202_CHAT_CONFIG = {base, token, user}.
TOKEN=$(grep -oE 'token: "[^"]+"' "$OUT/account.html" | head -1 | sed 's/token: "//; s/"$//')
if [ -n "$TOKEN" ]; then ok "signed in, with the chat box's session token"; else bad "signed in, with the chat box's session token"; exit 1; fi

# POST the pass request; sets STATUS and PASS.
pass() {
    STATUS=$(curl -sS -c "$JAR" -b "$JAR" -o "$OUT/pass.json" -w '%{http_code}' \
        --data-urlencode "token=$TOKEN" "$@" "$BASE/202-account/ajax/chat-pass.php")
    PASS=$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? ($d["pass"] ?? "") : "";' "$OUT/pass.json")
}

pass
FIRST=$PASS
[ "$STATUS" = 200 ] && [ -n "$FIRST" ] && [ "$(asked)" = 1 ] \
    && ok "the first request asks the portal once and answers its pass" \
    || bad "the first request asks the portal once and answers its pass ($STATUS, '$FIRST', asked $(asked))"

pass
[ "$STATUS" = 200 ] && [ "$PASS" = "$FIRST" ] && [ "$(asked)" = 1 ] \
    && ok "the same request is answered from the session, without asking the portal" \
    || bad "the same request is answered from the session ($STATUS, '$PASS', asked $(asked))"

pass --data-urlencode refresh=1
SECOND=$PASS
[ "$STATUS" = 200 ] && [ -n "$SECOND" ] && [ "$SECOND" != "$FIRST" ] && [ "$(asked)" = 2 ] \
    && ok "refresh=1 asks the portal again and answers the new pass" \
    || bad "refresh=1 asks the portal again ($STATUS, '$SECOND', asked $(asked))"

pass
[ "$STATUS" = 200 ] && [ "$PASS" = "$SECOND" ] && [ "$(asked)" = 2 ] \
    && ok "and the new pass is the one kept" \
    || bad "and the new pass is the one kept ($STATUS, '$PASS', asked $(asked))"

touch "$STUB/fail"
pass --data-urlencode refresh=1
[ "$STATUS" = 502 ] && [ "$(asked)" = 3 ] \
    && ok "a portal that gives no pass answers 502" \
    || bad "a portal that gives no pass answers 502 ($STATUS, asked $(asked))"
rm -f "$STUB/fail"

pass
[ "$STATUS" = 200 ] && [ -n "$PASS" ] && [ "$PASS" != "$SECOND" ] && [ "$(asked)" = 4 ] \
    && ok "after a failed request the kept pass is gone, so the next one asks again" \
    || bad "after a failed request the next one asks again ($STATUS, '$PASS', asked $(asked))"

pass --data-urlencode refresh=yes
[ "$STATUS" = 400 ] && [ "$(asked)" = 4 ] \
    && ok "refresh that is neither 1 nor 0 is refused, unasked" \
    || bad "refresh that is neither 1 nor 0 is refused ($STATUS, asked $(asked))"

echo "$PASSED passed, $FAILED failed"
[ "$FAILED" -eq 0 ]
