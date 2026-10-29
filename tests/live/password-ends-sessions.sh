#!/bin/bash
# Live pass: a new password ends every other browser signed in as the user.
#
# A new password revoked the user's remember-me keys (#325) and left every
# browser already signed in signed in, so a thief who had used a stolen
# cookie kept that session after the owner changed the password. A session
# now holds the user's session generation from its sign-in; a new password,
# a switch-off or a removal counts it up, and connect.php ends a session that
# holds another at its next request.
#
# The pass signs in as the admin (P202_USER), adds a second Admin through
# Account › Users, and signs that user in in browsers of their own (cookie
# jars, without "Keep me signed in", so only the session can keep them in).
# A browser is signed in when Personal settings answers 200 with its form,
# and signed out when it is sent to the sign-in page. Then:
#
#  - two browsers; the user changes the password on Personal settings in one:
#    that one stays signed in, on the same session id, and the other is
#    signed out at its next request, with a new session id;
#  - two browsers; a reset through 202-lost-pass.php and 202-pass-reset.php
#    (the key read from the database, as the email cannot be read here) signs
#    both out;
#  - the admin sets the password through PUT /api/v3/users/{id}
#    (P202_API_KEY) and then on Account › Users' edit form: each signs the
#    user's browser out, and the admin's own stays signed in;
#  - a sign-in that rehashes a legacy MD5 password signs nobody out;
#  - a browser that makes no request while the user is switched off and on
#    again through PUT is signed out at its next request.
#
# It adds users named "rkse…" and removes them.
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
submit() { # JAR PAGE MARKER URL OUT [name=value ...]
  local jar="$1" page="$2" marker="$3" url="$4" out="$5"; shift 5
  python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body" || { echo "000 -"; return; }
  curl -sS -b "$jar" -c "$jar" -o "$out" -w '%{http_code} %{redirect_url}' --data-binary @"$OUT/.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url"
}
sid() { awk '$6=="PHPSESSID"{print $7}' "$1" | tail -1; }
# Sign USER in through the sign-in form, for this browser's session only.
sign_in() { # JAR USER PASS
  rm -f "$1"
  get "$1" 202-login.php "$OUT/login.html" > /dev/null
  submit "$1" "$OUT/login.html" user_name 202-login.php "$OUT/signed-in.html" "user_name=$2" "user_pass=$3"
}
SIGNIN="$BASE/202-login.php?redirect=%2F202-account%2Faccount.php"
# Whether a browser is signed in: Personal settings with its form, or sent to sign in.
state() { # JAR
  local answer
  answer=$(get "$1" 202-account/account.php "$OUT/state.html")
  case "$answer" in
    "200 ") grep -q 'name="new_user_pass"' "$OUT/state.html" && echo in || echo "200 without the form" ;;
    "302 $SIGNIN") echo out ;;
    *) echo "$answer" ;;
  esac
}
browser() { # JAR PASS: a browser of the user, signed in
  local code
  code=$(sign_in "$1" "$NAME" "$2")
  [ "${code%% *}" = "302" ] || echo "sign-in answered $code" >&2
}
generation() { Q "SELECT user_session_generation FROM 202_users WHERE user_id = '$USER_ID'"; }
signs_in_with() { # PASS: "302" signs in, "200" draws the form again
  local code
  code=$(sign_in "$OUT/try.jar" "$NAME" "$1")
  echo "${code%% *}"
}

STAMP=$(date +%s)
NAME="rkse$STAMP"
UPASS="rkse-$STAMP-pass"
NEW1="rkse-$STAMP-new1"
NEW2="rkse-$STAMP-new2"
NEW3="rkse-$STAMP-new3"
NEW4="rkse-$STAMP-new4"
cleanup() {
  Q "DELETE k FROM 202_auth_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'rkse%'" 2>/dev/null
  Q "DELETE k FROM 202_api_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'rkse%'" 2>/dev/null
  Q "DELETE u, r, p FROM 202_users u LEFT JOIN 202_user_role r ON r.user_id = u.user_id LEFT JOIN 202_users_pref p ON p.user_id = u.user_id WHERE u.user_name LIKE 'rkse%'" 2>/dev/null
  rm -rf "$OUT"
}
trap cleanup EXIT

say "the admin signs in and adds a second Admin"
code=$(sign_in "$ADMIN" "$P202_USER" "$P202_PASS")
eq "${code%% *}" "302" "the admin's sign-in redirects"
eq "$(get "$ADMIN" 202-account/user-management.php "$OUT/users.html" | cut -d' ' -f1)" "200" "Account › Users answers 200"
code=$(submit "$ADMIN" "$OUT/users.html" user_fname 202-account/user-management.php "$OUT/added.html" \
  "user_fname=Session" "user_lname=Ends" "user_email=$NAME@example.com" "user_name=$NAME" \
  "user_password=$UPASS" "user_password2=$UPASS" "user_role=2" "user_active=on")
eq "${code%% *}" "303" "the add form redirects"
USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$NAME' AND user_deleted = 0 AND user_active = 1")
eq "$(printf '%s' "$USER_ID" | grep -cE '^[0-9]+$')" "1" "the user $NAME is stored, active (id $USER_ID)"
eq "$(generation)" "0" "at generation 0"

say "two browsers; the password changed on Personal settings in one"
browser "$OUT/one.jar" "$UPASS"
browser "$OUT/two.jar" "$UPASS"
eq "$(state "$OUT/one.jar")" "in" "the changing browser is signed in"
eq "$(state "$OUT/two.jar")" "in" "the other browser is signed in"
ONE=$(sid "$OUT/one.jar"); TWO=$(sid "$OUT/two.jar")
get "$OUT/one.jar" 202-account/account.php "$OUT/account.html" > /dev/null
code=$(submit "$OUT/one.jar" "$OUT/account.html" new_user_pass 202-account/account.php "$OUT/changed.html" \
  "user_pass=$UPASS" "new_user_pass=$NEW1" "retype_new_user_pass=$NEW1")
eq "${code%% *}" "303" "the password form redirects"
eq "$(generation)" "1" "the user's generation is counted up"
eq "$(state "$OUT/one.jar")" "in" "the changing browser stays signed in"
eq "$(sid "$OUT/one.jar")" "$ONE" "on the same session"
eq "$(state "$OUT/two.jar")" "out" "the other browser is sent to sign in at its next request"
[ "$(sid "$OUT/two.jar")" != "$TWO" ] && ok "with a new session id" || bad "the other browser kept its session id"
eq "$(state "$OUT/two.jar")" "out" "and stays signed out"
eq "$(signs_in_with "$UPASS")" "200" "the old password is refused"
eq "$(signs_in_with "$NEW1")" "302" "the new one signs in"

say "two browsers; a reset through the lost-password page"
browser "$OUT/three.jar" "$NEW1"
browser "$OUT/four.jar" "$NEW1"
eq "$(state "$OUT/three.jar")" "in" "a browser is signed in"
eq "$(state "$OUT/four.jar")" "in" "and another"
get "$OUT/reset.jar" 202-lost-pass.php "$OUT/lost.html" > /dev/null
code=$(submit "$OUT/reset.jar" "$OUT/lost.html" user_email 202-lost-pass.php "$OUT/asked.html" \
  "user_name=$NAME" "user_email=$NAME@example.com")
KEY=$(Q "SELECT user_pass_key FROM 202_users WHERE user_id = '$USER_ID'")
eq "$(printf '%s' "$KEY" | grep -cE '^[0-9a-f]{64}$')" "1" "a reset key is stored (read from the database)"
get "$OUT/reset.jar" "202-pass-reset.php?key=$KEY" "$OUT/reset.html" > /dev/null
submit "$OUT/reset.jar" "$OUT/reset.html" verify_user_pass "202-pass-reset.php?key=$KEY" "$OUT/reset-done.html" \
  "user_pass=$NEW2" "verify_user_pass=$NEW2" > /dev/null
grep -q 'Your password has been reset.' "$OUT/reset-done.html" && ok "the reset page says the password is reset" \
  || bad "the reset page does not say the password is reset"
eq "$(generation)" "2" "the user's generation is counted up"
eq "$(state "$OUT/three.jar")" "out" "the first browser is sent to sign in"
eq "$(state "$OUT/four.jar")" "out" "and so is the second"

say "the admin sets the password through PUT /api/v3/users/{id}"
browser "$OUT/five.jar" "$NEW2"
eq "$(state "$OUT/five.jar")" "in" "the user's browser is signed in"
eq "$(curl -sS -o "$OUT/put.json" -w '%{http_code}' -X PUT -H "Authorization: Bearer $P202_API_KEY" \
  -H 'Content-Type: application/json' --data "{\"user_pass\": \"$NEW3\"}" "$BASE/api/v3/users/$USER_ID")" "200" \
  "PUT user_pass answers 200"
eq "$(state "$OUT/five.jar")" "out" "the user's browser is sent to sign in"
eq "$(get "$ADMIN" 202-account/user-management.php "$OUT/admin.html" | cut -d' ' -f1)" "200" "the admin's own stays signed in"

say "the admin sets the password on Account › Users"
browser "$OUT/six.jar" "$NEW3"
eq "$(state "$OUT/six.jar")" "in" "the user's browser is signed in"
get "$ADMIN" "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edit.html" > /dev/null
code=$(submit "$ADMIN" "$OUT/edit.html" user_fname "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edited.html" \
  "user_password=$NEW4" "user_password2=$NEW4" "user_active=on")
eq "${code%% *}" "303" "the edit form, with a new password, redirects"
eq "$(state "$OUT/six.jar")" "out" "the user's browser is sent to sign in"
eq "$(get "$ADMIN" 202-account/user-management.php "$OUT/admin.html" | cut -d' ' -f1)" "200" "the admin's own stays signed in"

say "a sign-in that rehashes the password signs nobody out"
browser "$OUT/seven.jar" "$NEW4"
eq "$(state "$OUT/seven.jar")" "in" "a browser is signed in"
BEFORE=$(generation)
Q "UPDATE 202_users SET user_pass = MD5(CONCAT('202', MD5(CONCAT('$NEW4', '202')))) WHERE user_id = '$USER_ID'"
eq "$(signs_in_with "$NEW4")" "302" "the legacy password signs in"
eq "$(Q "SELECT LEFT(user_pass, 4) FROM 202_users WHERE user_id = '$USER_ID'")" "\$2y\$" "and is stored again, rehashed"
eq "$(generation)" "$BEFORE" "the generation is not counted up"
eq "$(state "$OUT/seven.jar")" "in" "the browser signed in before stays signed in"

say "a browser idle while the user is switched off and on again"
browser "$OUT/eight.jar" "$NEW4"
eq "$(state "$OUT/eight.jar")" "in" "a browser is signed in"
put_active() {
  curl -sS -o "$OUT/put.json" -w '%{http_code}' -X PUT -H "Authorization: Bearer $P202_API_KEY" \
    -H 'Content-Type: application/json' --data "{\"user_active\": $1}" "$BASE/api/v3/users/$USER_ID"
}
eq "$(put_active 0)" "200" "PUT user_active 0 answers 200"
eq "$(put_active 1)" "200" "PUT user_active 1 answers 200"
eq "$(state "$OUT/eight.jar")" "out" "the idle browser is sent to sign in at its next request"
eq "$(signs_in_with "$NEW4")" "302" "the user signs in again as usual"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
