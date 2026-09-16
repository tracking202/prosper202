#!/bin/bash
# Live pass for the CSRF guard on 202-config/upgrade.php.
#
# The upgrade page takes its POST before there is a login — that is how a
# fresh upgrade runs at all — so the session token connect.php mints on every
# request is the only thing standing between a cross-site form and a schema
# upgrade run at a time of the attacker's choosing. This pass proves the
# token is required, over HTTP, against the database the page writes:
#
#   1. wind the stored version back so upgrade_needed() is true
#   2. GET the page: the form carries a token
#   3. POST without a token, with a wrong token, and from a client with no
#      session at all: each is refused, says why, and 202_version is untouched
#   4. POST with the token from the page: the ladder runs, 202_version is the
#      code version, and the page answers "Already Upgraded" after
#
# Step 4 leaves the database exactly where step 1 found it (the code
# version), so the pass is self-restoring; the rung it climbs is the
# reconciler, which is idempotent.
#
# --- environment -------------------------------------------------------
# The names match the other passes here and tests/browser. P202_DB must be a
# SCRATCH database: this pass rewrites 202_version, and guard.sh refuses a
# name that does not read as disposable. No login is needed; that is the
# point of the page.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
# The rung to wind back to. Any version below the code version works — the
# ladder climbs from wherever it starts — and 1.9.75 is the one RELEASING.md
# names for the attribution tables.
PRIOR=${P202_PRIOR_VERSION:-1.9.75}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

CODE=$(php -r 'require $argv[1]; echo PROSPER202_VERSION;' "$HERE/../../202-config/version.php")
if [ -z "$CODE" ]; then
    echo "could not read PROSPER202_VERSION from 202-config/version.php" >&2
    exit 2
fi
if ! php -r 'exit(version_compare($argv[1], $argv[2], "<") ? 0 : 1);' "$PRIOR" "$CODE"; then
    echo "P202_PRIOR_VERSION=$PRIOR is not below the code version $CODE; nothing to upgrade" >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
# -----------------------------------------------------------------------

JAR=$(mktemp)
OUT=$(mktemp -d)
PASS=0; FAIL=0
Q() { mysql_q -N "$DB" -e "$1"; }
URL="$BASE/202-config/upgrade.php"

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
stored() { Q "SELECT version FROM 202_version"; }
wind_back() { Q "UPDATE 202_version SET version='$PRIOR'"; }

say "wind the stored version back to $PRIOR (code is $CODE)"
wind_back
eq "$(stored)" "$PRIOR" "stored version is $PRIOR, so upgrade_needed() is true"

say "GET the upgrade page"
curl -sS -c "$JAR" -b "$JAR" "$URL" -o "$OUT/get.html"
has "$OUT/get.html" 'id="upgrade-form"' "the upgrade form is rendered"
has "$OUT/get.html" 'name="token"' "the form carries a token"
TOKEN=$(grep -oE 'name="token" value="[^"]+"' "$OUT/get.html" | head -1 | sed 's/.*value="//; s/"//')
if [ -n "$TOKEN" ]; then ok "token extracted (${#TOKEN} chars)"; else bad "token extracted"; fi

say "POST with no token at all — what a cross-site form sends"
curl -sS -c "$JAR" -b "$JAR" "$URL" -d "lp_ssl=1" -o "$OUT/no-token.html"
hasnt "$OUT/no-token.html" 'Success!' "not upgraded"
has   "$OUT/no-token.html" 'security check failed' "says why"
eq "$(stored)" "$PRIOR" "202_version untouched"

say "POST with a wrong token"
curl -sS -c "$JAR" -b "$JAR" "$URL" --data-urlencode "token=not-the-token" -d "lp_ssl=1" -o "$OUT/wrong-token.html"
hasnt "$OUT/wrong-token.html" 'Success!' "not upgraded"
has   "$OUT/wrong-token.html" 'security check failed' "says why"
eq "$(stored)" "$PRIOR" "202_version untouched"

say "POST from a client with no session, replaying this session's token"
JAR2=$(mktemp)
curl -sS -c "$JAR2" -b "$JAR2" "$URL" --data-urlencode "token=$TOKEN" -d "lp_ssl=1" -o "$OUT/no-session.html"
hasnt "$OUT/no-session.html" 'Success!' "not upgraded"
eq "$(stored)" "$PRIOR" "202_version untouched"
rm -f "$JAR2"

say "POST with the page's own token"
curl -sS -c "$JAR" -b "$JAR" "$URL" --data-urlencode "token=$TOKEN" -d "lp_ssl=1" -o "$OUT/ok.html"
has "$OUT/ok.html" 'Success!' "the upgrade ran"
eq "$(stored)" "$CODE" "202_version is the code version"

say "the page now refuses to run again"
curl -sS -c "$JAR" -b "$JAR" "$URL" -o "$OUT/again.html"
has "$OUT/again.html" 'Already Upgraded' "Already Upgraded"

rm -rf "$JAR" "$OUT"
printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
