#!/bin/bash
# Live pass: a new password ends every remember-me cookie of the user.
#
# "Keep me signed in" stores a key per browser (202_auth_keys) that its
# remember_me cookie names. A new password kept every key, so a stolen cookie
# went on signing in after its owner changed the password to lock the thief
# out. A rehash of the password at sign-in is not a new password and keeps
# them.
#
# The pass signs in as the admin (P202_USER), adds a second Admin through
# Account › Users, and signs that user in through the sign-in form with
# "Keep me signed in", in jars of its own, copying each browser's cookie. A
# cookie is tried the way a browser holding only it is: the sign-in page,
# which sends a browser its cookie signs in on to the app (302) and draws the
# form for one it does not (200). Then:
#
#  - the user changes the password on Personal settings in one remembered
#    browser: a copy of that browser's cookie and the other browser's cookie
#    are refused, every key is gone, and the changing browser stays signed in
#    (its pages answer, its cookie cleared); the old password is refused and
#    the new one signs in;
#  - the user asks for a reset on the lost-password page and resets through
#    202-pass-reset.php (the key read from the database, as the email that
#    carries it cannot be read here): the cookie from before is refused;
#  - the admin sets the password through PUT /api/v3/users/{id}
#    (P202_API_KEY, the admin's REST key), and then on Account › Users' edit
#    form: each time the cookie from before is refused;
#  - the stored password is put back to the legacy salted MD5 of the same
#    password, and a sign-in rehashes it: every remembered browser stays.
#
# It adds users named "rkpw…" and removes them, with their keys.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}

if [ -z "$P202_PASS" ] || [ -z "$P202_API_KEY" ]; then
    echo "P202_PASS and P202_API_KEY are required: this pass signs in as $P202_USER and calls PUT /users as it." >&2
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
ADMIN="$OUT/admin.jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

# GET with a jar; the body to $3, "status redirect" to stdout.
get() { curl -sS -b "$1" -c "$1" -o "$3" -w '%{http_code} %{redirect_url}' "$BASE/$2"; }
# Submit the form of a saved page carrying MARKER, with its own fields (and
# overrides); "status redirect" to stdout.
submit() { # JAR PAGE MARKER URL OUT [name=value ...]
  local jar="$1" page="$2" marker="$3" url="$4" out="$5"; shift 5
  python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body" || { echo "000 -"; return; }
  curl -sS -b "$jar" -c "$jar" -o "$out" -w '%{http_code} %{redirect_url}' --data-binary @"$OUT/.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url"
}
remembered() { awk '$6=="remember_me"{print $7}' "$1" | tail -1; }
# Sign USER in through the sign-in form, keeping the browser signed in.
sign_in() { # JAR USER PASS
  rm -f "$1"
  get "$1" 202-login.php "$OUT/login.html" > /dev/null
  submit "$1" "$OUT/login.html" user_name 202-login.php "$OUT/signed-in.html" "user_name=$2" "user_pass=$3" "remember_me=on"
}
# A browser holding only COOKIE opens the sign-in page: "in" when the cookie
# signs it in (sent on to the app), "refused" when the form is drawn.
cookie() { # COOKIE
  local answer
  answer=$(curl -sS -o "$OUT/cookie.html" -w '%{http_code} %{redirect_url}' -b "remember_me=$1" "$BASE/202-login.php")
  case "$answer" in
    "302 $BASE/202-account") echo in ;;
    "200 ") grep -q 'name="user_pass"' "$OUT/cookie.html" && echo refused || echo "200 without the form" ;;
    *) echo "$answer" ;;
  esac
}
# A remembered browser of the user, signed in with PASS: prints its cookie.
remember() { # JAR PASS
  local code
  code=$(sign_in "$1" "$NAME" "$2")
  [ "${code%% *}" = "302" ] || { echo "sign-in answered $code" >&2; return; }
  remembered "$1"
}
# The sign-in form's answer for PASS, not kept signed in (so no key is added):
# "302" signs in, "200" draws the form again.
signs_in_with() { # PASS
  local code
  rm -f "$OUT/try.jar"
  get "$OUT/try.jar" 202-login.php "$OUT/login.html" > /dev/null
  code=$(submit "$OUT/try.jar" "$OUT/login.html" user_name 202-login.php "$OUT/tried.html" "user_name=$NAME" "user_pass=$1")
  echo "${code%% *}"
}
keys() { Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'"; }

STAMP=$(date +%s)
NAME="rkpw$STAMP"
UPASS="rkpw-$STAMP-pass"
NEW1="rkpw-$STAMP-new1"
NEW2="rkpw-$STAMP-new2"
NEW3="rkpw-$STAMP-new3"
NEW4="rkpw-$STAMP-new4"
cleanup() {
  Q "DELETE k FROM 202_auth_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'rkpw%'" 2>/dev/null
  Q "DELETE k FROM 202_api_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'rkpw%'" 2>/dev/null
  Q "DELETE u, r, p FROM 202_users u LEFT JOIN 202_user_role r ON r.user_id = u.user_id LEFT JOIN 202_users_pref p ON p.user_id = u.user_id WHERE u.user_name LIKE 'rkpw%'" 2>/dev/null
  rm -rf "$OUT"
}
trap cleanup EXIT

say "the admin signs in and adds a second Admin"
code=$(sign_in "$ADMIN" "$P202_USER" "$P202_PASS")
eq "${code%% *}" "302" "the admin's sign-in redirects"
eq "$(get "$ADMIN" 202-account/user-management.php "$OUT/users.html" | cut -d' ' -f1)" "200" "Account › Users answers 200"
code=$(submit "$ADMIN" "$OUT/users.html" user_fname 202-account/user-management.php "$OUT/added.html" \
  "user_fname=Password" "user_lname=Revokes" "user_email=$NAME@example.com" "user_name=$NAME" \
  "user_password=$UPASS" "user_password2=$UPASS" "user_role=2" "user_active=on")
eq "${code%% *}" "303" "the add form redirects"
USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$NAME' AND user_deleted = 0 AND user_active = 1")
eq "$(printf '%s' "$USER_ID" | grep -cE '^[0-9]+$')" "1" "the user $NAME is stored, active (id $USER_ID)"

say "the password changed on Personal settings"
CHANGING=$(remember "$OUT/one.jar" "$UPASS")
OTHER=$(remember "$OUT/two.jar" "$UPASS")
eq "$(keys)" "2" "two remembered browsers, two stored keys"
eq "$(cookie "$CHANGING")" "in" "a copy of the changing browser's cookie signs a browser in"
eq "$(get "$OUT/one.jar" 202-account/account.php "$OUT/account.html" | cut -d' ' -f1)" "200" "Personal settings answers 200"
code=$(submit "$OUT/one.jar" "$OUT/account.html" new_user_pass 202-account/account.php "$OUT/changed.html" \
  "user_pass=$UPASS" "new_user_pass=$NEW1" "retype_new_user_pass=$NEW1")
eq "${code%% *}" "303" "the password form redirects"
eq "$(keys)" "0" "every stored key of the user is gone"
eq "$(remembered "$OUT/one.jar")" "" "the changing browser's cookie is cleared"
eq "$(get "$OUT/one.jar" 202-account/account.php "$OUT/after.html" | cut -d' ' -f1)" "200" "the changing browser stays signed in"
grep -q 'Your password is changed.' "$OUT/after.html" && ok "and is told the password is changed" || bad "the page does not say the password is changed"
grep -q 'name="new_user_pass"' "$OUT/after.html" && ok "on Personal settings itself" || bad "the page drawn is not Personal settings"
eq "$(cookie "$CHANGING")" "refused" "the copy of the changing browser's cookie signs nobody in"
eq "$(cookie "$OTHER")" "refused" "nor does the other remembered browser's"
eq "$(signs_in_with "$UPASS")" "200" "the old password is refused"
eq "$(signs_in_with "$NEW1")" "302" "the new one signs in"

say "a reset through the lost-password page"
BEFORE_RESET=$(remember "$OUT/three.jar" "$NEW1")
eq "$(cookie "$BEFORE_RESET")" "in" "a remembered browser signs in"
get "$OUT/reset.jar" 202-lost-pass.php "$OUT/lost.html" > /dev/null
code=$(submit "$OUT/reset.jar" "$OUT/lost.html" user_email 202-lost-pass.php "$OUT/asked.html" \
  "user_name=$NAME" "user_email=$NAME@example.com")
eq "${code%% *}" "200" "the lost-password form answers"
KEY=$(Q "SELECT user_pass_key FROM 202_users WHERE user_id = '$USER_ID'")
eq "$(printf '%s' "$KEY" | grep -cE '^[0-9a-f]{64}$')" "1" "a reset key is stored (read from the database)"
eq "$(get "$OUT/reset.jar" "202-pass-reset.php?key=$KEY" "$OUT/reset.html" | cut -d' ' -f1)" "200" "the reset page answers"
code=$(submit "$OUT/reset.jar" "$OUT/reset.html" verify_user_pass "202-pass-reset.php?key=$KEY" "$OUT/reset-done.html" \
  "user_pass=$NEW2" "verify_user_pass=$NEW2")
eq "${code%% *}" "200" "the reset form answers"
grep -q 'Your password has been reset.' "$OUT/reset-done.html" && ok "and says the password is reset" || bad "the reset page does not say the password is reset"
eq "$(keys)" "0" "every stored key of the user is gone"
eq "$(cookie "$BEFORE_RESET")" "refused" "a browser remembered before the reset signs nobody in"
eq "$(signs_in_with "$NEW2")" "302" "the reset password signs in"

say "the admin sets the password through PUT /api/v3/users/{id}"
BEFORE_PUT=$(remember "$OUT/four.jar" "$NEW2")
eq "$(keys)" "1" "a remembered browser"
eq "$(curl -sS -o "$OUT/put.json" -w '%{http_code}' -X PUT -H "Authorization: Bearer $P202_API_KEY" \
  -H 'Content-Type: application/json' --data "{\"user_pass\": \"$NEW3\"}" "$BASE/api/v3/users/$USER_ID")" "200" \
  "PUT user_pass answers 200"
eq "$(keys)" "0" "every stored key of the user is gone"
eq "$(cookie "$BEFORE_PUT")" "refused" "a browser remembered before signs nobody in"

say "the admin sets the password on Account › Users"
BEFORE_EDIT=$(remember "$OUT/six.jar" "$NEW3")
eq "$(keys)" "1" "a remembered browser"
get "$ADMIN" "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edit.html" > /dev/null
code=$(submit "$ADMIN" "$OUT/edit.html" user_fname "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edited.html" \
  "user_password=$NEW4" "user_password2=$NEW4" "user_active=on")
eq "${code%% *}" "303" "the edit form, with a new password, redirects"
eq "$(keys)" "0" "every stored key of the user is gone"
eq "$(cookie "$BEFORE_EDIT")" "refused" "a browser remembered before signs nobody in"
eq "$(signs_in_with "$NEW4")" "302" "the new password signs in"

say "a rehash at sign-in keeps the keys"
KEPT=$(remember "$OUT/five.jar" "$NEW4")
eq "$(cookie "$KEPT")" "in" "a remembered browser signs in"
Q "UPDATE 202_users SET user_pass = MD5(CONCAT('202', MD5(CONCAT('$NEW4', '202')))) WHERE user_id = '$USER_ID'"
eq "$(Q "SELECT LENGTH(user_pass) FROM 202_users WHERE user_id = '$USER_ID'")" "32" "the stored password is the legacy MD5 of the same password"
eq "$(signs_in_with "$NEW4")" "302" "the same password signs in"
eq "$(Q "SELECT LEFT(user_pass, 4) FROM 202_users WHERE user_id = '$USER_ID'")" "\$2y\$" "and is stored again, rehashed"
eq "$(keys)" "1" "the key of the browser remembered before stays"
eq "$(cookie "$KEPT")" "in" "and still signs in"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
