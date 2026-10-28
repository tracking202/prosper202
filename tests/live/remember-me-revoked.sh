#!/bin/bash
# Live pass: a remember-me cookie stops signing in once what made it is over.
#
# "Keep me signed in" stores a key per browser (202_auth_keys) that its
# remember_me cookie names. The sign-out cleared the cookie and kept the key,
# so a copy of the cookie went on signing in for up to 14 days; switching a
# user off kept every key, so a remembered browser that made no request while
# the user was off signed back in once they were switched on again.
#
# The pass signs in as the admin (P202_USER), adds a second Admin through
# Account › Users, and signs that user in through the sign-in form with
# "Keep me signed in", in jars of its own, copying each browser's cookie. A
# cookie is tried the way a browser holding only it is: the sign-in page,
# which sends a browser its cookie signs in on to the app (302) and draws the
# form for one it does not (200). Then:
#
#  - a copied cookie, after its browser signs out, is refused, while the same
#    user's other remembered browser still signs in (a sign-out ends its own
#    browser's key, not the user's others);
#  - after the user is switched off and on again through Account › Users,
#    every cookie from before is refused;
#  - the same through PUT /api/v3/users/{id} (P202_API_KEY, the admin's REST
#    key);
#  - after the user is removed, and their row restored, the old cookie is
#    refused.
#
# It adds users named "rkend…" and removes them, with their keys.
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
# A remembered browser of the user: signs in, prints its cookie.
remember() { # JAR
  local code
  code=$(sign_in "$1" "$NAME" "$UPASS")
  [ "${code%% *}" = "302" ] || { echo "sign-in answered $code" >&2; return; }
  remembered "$1"
}
keys() { Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'"; }
put_active() { # 0|1; status to stdout
  curl -sS -o "$OUT/put.json" -w '%{http_code}' -X PUT -H "Authorization: Bearer $P202_API_KEY" \
    -H 'Content-Type: application/json' --data "{\"user_active\": $1}" "$BASE/api/v3/users/$USER_ID"
}
edit_active() { # on|'' ; status to stdout
  get "$ADMIN" "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edit.html" > /dev/null
  submit "$ADMIN" "$OUT/edit.html" user_fname "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edited.html" "user_active=$1" | cut -d' ' -f1
}

STAMP=$(date +%s)
NAME="rkend$STAMP"
UPASS="rkend-$STAMP-pass"
cleanup() {
  Q "DELETE k FROM 202_auth_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'rkend%'" 2>/dev/null
  Q "DELETE k FROM 202_api_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'rkend%'" 2>/dev/null
  Q "DELETE u, r, p FROM 202_users u LEFT JOIN 202_user_role r ON r.user_id = u.user_id LEFT JOIN 202_users_pref p ON p.user_id = u.user_id WHERE u.user_name LIKE 'rkend%'" 2>/dev/null
  rm -rf "$OUT"
}
trap cleanup EXIT

say "the admin signs in and adds a second Admin"
code=$(sign_in "$ADMIN" "$P202_USER" "$P202_PASS")
eq "${code%% *}" "302" "the admin's sign-in redirects"
eq "$(get "$ADMIN" 202-account/user-management.php "$OUT/users.html" | cut -d' ' -f1)" "200" "Account › Users answers 200"
code=$(submit "$ADMIN" "$OUT/users.html" user_fname 202-account/user-management.php "$OUT/added.html" \
  "user_fname=Remember" "user_lname=Revoked" "user_email=$NAME@example.com" "user_name=$NAME" \
  "user_password=$UPASS" "user_password2=$UPASS" "user_role=2" "user_active=on")
eq "${code%% *}" "303" "the add form redirects"
USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$NAME' AND user_deleted = 0 AND user_active = 1")
eq "$(printf '%s' "$USER_ID" | grep -cE '^[0-9]+$')" "1" "the user $NAME is stored, active (id $USER_ID)"

say "a copied cookie, after its browser signs out"
COPIED=$(remember "$OUT/one.jar")
OTHER=$(remember "$OUT/two.jar")
eq "$(keys)" "2" "two remembered browsers, two stored keys"
eq "$(cookie "$COPIED")" "in" "the copied cookie signs a browser in"
eq "$(get "$OUT/one.jar" 202-account/signout.php "$OUT/signout.html")" "302 $BASE/" "its browser signs out"
eq "$(remembered "$OUT/one.jar")" "" "and its cookie is cleared"
eq "$(keys)" "1" "its stored key is gone"
eq "$(cookie "$COPIED")" "refused" "the copied cookie signs nobody in"
eq "$(cookie "$OTHER")" "in" "the user's other remembered browser still signs in"

say "switched off and on through Account › Users"
THIRD=$(remember "$OUT/three.jar")
eq "$(keys)" "2" "two remembered browsers"
eq "$(edit_active "")" "303" "the edit form, Active unticked, redirects"
eq "$(Q "SELECT user_active FROM 202_users WHERE user_id = '$USER_ID'")" "0" "the user is stored switched off"
eq "$(keys)" "0" "and every stored key of theirs is gone"
eq "$(edit_active on)" "303" "the edit form, Active ticked, redirects"
eq "$(Q "SELECT user_active FROM 202_users WHERE user_id = '$USER_ID'")" "1" "the user is stored switched on"
eq "$(cookie "$OTHER")" "refused" "a browser remembered before the switch-off signs nobody in"
eq "$(cookie "$THIRD")" "refused" "nor does the other one"
code=$(sign_in "$OUT/again.jar" "$NAME" "$UPASS")
eq "${code%% *}" "302" "the user signs in again as usual"
eq "$(cookie "$(remembered "$OUT/again.jar")")" "in" "and a browser remembered now signs in"

say "switched off and on through PUT /api/v3/users/{id}"
FOURTH=$(remember "$OUT/four.jar")
eq "$(put_active 0)" "200" "PUT user_active 0 answers 200"
eq "$(keys)" "0" "every stored key of theirs is gone"
eq "$(put_active 1)" "200" "PUT user_active 1 answers 200"
eq "$(cookie "$FOURTH")" "refused" "a browser remembered before signs nobody in"

say "removed, then restored"
FIFTH=$(remember "$OUT/five.jar")
get "$ADMIN" 202-account/user-management.php "$OUT/users.html" > /dev/null
code=$(submit "$ADMIN" "$OUT/users.html" "delete_user_id=$USER_ID" 202-account/user-management.php "$OUT/removed.html")
eq "${code%% *}" "303" "the remove form redirects"
eq "$(keys)" "0" "every stored key of theirs is gone"
Q "UPDATE 202_users SET user_deleted = 0 WHERE user_id = '$USER_ID'"
eq "$(cookie "$FIFTH")" "refused" "restored, a browser remembered before signs nobody in"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
