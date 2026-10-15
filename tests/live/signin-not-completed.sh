#!/bin/bash
# Live pass: a sign-in whose password is right but which a database failure
# stops is logged as not completed (202_users_log.login_success = 2), which
# the sign-in throttle does not count; wrong passwords still lock out.
#
# The throttle refuses a user name with 10 failed attempts, or an address
# with 50, in 15 minutes (AUTH::is_rate_limited()). A sign-in refused because
# its record could not be written was logged as failed, so a database hiccup
# locked the user out as a password guesser would be. This pass makes that
# write fail for real — a trigger that refuses an UPDATE of its own users'
# rows, which is the write SignInRecord makes and the one a legacy hash's
# rehash makes — and asserts the plant landed before relying on it. Then:
#
#   1. the right password, past the per-user limit: every attempt is told the
#      sign-in could not be completed, is logged as 2, and none is throttled;
#   2. a legacy MD5 password whose rehash is refused: the same;
#   3. with the trigger gone, the right password signs in (302) at once;
#   4. Account › Settings lists those attempts as Not completed, GET
#      /system/login-log answers status not_completed and ?status= selects
#      them, and the Go CLI's --status does too (when P202_BIN is given);
#   5. ten wrong passwords lock the user out, the right one included.
#
# It makes its own two users (an Admin with a REST API key, and one with a
# legacy password hash) and removes them, their key, role and preferences
# row, the trigger, and every 202_users_log row of theirs or of its address,
# on any exit. It reads no other user and changes none, so it may run
# against a shared instance; it does not source guard.sh, which is for the
# passes that truncate tables. The successful sign-in adds its address to
# 202_ips (the address index rows are shared, and are kept).
#
# Every request carries X-Forwarded-For with an address of the pass's own
# (the address the throttle keys on, AUTH::client_ip()), so other sign-ins
# from 127.0.0.1 cannot move its per-address count.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
BIN=${P202_BIN:-}
SERVER_LOG=${P202_SERVER_LOG:-}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
LOG_START=0
if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then LOG_START=$(wc -l < "$SERVER_LOG"); fi
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
flash(){ if grep -qF -- "<div class=\"p202-flash__body\">$2" "$1"; then ok "$3"; else bad "$3"; grep -oE '<div class="p202-flash__body">[^<]*' "$1" | sed 's/^/    | /'; fi; }

mysql_q "$DB" -e 'SELECT 1' > /dev/null || { echo "cannot reach database $DB (P202_DB_HOST/P202_DB_PORT)" >&2; exit 2; }
[ -f "$ROOT/vendor/autoload.php" ] || { echo "vendor/autoload.php is missing: the password is hashed with the app's own hash_user_pass()" >&2; exit 2; }

TAG="snc$(date +%s | tail -c 6)$((RANDOM % 1000))"
ADMIN="$TAG-admin"
LEGACY="$TAG-legacy"
PW="pw-$TAG-$RANDOM$RANDOM"
LEGACY_PW="legacy-$TAG-$RANDOM"
API_KEY="key-$TAG-$RANDOM$RANDOM$RANDOM"
XFF="203.0.113.$((RANDOM % 200 + 20))"
TRIGGER="p202_${TAG}_refuse"
MAX_PER_USER=$(php -r 'require $argv[1] . "/202-config/functions-auth.php"; echo AUTH::RATE_LIMIT_MAX_PER_USER;' "$ROOT")
HASH=$(php -r 'require $argv[1] . "/202-config/functions-auth.php"; echo hash_user_pass($argv[2]);' "$ROOT" "$PW")
[ -n "$MAX_PER_USER" ] && [ -n "$HASH" ] || { echo "could not read the throttle limit or hash the password" >&2; exit 2; }

ADMIN_ID=''; LEGACY_ID=''
cleanup() {
  Q "DROP TRIGGER IF EXISTS $TRIGGER" > /dev/null
  local ids="0${ADMIN_ID:+,$ADMIN_ID}${LEGACY_ID:+,$LEGACY_ID}"
  Q "DELETE FROM 202_users_log WHERE user_name IN ('$ADMIN', '$LEGACY') OR ip_address = '$XFF';
     DELETE FROM 202_api_keys WHERE user_id IN ($ids);
     DELETE FROM 202_user_role WHERE user_id IN ($ids);
     DELETE FROM 202_users_pref WHERE user_id IN ($ids);
     DELETE FROM 202_auth_keys WHERE user_id IN ($ids);
     DELETE FROM 202_users WHERE user_id IN ($ids);" > /dev/null
  rm -rf "$OUT"
}
trap cleanup EXIT

say "setup: two users of the pass's own"
NOW=$(date +%s)
Q "INSERT INTO 202_users (user_name, user_pass, user_email, user_dash_email, user_timezone, user_time_register,
     install_hash, user_hash, p202_customer_api_key, user_active, user_deleted)
   VALUES ('$ADMIN', '$HASH', '$ADMIN@example.com', '$ADMIN@example.com', 'UTC', $NOW, '', '', 'live-$TAG', 1, 0)"
ADMIN_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$ADMIN'")
Q "INSERT INTO 202_users (user_name, user_pass, user_email, user_dash_email, user_timezone, user_time_register,
     install_hash, user_hash, user_active, user_deleted)
   VALUES ('$LEGACY', MD5('$LEGACY_PW'), '$LEGACY@example.com', '', 'UTC', $NOW, '', '', 1, 0)"
LEGACY_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$LEGACY'")
[ -n "$ADMIN_ID" ] && [ -n "$LEGACY_ID" ] || { echo "could not create the pass's users" >&2; exit 2; }
Q "INSERT INTO 202_users_pref (user_id) VALUES ($ADMIN_ID);
   INSERT INTO 202_user_role (user_id, role_id) SELECT $ADMIN_ID, role_id FROM 202_roles WHERE role_name = 'Admin';
   INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES ($ADMIN_ID, '$API_KEY', NULL, $NOW);"
eq "$(Q "SELECT COUNT(*) FROM 202_user_role WHERE user_id = $ADMIN_ID")" 1 "user $ADMIN_ID ($ADMIN) is an Admin with its own key; user $LEGACY_ID ($LEGACY) has a legacy MD5 hash"

# A sign-in with the form's own fields (form-body.py), from a browser of its
# own: GET the page, then POST it. $JAR is left holding its cookies.
JAR=''
signin() { # USER PASSWORD OUT_FILE
  JAR=$(mktemp "$OUT/jar.XXXXXX")
  local jar="$JAR"
  curl -sS -b "$jar" -c "$jar" -H "X-Forwarded-For: $XFF" -o "$OUT/page.html" "$BASE/202-login.php"
  if ! python3 "$HERE/form-body.py" "$OUT/page.html" user_pass "user_name=$1" "user_pass=$2" > "$OUT/.body"; then
    : > "$3"; echo "000 " > "$OUT/.code"; return
  fi
  curl -sS -b "$jar" -c "$jar" -H "X-Forwarded-For: $XFF" -o "$3" -w '%{http_code} %{redirect_url}' \
    --data-binary @"$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-login.php" > "$OUT/.code"
}
code() { cut -d' ' -f1 < "$OUT/.code"; }
where(){ cut -d' ' -f2- < "$OUT/.code"; }
rows() { Q "SELECT COUNT(*) FROM 202_users_log WHERE user_name = '$1' AND login_success = $2"; }

NOT_COMPLETED='Your sign-in could not be completed. Please try again in a moment.'
THROTTLED='Too many failed login attempts. Please wait a few minutes and try again.'
WRONG='Your username or password is incorrect.'

say "plant: the database refuses an UPDATE of the pass's users, and only theirs"
# Through stdin: the client would split `mysql -e` at the body's semicolons.
printf '%s\n' 'DELIMITER //' \
  "CREATE TRIGGER $TRIGGER BEFORE UPDATE ON 202_users FOR EACH ROW BEGIN
     IF OLD.user_id IN ($ADMIN_ID, $LEGACY_ID) THEN
       SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'signin-not-completed live pass: this update is refused';
     END IF;
   END//" 'DELIMITER ;' | mysql_q "$DB"
eq "$(Q "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$TRIGGER'")" 1 "the trigger is in place"
if refused=$(Q "UPDATE 202_users SET modal_status = 1 WHERE user_id = $ADMIN_ID" 2>&1); then
  bad "the plant landed: an update of user $ADMIN_ID was written"
else
  case "$refused" in *'this update is refused'*) ok "the plant landed: an update of user $ADMIN_ID is refused by the trigger";; *) bad "the update failed for another reason: $refused";; esac
fi

say "1. the right password, $((MAX_PER_USER + 2)) times, past the per-user limit of $MAX_PER_USER"
throttled=0; completed=0; other=0
for i in $(seq 1 $((MAX_PER_USER + 2))); do
  signin "$ADMIN" "$PW" "$OUT/nc-$i.html"
  if [ "$(code)" = 302 ]; then completed=$((completed+1))
  elif grep -qF "$THROTTLED" "$OUT/nc-$i.html"; then throttled=$((throttled+1))
  elif ! grep -qF "<div class=\"p202-flash__body\">$NOT_COMPLETED" "$OUT/nc-$i.html"; then other=$((other+1)); fi
done
eq "$throttled" 0 "no attempt was throttled"
eq "$completed" 0 "no attempt signed in while the record is refused"
eq "$other" 0 "every attempt was told the sign-in could not be completed"
flash "$OUT/nc-$((MAX_PER_USER + 2)).html" "$NOT_COMPLETED" "the last attempt's sentence (it is not 'incorrect', nor throttled)"
eq "$(rows "$ADMIN" 2)" "$((MAX_PER_USER + 2))" "each attempt is logged as not completed (login_success 2)"
eq "$(rows "$ADMIN" 0)" 0 "none is logged as failed"
eq "$(Q "SELECT IFNULL(user_last_login_ip_id, 0) FROM 202_users WHERE user_id = $ADMIN_ID")" 0 "the refused record wrote nothing"

say "2. a legacy password whose rehash is refused"
signin "$LEGACY" "$LEGACY_PW" "$OUT/legacy.html"
eq "$(code)" 200 "the sign-in is answered, not redirected"
flash "$OUT/legacy.html" "$NOT_COMPLETED" "and told it could not be completed"
eq "$(rows "$LEGACY" 2) $(rows "$LEGACY" 0)" "1 0" "logged as not completed, not as failed"
eq "$(Q "SELECT user_pass = MD5('$LEGACY_PW') FROM 202_users WHERE user_id = $LEGACY_ID")" 1 "the stored hash is the legacy one still"

say "3. with the trigger gone, the right password signs in at once"
Q "DROP TRIGGER $TRIGGER"
eq "$(Q "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$TRIGGER'")" 0 "the trigger is gone"
signin "$ADMIN" "$PW" "$OUT/ok.html"
eq "$(code)" 302 "signed in after $((MAX_PER_USER + 2)) sign-ins not completed"
case "$(where)" in *202-account*) ok "and sent on to the account";; *) bad "redirected to '$(where)'";; esac
eq "$(rows "$ADMIN" 1)" 1 "logged as passed"
[ "$(Q "SELECT IFNULL(user_last_login_ip_id, 0) FROM 202_users WHERE user_id = $ADMIN_ID")" != 0 ] && ok "the sign-in is recorded" || bad "the sign-in is recorded"

say "4a. Account › Settings lists them as Not completed"
curl -sS -b "$JAR" -c "$JAR" -H "X-Forwarded-For: $XFF" -o "$OUT/settings.html" -w '%{http_code}' \
  "$BASE/202-account/administration.php" > "$OUT/.code"
eq "$(cat "$OUT/.code")" 200 "the page answers the signed-in Admin"
python3 "$HERE/html-table.py" "$OUT/settings.html" sign-in-attempts > "$OUT/attempts.tsv" || bad "the page draws the sign-in attempts table"
attempts() { awk -F'\t' -v u="$1" -v a="$2" '$3 == u && $5 == a' "$OUT/attempts.tsv" | wc -l | tr -d ' '; }
eq "$(attempts "$ADMIN" 'Not completed')" "$((MAX_PER_USER + 2))" "$ADMIN: $((MAX_PER_USER + 2)) rows read Not completed"
eq "$(attempts "$ADMIN" 'Failed')" 0 "$ADMIN: none reads Failed"
eq "$(attempts "$ADMIN" 'Passed')" 1 "$ADMIN: the sign-in reads Passed"
eq "$(attempts "$LEGACY" 'Not completed')" 1 "$LEGACY: the refused rehash reads Not completed"
grep -qF 'p202-pill p202-pill--warn' "$OUT/settings.html" && ok "drawn as a warning pill, apart from Failed and Passed" || bad "drawn as a warning pill"

say "4b. GET /system/login-log"
api() { curl -sS -H "Authorization: Bearer $API_KEY" -o "$OUT/api.json" -w '%{http_code}' "$BASE/api/v3/system/login-log$1"; }
count_rows() { # USER STATUS LOGIN_SUCCESS(true|false) — rows of USER in $OUT/api.json with that status and boolean
  php -r '$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    echo count(array_filter($d["data"], fn ($r) => $r["user_name"] === $argv[2] && $r["status"] === $argv[3]
      && $r["login_success"] === ($argv[4] === "true")));' "$OUT/api.json" "$1" "$2" "$3"
}
eq "$(api '?limit=500')" 200 "answers the Admin's key"
eq "$(count_rows "$ADMIN" not_completed false)" "$((MAX_PER_USER + 2))" "status not_completed, login_success false, for each"
eq "$(count_rows "$ADMIN" passed true)" 1 "status passed, login_success true, for the sign-in"
eq "$(api '?status=not_completed&limit=500')" 200 "?status=not_completed answers"
eq "$(count_rows "$ADMIN" not_completed false) $(count_rows "$LEGACY" not_completed false)" "$((MAX_PER_USER + 2)) 1" "and lists both users' attempts not completed"
eq "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo implode(",", array_unique(array_column($d["data"], "status")));' "$OUT/api.json")" not_completed "and nothing else"
eq "$(api '?status=failed&limit=500')" 200 "?status=failed answers"
eq "$(count_rows "$ADMIN" not_completed false)$(count_rows "$ADMIN" failed false)" 00 "and lists none of them"
eq "$(api '?status=not-completed')" 422 "a status the API does not take is refused"
grep -q '"status"' "$OUT/api.json" && ok "naming status" || bad "naming status: $(cat "$OUT/api.json")"

if [ -n "$BIN" ]; then
  say "4c. p202 system login-log --status"
  CLIHOME=$(mktemp -d)
  HOME="$CLIHOME" "$BIN" config set-url "$BASE" > /dev/null 2>&1
  HOME="$CLIHOME" "$BIN" config set-key "$API_KEY" > /dev/null 2>&1
  HOME="$CLIHOME" "$BIN" system login-log --status not_completed --limit 500 --json > "$OUT/api.json" 2> "$OUT/cli.err"
  eq "$?" 0 "the CLI lists the attempts not completed"
  eq "$(count_rows "$ADMIN" not_completed false)" "$((MAX_PER_USER + 2))" "with status not_completed for each"
  HOME="$CLIHOME" "$BIN" system login-log --status completed --json > /dev/null 2> "$OUT/cli.err"
  eq "$?" 1 "a status the CLI does not take is exit 1"
  grep -q '"category": *"validation"' "$OUT/cli.err" && grep -qF 'not_completed' "$OUT/cli.err" \
    && ok "a validation envelope naming the statuses" || bad "the envelope: $(cat "$OUT/cli.err")"
  rm -rf "$CLIHOME"
else
  echo "  skip - P202_BIN not set: the Go CLI was not run"
fi

say "5. wrong passwords still lock the user out"
wrong=0
for i in $(seq 1 "$MAX_PER_USER"); do
  signin "$ADMIN" "wrong-$i" "$OUT/wrong-$i.html"
  grep -qF "<div class=\"p202-flash__body\">$WRONG" "$OUT/wrong-$i.html" && wrong=$((wrong+1))
done
eq "$wrong" "$MAX_PER_USER" "$MAX_PER_USER wrong passwords are each told so"
eq "$(rows "$ADMIN" 0)" "$MAX_PER_USER" "and logged as failed"
signin "$ADMIN" "wrong-again" "$OUT/wrong-more.html"
flash "$OUT/wrong-more.html" "$THROTTLED" "the next wrong password is throttled"
signin "$ADMIN" "$PW" "$OUT/right-throttled.html"
eq "$(code)" 200 "and so is the right one"
flash "$OUT/right-throttled.html" "$THROTTLED" "with the throttle's sentence"
eq "$(rows "$ADMIN" 0)" "$MAX_PER_USER" "throttled attempts are not logged"

if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then
  say "server log"
  noise=$(tail -n +$((LOG_START + 1)) "$SERVER_LOG" | grep -cE 'PHP (Fatal|Warning|Notice|Deprecated)|Uncaught')
  eq "$noise" 0 "no PHP error, warning or notice in the server log during the pass"
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
