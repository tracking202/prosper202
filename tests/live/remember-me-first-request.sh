#!/bin/bash
# Live pass: the first request a remember-me cookie signs in has the user's roles.
#
# connect.php read the session's user (new User(), the roles every page
# asks) before the page tried the remember-me cookie, so on the request the
# cookie signed in the User was nobody's: a permission-gated page refused the
# user, and Account › Users sent even the Super user to the account page.
# The cookie is tried in connect.php now, before the user is read.
#
# The pass signs in as P202_USER (a role with add_users: the Super user or an
# Admin) through the sign-in form with "Keep me signed in", keeps only the
# remember_me cookie (the session cookie dropped, as when the browser is
# closed), and opens Account › Users as the first request: it is served,
# with the chrome's Users entry, and the browser is given a session. A second
# request on that session is served as well, and the sign-in page sends a
# browser holding only the cookie on to the app.
#
# The first request has no session to carry the licence's verdict, so it asks
# the licence service: serve the instance with a licence the service accepts,
# or with the service unreachable (the check lets a request through when it
# cannot be asked). It changes nothing in the database but the remember-me
# key it adds, which it removes.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is required: this pass signs in as $P202_USER." >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

# GET with a jar; the body to $3, "status redirect" to stdout.
get() { curl -sS -b "$1" -c "$1" -o "$3" -w '%{http_code} %{redirect_url}' "$BASE/$2"; }
submit() { # JAR PAGE MARKER URL OUT [name=value ...]
  local jar="$1" page="$2" marker="$3" url="$4" out="$5"; shift 5
  python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body" || { echo "000 -"; return; }
  curl -sS -b "$jar" -c "$jar" -o "$out" -w '%{http_code} %{redirect_url}' --data-binary @"$OUT/.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url"
}
cookie_of() { awk -v n="$2" '$6==n{print $7}' "$1" | tail -1; }
# Sign USER in through the sign-in form, keeping the browser signed in.
sign_in() { # JAR USER PASS
  rm -f "$1"
  get "$1" 202-login.php "$OUT/login.html" > /dev/null
  submit "$1" "$OUT/login.html" user_name 202-login.php "$OUT/signed-in.html" "user_name=$2" "user_pass=$3" "remember_me=on"
}
# A jar holding only the remember_me cookie, for the instance's host.
only_cookie() { # JAR COOKIE
  local host
  host=$(printf '%s' "$BASE" | sed -E 's#^[a-z]+://([^/:]+).*#\1#')
  printf '# Netscape HTTP Cookie File\n%s\tFALSE\t/\tFALSE\t0\tremember_me\t%s\n' "$host" "$2" > "$1"
}

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$P202_USER' AND user_deleted = 0")
KEYS_BEFORE=$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")
cleanup() {
  [ -n "$REMEMBERED" ] && Q "DELETE FROM 202_auth_keys WHERE user_id = '$USER_ID' AND auth_key = '$(printf '%s' "$REMEMBERED" | cut -d- -f2)'" 2>/dev/null
  rm -rf "$OUT"
}
trap cleanup EXIT

say "$P202_USER signs in with Keep me signed in"
code=$(sign_in "$OUT/signed.jar" "$P202_USER" "$P202_PASS")
eq "${code%% *}" "302" "the sign-in redirects"
REMEMBERED=$(cookie_of "$OUT/signed.jar" remember_me)
eq "$(printf '%s' "$REMEMBERED" | grep -cE '^[0-9]+-[^-]+-[0-9a-f]{64}$')" "1" "a remember_me cookie is set"
eq "$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")" "$((KEYS_BEFORE + 1))" "its key is stored"

say "the first request, by the cookie alone, opens Account › Users"
only_cookie "$OUT/first.jar" "$REMEMBERED"
eq "$(cookie_of "$OUT/first.jar" PHPSESSID)" "" "the browser holds no session"
eq "$(get "$OUT/first.jar" 202-account/user-management.php "$OUT/users.html")" "200 " "Account › Users is served, not sent away"
grep -q 'name="user_fname"' "$OUT/users.html" && ok "with its add-a-user form" || bad "the page has no add-a-user form"
grep -q 'id="UserManagementPage"' "$OUT/users.html" && ok "and the chrome's User Management entry, which asks add_users" \
  || bad "the chrome has no User Management entry"
grep -q 'id="SettingsPage"' "$OUT/users.html" && ok "and its Settings entry, which asks access_to_settings" \
  || bad "the chrome has no Settings entry"
[ -n "$(cookie_of "$OUT/first.jar" PHPSESSID)" ] && ok "the browser is given a session" || bad "no session was given"

say "the next request, on that session"
eq "$(get "$OUT/first.jar" 202-account/user-management.php "$OUT/users2.html")" "200 " "Account › Users is served again"
grep -q 'name="user_fname"' "$OUT/users2.html" && ok "with its form" || bad "the page has no form"

say "the sign-in page, by the cookie alone"
only_cookie "$OUT/login.jar" "$REMEMBERED"
eq "$(get "$OUT/login.jar" 202-login.php "$OUT/login2.html")" "302 $BASE/202-account" "sends the browser on to the app"
eq "$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")" "$((KEYS_BEFORE + 1))" "the key is kept"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
