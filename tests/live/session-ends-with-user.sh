#!/bin/bash
# Live pass: a signed-in session ends the moment its user is switched off,
# removed, or its row is gone, and the user switched back on signs in again.
#
# The sign-in, the remember-me cookie and the API keys all asked for an
# active, undeleted user; a session already signed in was never asked again
# (AUTH::logged_in() read only the session's own fields and refreshed its
# time on every request), so a user switched off in Account › Users, or
# removed, kept every page, every AJAX endpoint and every form for as long as
# they kept using the browser. Every signed-in request now reads the user's
# row (the read connect.php makes for the role) and ends the session when the
# user may not act.
#
# The pass signs in as the admin (P202_USER), adds a second Admin through
# Account › Users, and signs that user in through the sign-in form with
# "Keep me signed in", in a jar of its own. While signed in, the user's pages
# answer and Personal settings mints a REST API key. Then, for each way a user
# stops being able to act — switched off through the edit form, removed
# through the list's remove form, the row deleted under it — the user's next
# page is sent to sign in; the session's file, its id and the remember-me
# cookie and keys are gone; the same Personal settings form, posted with its
# own token, mints nothing; the Overview's AJAX fragment answers 401 and the
# chat pass its JSON 401, as does the update banner's fetch(); the sign-in
# page draws; and the admin's own session is unaffected. Between the first two the user is switched back on and signs
# in again through the form.
#
# It adds users named "sxend…" and removes them, with their keys.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
# Where PHP keeps the session files, to see the ended one gone; '' skips that check.
SESSION_DIR=${P202_SESSION_DIR-/var/lib/php/sessions}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass signs in as $P202_USER and needs its password." >&2
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
JAR="$OUT/user.jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }

# GET with a jar; the body to $3, "status redirect" to stdout.
get() { curl -sS -b "$1" -c "$1" -o "$3" -w '%{http_code} %{redirect_url}' "$BASE/$2"; }
# The same, as page JavaScript asks: jQuery sends X-Requested-With, and a
# browser marks a fetch() Sec-Fetch-Dest: empty.
xhr() { curl -sS -b "$1" -c "$1" -o "$3" -w '%{http_code} %{redirect_url}' -H 'X-Requested-With: XMLHttpRequest' "$BASE/$2"; }
fetched() { curl -sS -b "$1" -c "$1" -o "$3" -w '%{http_code} %{redirect_url}' -H 'Sec-Fetch-Dest: empty' -H 'Sec-Fetch-Mode: cors' "$BASE/$2"; }
# Submit the form of a saved page carrying MARKER, with its own fields (and
# overrides); "status redirect" to stdout.
submit() { # JAR PAGE MARKER URL OUT [name=value ...]
  local jar="$1" page="$2" marker="$3" url="$4" out="$5"; shift 5
  python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body" || { echo "000 -"; return; }
  curl -sS -b "$jar" -c "$jar" -o "$out" -w '%{http_code} %{redirect_url}' --data-binary @"$OUT/.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url"
}
# The remember_me cookie and the session id a jar holds ('' when none).
remembered() { awk '$6=="remember_me"{print $7}' "$1" | tail -1; }
session_id() { awk '$6=="PHPSESSID"{print $7}' "$1" | tail -1; }
# The token inside the form that carries MARKER, as a browser would post it.
form_token() { # PAGE MARKER
  python3 "$HERE/form-body.py" "$1" "$2" | tr '&' '\n' | sed -n 's/^token=//p' | head -1
}
# POST the chat box's pass request with TOKEN, as p202-chat.js does; status to stdout.
chat() { # TOKEN OUT
  curl -sS -b "$JAR" -c "$JAR" -o "$2" -w '%{http_code}' --data-urlencode "token=$1" --data-urlencode "refresh=0" \
    "$BASE/202-account/ajax/chat-pass.php"
}
SIGNIN="$BASE/202-login.php?redirect="
keys() { Q "SELECT COUNT(*) FROM 202_api_keys WHERE user_id = '$USER_ID'"; }

# Sign USER in through the sign-in form, keeping the browser signed in.
sign_in() { # JAR USER PASS
  rm -f "$1"
  get "$1" 202-login.php "$OUT/login.html" > /dev/null
  submit "$1" "$OUT/login.html" user_name 202-login.php "$OUT/signed-in.html" "user_name=$2" "user_pass=$3" "remember_me=on"
}

STAMP=$(date +%s)
NAME="sxend$STAMP"
UPASS="sxend-$STAMP-pass"
cleanup() {
  Q "DELETE k FROM 202_api_keys k JOIN 202_users u ON u.user_id = k.user_id WHERE u.user_name LIKE 'sxend%'" 2>/dev/null
  Q "DELETE u, r, p FROM 202_users u LEFT JOIN 202_user_role r ON r.user_id = u.user_id LEFT JOIN 202_users_pref p ON p.user_id = u.user_id WHERE u.user_name LIKE 'sxend%'" 2>/dev/null
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

# What the signed-in user can do, and then cannot.
signed_in_works() { # LABEL
  local before
  eq "$(get "$JAR" tracking202/setup/aff_networks.php "$OUT/page.html" | cut -d' ' -f1)" "200" "$1: Setup › Categories answers 200"
  has "$OUT/page.html" 'name="aff_network_name"' "$1: with its add form"
  eq "$(get "$JAR" 202-account/account.php "$OUT/account.html" | cut -d' ' -f1)" "200" "$1: Personal settings answers 200"
  before=$(keys)
  code=$(submit "$JAR" "$OUT/account.html" add_rest_api_key 202-account/account.php "$OUT/minted.html")
  eq "${code%% *}" "303" "$1: its form to add a REST API key, with its own token, redirects"
  eq "$(keys)" "$((before + 1))" "$1: and mints a key"
  get "$JAR" 202-account/account.php "$OUT/account.html" > /dev/null
  eq "$(xhr "$JAR" tracking202/ajax/account_overview.php "$OUT/ajax.html" | cut -d' ' -f1)" "200" "$1: the Overview's AJAX fragment answers 200"
  eq "$(fetched "$JAR" 202-account/ajax/update-needed.php "$OUT/fetched.html" | cut -d' ' -f1)" "200" "$1: the update banner's fetch() answers 200"
  # The chat pass's sign-in gate lets the session through to its token check.
  eq "$(chat "" "$OUT/chat.json")" "403" "$1: the chat pass, posted without a token, gets past the sign-in to the token check"
  has "$OUT/chat.json" '"error":"invalid token"' "$1: and says so"
}
refused() { # LABEL
  local label="$1" before_keys before sid token
  before_keys=$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")
  sid=$(session_id "$JAR")
  token=$(form_token "$OUT/account.html" add_rest_api_key)
  eq "$(get "$JAR" tracking202/setup/aff_networks.php "$OUT/after.html")" "302 ${SIGNIN}%2Ftracking202%2Fsetup%2Faff_networks.php" "$label: the next page is sent to sign in"
  eq "$([ -n "$sid" ] && [ -n "$(session_id "$JAR")" ] && [ "$(session_id "$JAR")" != "$sid" ] && echo replaced)" "replaced" "$label: and the browser is given a new session id"
  if [ -n "$SESSION_DIR" ]; then
    eq "$([ -e "$SESSION_DIR/sess_$sid" ] && echo kept || echo gone)" "gone" "$label: and the signed-in session's file is gone"
  fi
  eq "$(remembered "$JAR")" "" "$label: and the remember-me cookie is cleared"
  eq "$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")" "0" "$label: and its stored keys are gone (were $before_keys)"
  eq "$(get "$JAR" 202-account/account.php "$OUT/again.html")" "302 ${SIGNIN}%2F202-account%2Faccount.php" "$label: Personal settings is sent to sign in too"
  before=$(keys)
  code=$(submit "$JAR" "$OUT/account.html" add_rest_api_key 202-account/account.php "$OUT/post.html")
  eq "$code" "302 ${SIGNIN}%2F202-account%2Faccount.php" "$label: its form to add a REST API key, with its own token, is sent to sign in"
  eq "$(keys)" "$before" "$label: and mints nothing"
  eq "$(xhr "$JAR" tracking202/ajax/account_overview.php "$OUT/ajax.html" | cut -d' ' -f1)" "401" "$label: the AJAX fragment answers 401"
  has "$OUT/ajax.html" "You are signed out" "$label: in a sentence the page can show"
  eq "$(fetched "$JAR" 202-account/ajax/update-needed.php "$OUT/fetched.html" | cut -d' ' -f1)" "401" "$label: the update banner's fetch() answers 401, which leaves its slot empty"
  eq "$(chat "$token" "$OUT/chat.json")" "401" "$label: the chat pass, posted with the page's token, answers 401"
  has "$OUT/chat.json" '"error":"authentication required"' "$label: from its sign-in gate"
  eq "$(get "$JAR" 202-login.php "$OUT/login-again.html" | cut -d' ' -f1)" "200" "$label: the sign-in page draws (no redirect back)"
  eq "$(form_token "$OUT/login-again.html" user_name | grep -cE '^[0-9a-f]{32}$')" "1" "$label: with a token in its form"
  eq "$(get "$ADMIN" tracking202/setup/aff_networks.php "$OUT/admin.html" | cut -d' ' -f1)" "200" "$label: the admin's own session is unaffected"
}

say "the user signs in, keeping the browser signed in"
code=$(sign_in "$JAR" "$NAME" "$UPASS")
eq "${code%% *}" "302" "the user's sign-in redirects"
eq "$([ -n "$(remembered "$JAR")" ] && echo set)" "set" "the browser holds a remember-me cookie"
eq "$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")" "1" "and its key is stored"
signed_in_works "signed in"

say "the admin switches the user off"
eq "$(get "$ADMIN" "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edit.html" | cut -d' ' -f1)" "200" "the edit form opens"
code=$(submit "$ADMIN" "$OUT/edit.html" user_fname "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edited.html" "user_active=")
eq "${code%% *}" "303" "the edit form, Active unticked, redirects"
eq "$(Q "SELECT user_active FROM 202_users WHERE user_id = '$USER_ID'")" "0" "the user is stored switched off"
refused "switched off"

say "switched back on, the user signs in again"
get "$ADMIN" "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edit.html" > /dev/null
code=$(submit "$ADMIN" "$OUT/edit.html" user_fname "202-account/user-management.php?edit_user_id=$USER_ID" "$OUT/edited.html" "user_active=on")
eq "${code%% *}" "303" "the edit form, Active ticked, redirects"
eq "$(Q "SELECT user_active FROM 202_users WHERE user_id = '$USER_ID'")" "1" "the user is stored switched on"
code=$(sign_in "$JAR" "$NAME" "$UPASS")
eq "${code%% *}" "302" "the user's sign-in redirects"
eq "$(Q "SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = '$USER_ID'")" "1" "only the new remember-me key is stored"
signed_in_works "signed in again"

say "the admin removes the user"
get "$ADMIN" 202-account/user-management.php "$OUT/users.html" > /dev/null
code=$(submit "$ADMIN" "$OUT/users.html" "delete_user_id=$USER_ID" 202-account/user-management.php "$OUT/removed.html")
eq "${code%% *}" "303" "the remove form redirects"
eq "$(Q "SELECT user_deleted FROM 202_users WHERE user_id = '$USER_ID'")" "1" "the user is stored removed"
# The removal revokes the user's remember-me keys itself (UserDataPurge).
refused "removed"

say "a user whose row is gone"
Q "UPDATE 202_users SET user_deleted = 0 WHERE user_id = '$USER_ID'"
code=$(sign_in "$JAR" "$NAME" "$UPASS")
eq "${code%% *}" "302" "restored, the user's sign-in redirects"
signed_in_works "signed in"
Q "DELETE FROM 202_api_keys WHERE user_id = '$USER_ID'; DELETE FROM 202_user_role WHERE user_id = '$USER_ID'; DELETE FROM 202_users WHERE user_id = '$USER_ID'"
eq "$(Q "SELECT COUNT(*) FROM 202_users WHERE user_id = '$USER_ID'")" "0" "the row is deleted"
refused "row gone"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
