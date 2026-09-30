#!/bin/bash
# Live pass for the standalone and pre-login pages on the v2 shell (U7):
# sign in, the password reset pair, the license-key page, the setup wizard
# on an installed instance, the 404, the retired 202-Mobile addresses, and
# the three feed sections (TV202, Hot Deals, the App Store). Driven over
# HTTP against a running instance, with the database read back after every
# write, including every refusal: a missing token writes nothing and says so
# in the page's own sentence.
#
# tests/live/upgrade-csrf.sh proves the upgrader, and install-instance.sh
# (tests/fixtures/agent-eval/ci/) drives the installer; this covers the rest.
#
# Every POST submits the form's OWN fields, serialized from the page by
# form-body.py (error pattern #21).
#
# It changes the admin's password through the reset link and puts the
# original hash back at the end; it sets and clears the ClickServer API key
# and puts it back too.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
SERVER_LOG=${P202_SERVER_LOG:-}

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
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
LOG_START=0
if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then LOG_START=$(wc -l < "$SERVER_LOG"); fi
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
flash(){ if grep -qF -- "<div class=\"p202-flash__body\">$2" "$1"; then ok "$3"; else bad "$3"; grep -oE '<div class="p202-flash__body">[^<]*' "$1" | sed 's/^/    | /'; fi; }

# A request with a jar; the body to a file, the status (and any redirect) to $OUT/.code.
get()  { curl -sS -b "$1" -c "$1" -o "$3" -w '%{http_code} %{redirect_url}' "$BASE/$2" > "$OUT/.code"; }
code() { cut -d' ' -f1 < "$OUT/.code"; }
where(){ cut -d' ' -f2- < "$OUT/.code"; }
# Submit one form of a saved page, never following a redirect.
#   submit JAR PAGE_FILE MARKER URL OUT_FILE [name=value ...]
submit() {
  local jar="$1" page="$2" marker="$3" url="$4" out="$5"; shift 5
  if ! python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body"; then
    bad "the form carrying '$marker' is on the page"
    : > "$out"; echo "000 " > "$OUT/.code"
    return
  fi
  curl -sS -b "$jar" -c "$jar" -o "$out" -w '%{http_code} %{redirect_url}' \
    --data-binary @"$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url" > "$OUT/.code"
}
standalone() { # FILE PAGE — the page rendered on the standalone v2 shell, cleanly
  if grep -q 'p202-shell-v2 p202-standalone' "$1" && grep -q 'p202-standalone__column' "$1" \
     && ! grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught|col-xs-|btn-p202|fui-' "$1"; then
    ok "$2: the standalone v2 shell, no classic class, no PHP noise"
  else
    bad "$2: v2=$(grep -c 'p202-shell-v2' "$1") classic=$(grep -cE 'col-xs-|btn-p202|fui-' "$1") noise=$(grep -cE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$1")"
  fi
}

# A connection failure must not read as "no such user" below.
mysql_q "$DB" -e 'SELECT 1' > /dev/null || { echo "cannot reach database $DB (P202_DB_HOST/P202_DB_PORT)" >&2; exit 2; }
USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
[ -n "$USER_ID" ] || { echo "no user $P202_USER in $DB" >&2; exit 2; }
EMAIL=$(Q "SELECT user_email FROM 202_users WHERE user_id=$USER_ID")
ORIG_HASH=$(Q "SELECT user_pass FROM 202_users WHERE user_id=$USER_ID")
ORIG_LICENSE=$(Q "SELECT IFNULL(p202_customer_api_key, '') FROM 202_users WHERE user_id=$USER_ID")
ORIG_CS_KEY=$(Q "SELECT IFNULL(clickserver_api_key, '') FROM 202_users WHERE user_id=$USER_ID")
restore() {
  mysql_q "$DB" -e "UPDATE 202_users SET user_pass='$ORIG_HASH', user_pass_key=NULL, user_pass_time=0, p202_customer_api_key='$ORIG_LICENSE', clickserver_api_key=NULLIF('$ORIG_CS_KEY', '') WHERE user_id=$USER_ID; DELETE FROM 202_users_log WHERE user_name IN ('$P202_USER', 'u7-nobody') AND login_time >= $(date +%s) - 3600 AND login_success = 0;"
}
trap restore EXIT
# The failed sign-ins below count toward the brute-force throttle; start clean.
restore

# ─────────────────────────────────────────────────────────────────────
say "signed out: every standalone page renders on the v2 shell"
OUTJAR=$(mktemp)
get "$OUTJAR" "202-login.php" "$OUT/login.html"; eq "$(code)" 200 "sign-in answers 200"; standalone "$OUT/login.html" "202-login.php"
get "$OUTJAR" "202-lost-pass.php" "$OUT/lost.html"; standalone "$OUT/lost.html" "202-lost-pass.php"
get "$OUTJAR" "202-pass-reset.php?key=nope" "$OUT/reset-bad.html"; standalone "$OUT/reset-bad.html" "202-pass-reset.php with an unknown key"
has "$OUT/reset-bad.html" 'This reset link does not work' "an unknown reset key is said, on the page's own card"
get "$OUTJAR" "202-404.php" "$OUT/404.html"; eq "$(code)" 404 "the 404 page answers 404"; standalone "$OUT/404.html" "202-404.php"
get "$OUTJAR" "api-key-required.php" "$OUT/license.html"; standalone "$OUT/license.html" "api-key-required.php"
get "$OUTJAR" "202-config/requirements.php" "$OUT/req.html"; standalone "$OUT/req.html" "requirements.php (installed)"
has "$OUT/req.html" 'Already Installed' "the requirements step knows the install is done"
get "$OUTJAR" "202-config/setup-config.php" "$OUT/wiz0.html"; standalone "$OUT/wiz0.html" "setup-config.php"
has "$OUT/wiz0.html" 'Prosper202 is already set up' "the setup wizard's welcome says the install is done instead of starting it"
get "$OUTJAR" "202-config/upgrade.php" "$OUT/upg.html"; eq "$(code)" 302 "the upgrader sends an up-to-date install on to sign in"

say "the retired 202-Mobile addresses lead to the responsive pages"
get "$OUTJAR" "202-Mobile/" "$OUT/m1.html"; eq "$(code) $(where)" "302 $BASE/" "202-Mobile/ goes to the root, which routes the visitor"
get "$OUTJAR" "202-Mobile/202-login.php" "$OUT/m2.html"; eq "$(code) $(where)" "302 $BASE/202-login.php?redirect=%2Ftracking202%2Foverview%2F" "the mobile sign-in is the sign-in, landing on Campaign Overview"
get "$OUTJAR" "202-Mobile/mini-stats/" "$OUT/m3.html"; eq "$(code) $(where)" "302 $BASE/tracking202/overview/" "the mini stats are Campaign Overview"

# ─────────────────────────────────────────────────────────────────────
say "sign in: refusals in words, then the form's own fields sign in"
submit "$OUTJAR" "$OUT/login.html" user_pass "202-login.php" "$OUT/l-notoken.html" "user_name=$P202_USER" "user_pass=$P202_PASS" token=
eq "$(code)" 200 "a sign-in without the session token is answered, not redirected"
flash "$OUT/l-notoken.html" 'Your session has expired. Please reload the page and try again.' "and refused in words"
submit "$OUTJAR" "$OUT/login.html" user_pass "202-login.php" "$OUT/l-wrong.html" "user_name=$P202_USER" "user_pass=not-the-password"
flash "$OUT/l-wrong.html" 'Your username or password is incorrect.' "a wrong password is refused in words"
has "$OUT/l-wrong.html" "value=\"$P202_USER\"" "and the username typed is kept"
submit "$OUTJAR" "$OUT/login.html" user_pass "202-login.php" "$OUT/l-ok.html" "user_name=$P202_USER" "user_pass=$P202_PASS"
eq "$(code) $(where)" "302 $BASE/202-account" "the right password signs in and goes to the account home"

# ─────────────────────────────────────────────────────────────────────
say "the password reset: a link by email, used once"
RJAR=$(mktemp)
get "$RJAR" "202-lost-pass.php" "$OUT/lp.html"
submit "$RJAR" "$OUT/lp.html" user_email "202-lost-pass.php" "$OUT/lp-notoken.html" "user_name=$P202_USER" "user_email=$EMAIL" token=
flash "$OUT/lp-notoken.html" 'Your session has expired. Please reload the page and try again.' "a reset request without the token is refused in words"
eq "$(Q "SELECT IFNULL(user_pass_key, '') FROM 202_users WHERE user_id=$USER_ID")" "" "and issues no key"
submit "$RJAR" "$OUT/lp.html" user_email "202-lost-pass.php" "$OUT/lp-other.html" "user_name=$P202_USER" "user_email=someone-else@example.test"
has "$OUT/lp-other.html" 'Check your email' "a username and email that do not match answer the same, so accounts cannot be probed"
eq "$(Q "SELECT IFNULL(user_pass_key, '') FROM 202_users WHERE user_id=$USER_ID")" "" "and issue no key"
submit "$RJAR" "$OUT/lp.html" user_email "202-lost-pass.php" "$OUT/lp-ok.html" "user_name=$P202_USER" "user_email=$EMAIL"
has "$OUT/lp-ok.html" 'Check your email' "a matching request says to check the email"
KEY=$(Q "SELECT IFNULL(user_pass_key, '') FROM 202_users WHERE user_id=$USER_ID")
[ ${#KEY} -eq 64 ] && ok "and stores a 64-character reset key (the page used to die before it could)" || bad "a reset key was stored (got '${KEY}')"
get "$RJAR" "202-pass-reset.php?key=$KEY" "$OUT/pr.html"
standalone "$OUT/pr.html" "202-pass-reset.php with the key"
submit "$RJAR" "$OUT/pr.html" user_pass "202-pass-reset.php?key=$KEY" "$OUT/pr-notoken.html" user_pass=u7-new-password-1 verify_user_pass=u7-new-password-1 token=
flash "$OUT/pr-notoken.html" 'Your session has expired. Please reload the page and try again.' "a new password without the token is refused in words"
submit "$RJAR" "$OUT/pr.html" user_pass "202-pass-reset.php?key=$KEY" "$OUT/pr-mismatch.html" user_pass=u7-new-password-1 verify_user_pass=u7-new-password-2
flash "$OUT/pr-mismatch.html" 'Your passwords did not match, please try again' "two different passwords are refused in the page's sentence"
eq "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$USER_ID")" "$ORIG_HASH" "no refusal changed the password"
submit "$RJAR" "$OUT/pr.html" user_pass "202-pass-reset.php?key=$KEY" "$OUT/pr-ok.html" user_pass=u7-new-password-1 verify_user_pass=u7-new-password-1
has "$OUT/pr-ok.html" 'Password changed' "the form's own fields change the password"
eq "$(Q "SELECT IFNULL(user_pass_key, '') FROM 202_users WHERE user_id=$USER_ID")" "" "and the link is used up"
NJAR=$(mktemp)
get "$NJAR" "202-login.php" "$OUT/login2.html"
submit "$NJAR" "$OUT/login2.html" user_pass "202-login.php" "$OUT/l-new.html" "user_name=$P202_USER" "user_pass=u7-new-password-1"
eq "$(code)" 302 "the new password signs in"
get "$RJAR" "202-pass-reset.php?key=$KEY" "$OUT/pr-again.html"
has "$OUT/pr-again.html" 'This reset link does not work' "the same link again is refused"
restore

# ─────────────────────────────────────────────────────────────────────
say "the license-key page: the session token before any write"
LJAR=$(mktemp)
get "$LJAR" "api-key-required.php" "$OUT/lk.html"
submit "$LJAR" "$OUT/lk.html" api_key "api-key-required.php" "$OUT/lk-notoken.html" api_key=u7-not-a-key token=
flash "$OUT/lk-notoken.html" 'Your session has expired. Please reload the page and try again.' "a key posted without the token is refused in words (it used to be checked and saved)"
submit "$LJAR" "$OUT/lk.html" api_key "api-key-required.php" "$OUT/lk-empty.html" "api_key= "
flash "$OUT/lk-empty.html" 'Please enter your API key.' "an empty key is refused in words"
eq "$(Q "SELECT IFNULL(p202_customer_api_key, '') FROM 202_users WHERE user_id=$USER_ID")" "$ORIG_LICENSE" "no refusal changed the saved license key"
# With the token, the license service decides; the page must say what it
# decided and the row must agree with the page (the service answers "Key
# valid" to any key at the time of writing, so either answer is possible).
submit "$LJAR" "$OUT/lk.html" api_key "api-key-required.php" "$OUT/lk-checked.html" api_key=u7-not-a-key
STORED=$(Q "SELECT IFNULL(p202_customer_api_key, '') FROM 202_users WHERE user_id=$USER_ID")
if grep -qF 'License key saved' "$OUT/lk-checked.html"; then
  eq "$STORED" "u7-not-a-key" "the service accepted the key; the page says saved and the key is stored"
else
  flash "$OUT/lk-checked.html" 'Invalid API key. Please check your key and try again.' "the service rejected the key, and the page says so"
  eq "$STORED" "$ORIG_LICENSE" "and the saved key is unchanged"
fi
restore

# ─────────────────────────────────────────────────────────────────────
say "the setup wizard will not rewrite an installed instance's configuration"
WJAR=$(mktemp)
get "$WJAR" "202-config/setup-config.php?step=1" "$OUT/wiz1.html"
has "$OUT/wiz1.html" 'Prosper202 is already set up' "the database form is not offered"
hasnt "$OUT/wiz1.html" 'name="dbpass"' "and no field for the database password is rendered"
# The configuration this checkout serves, when the server runs from it.
CONFIG="$HERE/../../202-config.php"
BEFORE=$( [ -f "$CONFIG" ] && md5sum "$CONFIG" | cut -d' ' -f1 )
# The attack this closes: one unauthenticated POST that names another
# database server. There is no form to take the fields from, so they are
# sent as a forger would send them.
curl -sS -b "$WJAR" -c "$WJAR" -o "$OUT/wiz2.html" --data "dbname=u7_evil&dbuser=u7-nobody&dbpass=x&dbhost=127.0.0.1&dbhostro=127.0.0.1&mchost=127.0.0.1" "$BASE/202-config/setup-config.php?step=2"
has "$OUT/wiz2.html" 'Prosper202 is already set up' "a POST naming another database is refused in words"
if [ -n "$BEFORE" ]; then
  eq "$(md5sum "$CONFIG" | cut -d' ' -f1)" "$BEFORE" "and 202-config.php is not rewritten"
fi
FRESH=$(mktemp)
get "$FRESH" "202-login.php" "$OUT/login-after.html"
eq "$(code)" 200 "the instance still answers from its own database"
get "$WJAR" "202-config/setup-config.php?step=1.1" "$OUT/wiz-mig-modern.html"
has "$OUT/wiz-mig-modern.html" 'is already in the current format' "the legacy-format update does not open on a current 202-config.php"
hasnt "$OUT/wiz-mig-modern.html" 'id="setup-config-migrate"' "and offers no update"

# ─────────────────────────────────────────────────────────────────────
say "behind a TLS-terminating proxy every session cookie is Secure, the wizard's too"
for page in 202-config/setup-config.php 202-login.php; do
  SC=$(curl -sS -o /dev/null -D - -H 'X-Forwarded-Proto: https' "$BASE/$page" | tr -d '\r' | grep -i '^set-cookie: PHPSESSID=')
  if printf '%s' "$SC" | grep -qi '; secure'; then ok "$page: the session cookie carries Secure"; else bad "$page: the session cookie carries Secure (got '$SC')"; fi
  SC=$(curl -sS -o /dev/null -D - "$BASE/$page" | tr -d '\r' | grep -i '^set-cookie: PHPSESSID=')
  if [ -n "$SC" ] && ! printf '%s' "$SC" | grep -qi '; secure'; then ok "$page: and not over plain HTTP"; else bad "$page: and not over plain HTTP (got '$SC')"; fi
done

# ─────────────────────────────────────────────────────────────────────
# The legacy-format update (setup-config.php?step=1.1 → 2.2) is the one
# thing that passes the wizard's lock on an installed instance. Driven only
# when this pass can see the configuration the server runs from (it swaps in
# a legacy one and puts the original bytes back, whatever happens).
if [ -f "$CONFIG" ] && [ -w "$CONFIG" ] && grep -qF "\$dbname = '$DB';" "$CONFIG"; then
  say "a legacy 202-config.php is brought to the current format, and nothing else passes the lock"
  CONFIG_SAVED=$(mktemp)
  cp -p "$CONFIG" "$CONFIG_SAVED"
  put_config_back() { cp -p "$CONFIG_SAVED" "$CONFIG"; }
  trap 'put_config_back; restore' EXIT
  # The settings the running config holds, in the format before the DB class.
  { echo '<?php'; echo '// ** MySQL settings ** //'
    grep -E "^\\\$(dbname|dbuser|dbpass|dbhost|mchost) = '" "$CONFIG"; } > "$OUT/legacy-config.php"
  cp "$OUT/legacy-config.php" "$CONFIG"
  LEGACY_MD5=$(md5sum "$CONFIG" | cut -d' ' -f1)
  sleep 3 # php -S revalidates cached bytecode every 2s (CLAUDE.md)
  MJAR=$(mktemp)
  get "$MJAR" "202-config/setup-config.php?step=1" "$OUT/mig-lock.html"
  has "$OUT/mig-lock.html" 'Prosper202 is already set up' "a legacy install is still locked against the database form"
  hasnt "$OUT/mig-lock.html" 'name="dbpass"' "which is not rendered"
  has "$OUT/mig-lock.html" "href='setup-config.php?step=1.1'>Update 202-config.php</a>" "and the lock page offers the format update"
  get "$MJAR" "202-config/setup-config.php?step=1.1" "$OUT/mig1.html"
  standalone "$OUT/mig1.html" "setup-config.php?step=1.1"
  has "$OUT/mig1.html" 'id="setup-config-migrate"' "the update is offered on a legacy 202-config.php"
  DBPASS_NOW=$(grep -oE "^\\\$dbpass = '[^']*'" "$OUT/legacy-config.php" | sed "s/^[^']*'//; s/'\$//")
  if [ -n "$DBPASS_NOW" ]; then hasnt "$OUT/mig1.html" "$DBPASS_NOW" "and the page does not show the database password"; fi
  curl -sS -b "$MJAR" -c "$MJAR" -o "$OUT/mig-get.html" "$BASE/202-config/setup-config.php?step=2.2"
  has "$OUT/mig-get.html" 'Security check failed' "a GET to the write step writes nothing"
  submit "$MJAR" "$OUT/mig1.html" token "202-config/setup-config.php?step=2.2" "$OUT/mig-notoken.html" token=
  has "$OUT/mig-notoken.html" 'Security check failed' "a POST without the session token is refused in words"
  eq "$(md5sum "$CONFIG" | cut -d' ' -f1)" "$LEGACY_MD5" "and 202-config.php is not rewritten"
  # The request carries hosts a forger would send (ones the connection check
  # before the write does not try, so only reading them could let them in).
  submit "$MJAR" "$OUT/mig1.html" token "202-config/setup-config.php?step=2.2" "$OUT/mig2.html" dbhostro=203.0.113.9 mchost=203.0.113.9
  has "$OUT/mig2.html" '202-config.php updated' "the form's own POST updates the file"
  grep -q 'class DB' "$CONFIG" && ok "202-config.php is in the current format" || bad "202-config.php is in the current format"
  for v in dbname dbuser dbpass dbhost mchost; do
    eq "$(grep -E "^\\\$$v = '" "$CONFIG" | sed 's/ *\/\/.*//')" "$(grep -E "^\\\$$v = '" "$OUT/legacy-config.php" | sed 's/ *\/\/.*//')" "\$$v is carried over unchanged, whatever the request said"
  done
  eq "$(grep -E "^\\\$dbhostro = '" "$CONFIG" | sed "s/^[^']*'//; s/'.*//")" "$(grep -E "^\\\$dbhost = '" "$CONFIG" | sed "s/^[^']*'//; s/'.*//")" "the reporting host it did not have is the database host"
  eq "$(stat -c %a "$CONFIG")" "640" "owner/group readable only"
  eq "$(find "$(dirname "$CONFIG")" -maxdepth 1 -name '.202-config.*.tmp.php' | wc -l)" "0" "no temporary file is left beside it"
  sleep 3
  get "$(mktemp)" "202-login.php" "$OUT/mig-login.html"
  eq "$(code)" 200 "the instance answers from the updated file"
  hasnt "$OUT/mig-login.html" 'Fatal error' "without a PHP error"
  AFTER_MD5=$(md5sum "$CONFIG" | cut -d' ' -f1)
  submit "$MJAR" "$OUT/mig1.html" token "202-config/setup-config.php?step=2.2" "$OUT/mig3.html"
  has "$OUT/mig3.html" 'is already in the current format' "the update does not run a second time"
  eq "$(md5sum "$CONFIG" | cut -d' ' -f1)" "$AFTER_MD5" "and the current file is left as it is"
  put_config_back
  sleep 3
else
  say "SKIP the legacy-format update: $CONFIG is not this instance's writable configuration"
fi

# ─────────────────────────────────────────────────────────────────────
say "signed in: TV202, Hot Deals and the App Store on the v2 shell"
AJAR=$(mktemp)
get "$AJAR" "202-login.php" "$OUT/login3.html"
submit "$AJAR" "$OUT/login3.html" user_pass "202-login.php" "$OUT/l3.html" "user_name=$P202_USER" "user_pass=$P202_PASS"
for page in 202-tv/ 202-resources/ 202-appstore/; do
  f="$OUT/sec-$(echo "$page" | tr '/' '_').html"
  get "$AJAR" "$page" "$f"
  if [ "$(code)" = 200 ] && grep -q 'p202-shell-v2' "$f" && grep -q 'p202-page-header__title' "$f" && ! grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught|col-xs-|btn-p202|fui-|class="tile' "$f"; then
    ok "$page: 200, v2 shell, a page header, no classic class, clean"
  else
    bad "$page: status $(code), v2=$(grep -c p202-shell-v2 "$f")"
  fi
done
if grep -q 'ratio-16x9' "$OUT/sec-202-tv_.html"; then
  hasnt "$OUT/sec-202-tv_.html" 'width="853"' "TV202 draws the feed's videos in responsive frames, not its 853px ones"
else
  has "$OUT/sec-202-tv_.html" 'TV202 is not available right now' "TV202's feed did not answer, and the page says so"
fi

say "the App Store key: token, masked value, a remove that is said"
get "$AJAR" "202-appstore/" "$OUT/as.html"
submit "$AJAR" "$OUT/as.html" clickserver_api_key "202-appstore/" "$OUT/as-notoken.html" clickserver_api_key= token=
flash "$OUT/as-notoken.html" 'This form expired or did not come from this page, so nothing was saved.' "a key posted without the token is refused in words"
mysql_q "$DB" -e "UPDATE 202_users SET clickserver_api_key='u7-saved-key-0123456789abcdefXYZ' WHERE user_id=$USER_ID"
get "$AJAR" "202-appstore/" "$OUT/as2.html"
has "$OUT/as2.html" 'value="**********************9abcdefXYZ"' "a saved key is shown masked: its first 22 characters hidden"
submit "$AJAR" "$OUT/as2.html" clickserver_api_key "202-appstore/" "$OUT/as-masked.html"
eq "$(code)" 303 "sending the masked key back redirects"
get "$AJAR" "202-appstore/" "$OUT/as3.html"
flash "$OUT/as3.html" 'Nothing was changed: the key shown is masked.' "and says nothing changed"
eq "$(Q "SELECT clickserver_api_key FROM 202_users WHERE user_id=$USER_ID")" "u7-saved-key-0123456789abcdefXYZ" "the saved key is kept"
submit "$AJAR" "$OUT/as3.html" clickserver_api_key "202-appstore/" "$OUT/as-clear.html" clickserver_api_key=
get "$AJAR" "202-appstore/" "$OUT/as4.html"
flash "$OUT/as4.html" 'ClickServer API key removed.' "an emptied key is removed, and the page says so"
eq "$(Q "SELECT IFNULL(clickserver_api_key, '') FROM 202_users WHERE user_id=$USER_ID")" "" "and it is gone"
restore

if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then
  say "the server logged no PHP warnings from these pages"
  NOISE=$(tail -n +"$((LOG_START+1))" "$SERVER_LOG" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' | grep -cvE 'Undefined array key "SERVER_ADDR"')
  eq "$NOISE" "0" "no PHP warning, notice or fatal in $SERVER_LOG since the pass began"
fi

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" = 0 ]
