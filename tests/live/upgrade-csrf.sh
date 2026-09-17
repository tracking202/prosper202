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
#   2. GET the page: the form carries a token, inside the form
#   3. POST without a token, with a wrong token, and from a client with no
#      session at all: each is refused, says why, and 202_version is untouched
#   4. POST the form as a browser would — the fields inside it, token
#      included: the ladder runs, 202_version is the
#      code version, and the page answers "Already Upgraded" after
#
# Step 4 leaves the database exactly where step 1 found it (the code
# version), so the pass is self-restoring; the rung it climbs is the
# reconciler, which is idempotent.
#
# Runs in CI as well: the Agent Evals job (.github/workflows/agent-evals.yml)
# runs it against the instance it installs, right after installing, so the
# page is driven over HTTP on every push and not only when someone runs this
# by hand. tests/Auth/PreLoginPostRequiresTokenTest is the static half.
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
# Empty means the mysql client's default, the local socket. CI's MySQL is a
# service container reached over TCP, so the Agent Evals job sets both.
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
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
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
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
# The form, not the page. The first version of this took the first
# name="token" anywhere in the response and posted it by hand, so a token
# input moved outside the form would have kept it green while every browser
# submission failed the check. The span from the form's tag to its </form>
# is what a browser submits from, so the token is read from there and so
# are the fields.
tr '\n' ' ' < "$OUT/get.html" | grep -oE '<form[^>]*id="upgrade-form"[^>]*>.*' | sed 's#</form>.*##' | head -1 > "$OUT/form.html"
if [ -s "$OUT/form.html" ]; then ok "the upgrade form is rendered"; else bad "the upgrade form is rendered"; fi
has "$OUT/form.html" 'name="token"' "the form carries a token inside it"
TOKEN=$(grep -oE '<input[^>]*name="token"[^>]*>' "$OUT/form.html" | head -1 | grep -oE 'value="[^"]*"' | sed 's/^value="//; s/"$//')
if [ -n "$TOKEN" ]; then ok "token extracted (${#TOKEN} chars)"; else bad "token extracted"; fi

# name=value for each field a browser would submit with the form: hidden,
# text and password inputs as they are, radios and checkboxes only when
# checked, buttons not at all (the upgrade button has no name). The form
# has no <select> or <textarea>; one added later needs teaching here.
form_fields() {
    local tag name type value
    grep -oE '<input[^>]*>' "$OUT/form.html" | while IFS= read -r tag; do
        name=$(printf '%s' "$tag" | grep -oE 'name="[^"]*"' | head -1 | sed 's/^name="//; s/"$//')
        [ -n "$name" ] || continue
        type=$(printf '%s' "$tag" | grep -oiE 'type="[^"]*"' | head -1 | sed 's/^[^"]*"//; s/"$//' | tr '[:upper:]' '[:lower:]')
        value=$(printf '%s' "$tag" | grep -oE 'value="[^"]*"' | head -1 | sed 's/^value="//; s/"$//')
        case "$type" in
            radio|checkbox) printf '%s' "$tag" | grep -qiE '[[:space:]]checked([[:space:]]|=|/|>)' || continue ;;
            submit|button|image|reset|file) continue ;;
        esac
        printf '%s=%s\n' "$name" "$value"
    done
}
mapfile -t FIELDS < <(form_fields)
if printf '%s\n' "${FIELDS[@]}" | grep -q '^token='; then
    ok "a browser submission of the form carries the token (${#FIELDS[@]} fields: $(printf '%s ' "${FIELDS[@]%%=*}"))"
else
    bad "a browser submission of the form carries the token (fields: $(printf '%s ' "${FIELDS[@]%%=*}"))"
fi
# The same submission with the token left out, with a wrong one, and as is.
SUBMIT=(); NO_TOKEN=(); WRONG_TOKEN=()
for field in "${FIELDS[@]}"; do
    SUBMIT+=(--data-urlencode "$field")
    case "$field" in
        token=*) WRONG_TOKEN+=(--data-urlencode "token=not-the-token") ;;
        *) NO_TOKEN+=(--data-urlencode "$field"); WRONG_TOKEN+=(--data-urlencode "$field") ;;
    esac
done

say "POST with no token at all — what a cross-site form sends"
curl -sS -c "$JAR" -b "$JAR" "$URL" "${NO_TOKEN[@]}" -o "$OUT/no-token.html"
hasnt "$OUT/no-token.html" 'Success!' "not upgraded"
has   "$OUT/no-token.html" 'security check failed' "says why"
eq "$(stored)" "$PRIOR" "202_version untouched"

say "POST with a wrong token"
curl -sS -c "$JAR" -b "$JAR" "$URL" "${WRONG_TOKEN[@]}" -o "$OUT/wrong-token.html"
hasnt "$OUT/wrong-token.html" 'Success!' "not upgraded"
has   "$OUT/wrong-token.html" 'security check failed' "says why"
eq "$(stored)" "$PRIOR" "202_version untouched"

say "POST from a client with no session, replaying this session's token"
JAR2=$(mktemp)
curl -sS -c "$JAR2" -b "$JAR2" "$URL" "${SUBMIT[@]}" -o "$OUT/no-session.html"
hasnt "$OUT/no-session.html" 'Success!' "not upgraded"
eq "$(stored)" "$PRIOR" "202_version untouched"
rm -f "$JAR2"

say "POST the form as a browser would — its own fields, token included"
curl -sS -c "$JAR" -b "$JAR" "$URL" "${SUBMIT[@]}" -o "$OUT/ok.html"
has "$OUT/ok.html" 'Success!' "the upgrade ran"
eq "$(stored)" "$CODE" "202_version is the code version"

say "the page now refuses to run again"
curl -sS -c "$JAR" -b "$JAR" "$URL" -o "$OUT/again.html"
has "$OUT/again.html" 'Already Upgraded' "Already Upgraded"

rm -rf "$JAR" "$OUT"
printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
