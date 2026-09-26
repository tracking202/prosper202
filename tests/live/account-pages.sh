#!/bin/bash
# Live pass for the Account pages on the v2 shell (U6). Drives the real forms
# over HTTP — preferences, currency, API keys, the password, users and their
# roles, an integration key, click-data settings — and reads the database
# after each step, including every refusal: a bad token writes nothing and
# says so, an invalid field shows its sentence and writes nothing.
#
# Every POST submits the form's OWN fields, serialized from the page by
# form-body.py, and changes only what the step means to change. A pass that
# scraped the first token anywhere on the page would stay green with the
# token missing from the form that posts (error pattern #21).
#
# --- environment -------------------------------------------------------
# P202_DB must be a SCRATCH database: this pass creates and removes users and
# API keys and rewrites the account's preferences (it puts them back at the
# end). The guard below refuses a name that does not read as disposable.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
# Optional: the built-in server's log, read at the end for PHP warnings.
SERVER_LOG=${P202_SERVER_LOG:-}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass logs in as $P202_USER and needs its password." >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
# -----------------------------------------------------------------------

JAR=$(mktemp)
OUT=$(mktemp -d)
# Only what this pass causes: the log may carry lines from before it started.
LOG_START=0
if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then LOG_START=$(wc -l < "$SERVER_LOG"); fi
PASS=0; FAIL=0
Q() { mysql_q -N "$DB" -e "$1"; }

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
skip() { printf '  \033[33mSKIP\033[0m %s\n' "$1"; }
# Make one kind of write fail, the way a lost connection or a deadlock would:
# a trigger that refuses it. fail_writes NAME TABLE EVENT CONDITION; clear_fail NAME.
fail_writes() { mysql_q --delimiter='//' "$DB" -e "DROP TRIGGER IF EXISTS $1// CREATE TRIGGER $1 BEFORE $3 ON $2 FOR EACH ROW BEGIN IF $4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted by account-pages.sh'; END IF; END//" || bad "the planted failure $1 could not be created"; }
clear_fail() { mysql_q "$DB" -e "DROP TRIGGER IF EXISTS $1"; }
msgs() { grep -oE '<div class="p202-flash__body">[^<]*|<div class="invalid-feedback[^"]*">[^<]*' "$1" \
           | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }

REFUSED='This form expired or did not come from this page, so nothing was saved.'

# GET a page into a file.
get() { curl -sS -b "$JAR" -c "$JAR" -L "$BASE/$1" -o "$2" -w '%{http_code}' > "$OUT/.code"; }

# Submit one form of a page the way a browser would.
#   submit PAGE_FILE MARKER URL OUT_FILE [name=value ...]
# One POST, never followed automatically: the first status lands in
# FIRST_STATUS, so a write that redirected (303, post-redirect-get) is told
# apart from a refused submit that re-rendered in place (200). A redirect is
# then followed with a GET, as the browser does; a re-render is kept as is.
submit() {
  local page="$1" marker="$2" url="$3" out="$4"; shift 4
  if ! python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body"; then
    bad "the form carrying '$marker' is on the page"
    : > "$out"
    FIRST_STATUS=missing
    return
  fi
  FIRST_STATUS=$(curl -sS -b "$JAR" -c "$JAR" -o "$out" -D "$OUT/.headers" -w '%{http_code}' \
    --data-binary @"$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url")
  if [ "$FIRST_STATUS" = "303" ] || [ "$FIRST_STATUS" = "302" ]; then
    local location
    location=$(grep -i '^location:' "$OUT/.headers" | head -1 | sed 's/^[Ll]ocation: *//' | tr -d '\r')
    location="${location%%#*}"
    case "$location" in
      http*) ;;
      /*) location="${BASE%/}$location" ;;
      *) location="$BASE/$location" ;;
    esac
    curl -sS -b "$JAR" -c "$JAR" -L "$location" -o "$out"
  fi
  printf '    first=%s\n' "$FIRST_STATUS"
}

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "202-account/account.php" "$OUT/account.html"
hasnt "$OUT/account.html" 'name="user_name"' "session established (no login form)"
OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")

# What the pass changes, so it can be put back.
ORIG_PREFS=$(Q "SELECT CONCAT_WS('|', u.user_timezone, p.user_daily_email, p.user_keyword_searched_or_bidded, p.user_pref_dynamic_bid, p.user_pref_referer_data, p.user_pref_privacy, p.user_pref_cloak_referer, p.user_pref_ad_settings, IFNULL(p.user_tracking_domain,''), p.user_account_currency, IFNULL(p.ipqs_api_key,''), p.user_auto_database_optimization_days, IFNULL(p.user_delete_data_clickid,'NULL')) FROM 202_users u JOIN 202_users_pref p USING (user_id) WHERE u.user_id=$OWNER")
ORIG_HASH=$(Q "SELECT user_pass FROM 202_users WHERE user_id=$OWNER")
printf '    owner=%s prefs=%s\n' "$OWNER" "$ORIG_PREFS"

# ─────────────────────────────────────────────────────────────────────
say "every Account page renders on the v2 shell, with no PHP noise"
for page in 202-account/ 202-account/account.php 202-account/user-management.php \
            202-account/api-integrations.php 202-account/administration.php 202-account/help.php \
            "202-account/docs.php?doc=ui-standard" 202-account/vip-perks.php 202-account/clickservers.php \
            202-account/disable-safe-mode.php 202-account/api-key-required.php 202-account/app-key-required.php \
            202-account/auto-upgrade.php 202-account/auto-upgrade-premium.php; do
  f="$OUT/render-$(echo "$page" | tr '/?=' '___').html"
  get "$page" "$f"
  code=$(cat "$OUT/.code")
  if [ "$code" = "200" ] && grep -q 'p202-shell-v2' "$f" && ! grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$f"; then
    ok "$page: 200, v2 shell, clean"
  else
    bad "$page: status $code, v2=$(grep -c p202-shell-v2 "$f"), noise=$(grep -cE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$f")"
  fi
done

# ─────────────────────────────────────────────────────────────────────
say "preferences: a bad token writes nothing and says so"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_profile "202-account/account.php" "$OUT/p-bad.html" \
  token=forged user_timezone=Asia/Tokyo
msgs "$OUT/p-bad.html"
eq "$FIRST_STATUS" "303" "the refusal redirects"
has "$OUT/p-bad.html" "$REFUSED" "the refusal is said in the guard's own sentence"
eq "$(Q "SELECT user_timezone FROM 202_users WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f1)" "time zone unchanged"

say "preferences: an invalid email shows its sentence and writes nothing"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_profile "202-account/account.php" "$OUT/p-email.html" \
  user_email=not-an-email user_timezone=Asia/Tokyo
msgs "$OUT/p-email.html"
eq "$FIRST_STATUS" "200" "a refused submit re-renders in place"
has "$OUT/p-email.html" 'Please enter a valid email address.' "the email sentence is under the field"
has "$OUT/p-email.html" 'value="not-an-email"' "what was typed is kept"
eq "$(Q "SELECT user_timezone FROM 202_users WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f1)" "and nothing was written"

say "preferences: a value the form never offers is refused by name"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_profile "202-account/account.php" "$OUT/p-tz.html" \
  user_timezone=Mars/Olympus_Mons user_pref_privacy=everyone
msgs "$OUT/p-tz.html"
has "$OUT/p-tz.html" 'Choose a time zone from the list.' "an unknown time zone is refused"
has "$OUT/p-tz.html" 'You must select your privacy setting.' "an unknown privacy value is refused"
eq "$(Q "SELECT user_pref_privacy FROM 202_users_pref WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f6)" "privacy unchanged"

say "preferences: every field of the form saves"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_profile "202-account/account.php" "$OUT/p-ok.html" \
  user_timezone=Europe/London user_daily_email=09 user_keyword_searched_or_bidded=bidded \
  user_bid=1 user_referer=t202ref user_pref_privacy=eu cloak_referer=never \
  user_pref_ad_settings=hide_login user_tracking_domain=track.example.test
msgs "$OUT/p-ok.html"
eq "$FIRST_STATUS" "303" "a saved profile redirects (post-redirect-get)"
has "$OUT/p-ok.html" 'Your settings are saved.' "and says it saved"
eq "$(Q "SELECT CONCAT_WS('|', u.user_timezone, p.user_daily_email, p.user_keyword_searched_or_bidded, p.user_pref_dynamic_bid, p.user_pref_referer_data, p.user_pref_privacy, p.user_pref_cloak_referer, p.user_pref_ad_settings, p.user_tracking_domain) FROM 202_users u JOIN 202_users_pref p USING (user_id) WHERE u.user_id=$OWNER")" \
  "Europe/London|09|bidded|1|t202ref|eu|never|hide_login|track.example.test" "all nine preferences stored"
get "202-account/account.php" "$OUT/account2.html"
has "$OUT/account2.html" '<option value="Europe/London" selected>' "the page shows the stored time zone"
eq "$(grep -c 'value="Europe/London"' "$OUT/account2.html")" "1" "and lists each zone once (the classic page printed the selected one twice)"

# ─────────────────────────────────────────────────────────────────────
say "currency"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_account_currency "202-account/account.php" "$OUT/c-bad.html" token=forged account_currency=EUR
has "$OUT/c-bad.html" "$REFUSED" "a bad token is refused"
eq "$(Q "SELECT user_account_currency FROM 202_users_pref WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f10)" "currency unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_account_currency "202-account/account.php" "$OUT/c-xyz.html" account_currency=XYZ
msgs "$OUT/c-xyz.html"
has "$OUT/c-xyz.html" 'Choose a currency from the list.' "a currency the page does not offer is refused"
eq "$(Q "SELECT user_account_currency FROM 202_users_pref WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f10)" "currency unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_account_currency "202-account/account.php" "$OUT/c-eur.html" account_currency=EUR
has "$OUT/c-eur.html" 'Account currency saved.' "EUR saves"
eq "$(Q "SELECT user_account_currency FROM 202_users_pref WHERE user_id=$OWNER")" "EUR" "stored as EUR"
# The currency and its re-pricing are one transaction: a refused write is
# said, and nothing is stored (#165).
fail_writes rv3_fail_currency 202_users_pref UPDATE "NEW.user_account_currency <> OLD.user_account_currency"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" update_account_currency "202-account/account.php" "$OUT/c-fail.html" account_currency=GBP
clear_fail rv3_fail_currency
has "$OUT/c-fail.html" 'The account currency could not be saved, and no campaign was re-priced; try again.' "a currency write that fails says so"
hasnt "$OUT/c-fail.html" 'Account currency saved.' "and does not say saved"
eq "$(Q "SELECT user_account_currency FROM 202_users_pref WHERE user_id=$OWNER")" "EUR" "still EUR"

# ─────────────────────────────────────────────────────────────────────
say "API keys: create, refuse, revoke"
# (before the keys) The Tracking202 API key change reports only a write that
# landed (#165, #173): it flashed "updated" and set the session key over an
# unchanged row.
KEY_BEFORE=$(Q "SELECT IFNULL(user_api_key,'') FROM 202_users WHERE user_id=$OWNER")
fail_writes rv3_fail_key 202_users UPDATE "NOT (NEW.user_api_key <=> OLD.user_api_key)"
get "202-account/account.php" "$OUT/account.html"
if grep -q 'name="change_user_api_key"' "$OUT/account.html"; then
  submit "$OUT/account.html" change_user_api_key "202-account/account.php" "$OUT/k-fail.html" user_api_key=0123456789abcdef0123456789abcdef
  clear_fail rv3_fail_key
  if grep -qF 'This API Key appears invalid.' "$OUT/k-fail.html"; then
    skip "the key service refused the probe key, so the write was not reached"
  else
    has "$OUT/k-fail.html" 'The Tracking202 API key could not be saved; try again.' "a key write that fails says so"
    hasnt "$OUT/k-fail.html" 'You have updated your Tracking202 API Key.' "and does not say updated"
    eq "$(Q "SELECT IFNULL(user_api_key,'') FROM 202_users WHERE user_id=$OWNER")" "$KEY_BEFORE" "the stored key is unchanged"
    get "202-account/account.php" "$OUT/k-after.html"
    hasnt "$OUT/k-after.html" '0123456789abcdef0123456789abcdef' "and the session does not carry the key that was not saved"
  fi
else
  clear_fail rv3_fail_key
  skip "this install's account page offers no Tracking202 API key form"
fi
KEYS_BEFORE=$(Q "SELECT COUNT(*) FROM 202_api_keys WHERE user_id=$OWNER")
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" add_rest_api_key "202-account/account.php" "$OUT/k-bad.html" token=forged
has "$OUT/k-bad.html" "$REFUSED" "a bad token creates no key"
eq "$(Q "SELECT COUNT(*) FROM 202_api_keys WHERE user_id=$OWNER")" "$KEYS_BEFORE" "key count unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" add_rest_api_key "202-account/account.php" "$OUT/k-shape.html" "rest_api_key=short key!"
has "$OUT/k-shape.html" 'An API key is 32 to 128 letters and digits.' "a posted key of the wrong shape is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_api_keys WHERE user_id=$OWNER")" "$KEYS_BEFORE" "key count unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" add_rest_api_key "202-account/account.php" "$OUT/k-new.html"
msgs "$OUT/k-new.html"
eq "$FIRST_STATUS" "303" "a created key redirects"
has "$OUT/k-new.html" 'API key created.' "and says so"
eq "$(Q "SELECT COUNT(*) FROM 202_api_keys WHERE user_id=$OWNER")" "$((KEYS_BEFORE+1))" "one key more"
NEWKEY=$(Q "SELECT api_key FROM 202_api_keys WHERE user_id=$OWNER ORDER BY created_at DESC, api_key LIMIT 1")
if [[ "$NEWKEY" =~ ^[0-9a-f]{64}$ ]]; then ok "the server minted a 64-hex key"; else bad "new key shape: '$NEWKEY'"; fi
# Revealable, and masked on screen: the full key is only in the reveal/copy data.
has "$OUT/k-new.html" "data-p202-value=\"$NEWKEY\"" "the key is behind Reveal"
hasnt "$OUT/k-new.html" ">$NEWKEY</pre>" "and not printed in the clear"
# A key the API really accepts: the page wrote the same row the API reads.
API_CODE=$(curl -sS -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $NEWKEY" "$BASE/api/v3/campaigns")
eq "$API_CODE" "200" "the new key authenticates against the v3 API"

get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" "rest_api_key=$NEWKEY" "202-account/account.php" "$OUT/r-bad.html" token=forged
has "$OUT/r-bad.html" "$REFUSED" "a revoke with a bad token is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_api_keys WHERE api_key='$NEWKEY'")" "1" "the key survives it"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" "rest_api_key=$NEWKEY" "202-account/account.php" "$OUT/r-ok.html"
msgs "$OUT/r-ok.html"
has "$OUT/r-ok.html" 'API key revoked.' "revoking says so"
eq "$(Q "SELECT COUNT(*) FROM 202_api_keys WHERE api_key='$NEWKEY'")" "0" "the key is gone"
API_CODE=$(curl -sS -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $NEWKEY" "$BASE/api/v3/campaigns")
eq "$API_CODE" "401" "and the API refuses it"

# ─────────────────────────────────────────────────────────────────────
say "password: refusals first, then a change that works, then back"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" change_user_pass "202-account/account.php" "$OUT/pw-bad.html" \
  token=forged "user_pass=$P202_PASS" new_user_pass=NewPass-123 retype_new_user_pass=NewPass-123
has "$OUT/pw-bad.html" "$REFUSED" "a bad token changes no password"
eq "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$OWNER")" "$ORIG_HASH" "hash unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" change_user_pass "202-account/account.php" "$OUT/pw-wrong.html" \
  user_pass=definitely-not-it new_user_pass=NewPass-123 retype_new_user_pass=NewPass-123
has "$OUT/pw-wrong.html" 'Your old password was typed incorrectly.' "a wrong current password is refused under its field"
eq "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$OWNER")" "$ORIG_HASH" "hash unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" change_user_pass "202-account/account.php" "$OUT/pw-short.html" \
  "user_pass=$P202_PASS" new_user_pass=short retype_new_user_pass=other
msgs "$OUT/pw-short.html"
has "$OUT/pw-short.html" 'Your password must be between 8 and 72 characters long.' "too short is refused under the new password"
has "$OUT/pw-short.html" 'Your password did not match, please try again.' "a mismatch is refused under the retype"
hasnt "$OUT/pw-short.html" "value=\"$P202_PASS\"" "no password is echoed back into the page"
eq "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$OWNER")" "$ORIG_HASH" "hash unchanged"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" change_user_pass "202-account/account.php" "$OUT/pw-ok.html" \
  "user_pass=$P202_PASS" new_user_pass=NewPass-123 retype_new_user_pass=NewPass-123
has "$OUT/pw-ok.html" 'Your password is changed.' "a correct change says so"
if [ "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$OWNER")" != "$ORIG_HASH" ]; then ok "the stored hash changed"; else bad "the stored hash did not change"; fi
# The new password really signs in.
J2=$(mktemp)
curl -sS -c "$J2" -b "$J2" "$BASE/202-login.php" -o "$OUT/l2.html"
LT2=$(grep -oE 'name="token" value="[^"]+"' "$OUT/l2.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$J2" -b "$J2" -L "$BASE/202-login.php" --data-urlencode "token=$LT2" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=NewPass-123" -o /dev/null
curl -sS -b "$J2" -c "$J2" -L "$BASE/202-account/account.php" -o "$OUT/l2-acc.html"
has "$OUT/l2-acc.html" 'change_user_pass' "the new password signs in"
rm -f "$J2"
get "202-account/account.php" "$OUT/account.html"
submit "$OUT/account.html" change_user_pass "202-account/account.php" "$OUT/pw-back.html" \
  user_pass=NewPass-123 "new_user_pass=$P202_PASS" "retype_new_user_pass=$P202_PASS"
has "$OUT/pw-back.html" 'Your password is changed.' "and it changes back"

# ─────────────────────────────────────────────────────────────────────
say "users: add, refuse, edit and change the role, remove"
drop_probe_users() {
  local ids
  ids=$(Q "SELECT GROUP_CONCAT(user_id) FROM 202_users WHERE user_name IN ('u6_live_user','u6_live_other','u6_live_norole')")
  [ -z "$ids" ] || [ "$ids" = "NULL" ] && return
  mysql_q "$DB" -e "DELETE FROM 202_user_role WHERE user_id IN ($ids); DELETE FROM 202_users_pref WHERE user_id IN ($ids); DELETE FROM 202_users WHERE user_id IN ($ids)"
}
drop_probe_users
get "202-account/user-management.php" "$OUT/um.html"
has "$OUT/um.html" 'No other users yet' "an empty list offers the first step"
submit "$OUT/um.html" user_fname "202-account/user-management.php" "$OUT/um-bad.html" token=forged \
  user_fname=Live user_lname=User user_email=u6-live@example.test user_name=u6_live_user \
  user_password=LivePass-1 user_password2=LivePass-1 user_role=4
has "$OUT/um-bad.html" "$REFUSED" "a bad token adds nobody"
eq "$(Q "SELECT COUNT(*) FROM 202_users WHERE user_name='u6_live_user'")" "0" "no row"
get "202-account/user-management.php" "$OUT/um.html"
submit "$OUT/um.html" user_fname "202-account/user-management.php" "$OUT/um-miss.html" \
  user_fname=Live user_lname=User user_email= user_name=u6_live_user \
  user_password=short user_password2=other user_role=1
msgs "$OUT/um-miss.html"
has "$OUT/um-miss.html" 'Enter an email address.' "a missing email is named"
has "$OUT/um-miss.html" 'The password must be between 8 and 72 characters long.' "a short password is named"
has "$OUT/um-miss.html" 'Make sure the passwords you entered match.' "a mismatch is named"
has "$OUT/um-miss.html" 'Invalid user role.' "role 1 (Super user) is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_users WHERE user_name='u6_live_user'")" "0" "no row"
get "202-account/user-management.php" "$OUT/um.html"
submit "$OUT/um.html" user_fname "202-account/user-management.php" "$OUT/um-add.html" \
  user_fname=Live user_lname=User user_email=u6-live@example.test user_name=u6_live_user \
  user_password=LivePass-1 user_password2=LivePass-1 user_role=4
msgs "$OUT/um-add.html"
eq "$FIRST_STATUS" "303" "adding redirects"
has "$OUT/um-add.html" 'u6_live_user is added as Campaign optimizer.' "and names the user and role"
NEWUSER=$(Q "SELECT user_id FROM 202_users WHERE user_name='u6_live_user' AND user_deleted=0")
eq "$(Q "SELECT CONCAT_WS('|', user_fname, user_lname, user_email, user_active) FROM 202_users WHERE user_id=${NEWUSER:-0}")" "Live|User|u6-live@example.test|1" "the row carries every field"
eq "$(Q "SELECT role_id FROM 202_user_role WHERE user_id=${NEWUSER:-0}")" "4" "role 4 stored"
eq "$(Q "SELECT COUNT(*) FROM 202_users_pref WHERE user_id=${NEWUSER:-0}")" "1" "a preferences row was created"
HASH_NEW=$(Q "SELECT user_pass FROM 202_users WHERE user_id=${NEWUSER:-0}")
if [[ "$HASH_NEW" == \$2y\$* ]]; then ok "the password is stored as a bcrypt hash"; else bad "password hash shape: ${HASH_NEW:0:7}"; fi

get "202-account/user-management.php" "$OUT/um.html"
submit "$OUT/um.html" user_fname "202-account/user-management.php" "$OUT/um-dup.html" \
  user_fname=Other user_lname=Person user_email=u6-other@example.test user_name=u6_live_user \
  user_password=LivePass-1 user_password2=LivePass-1 user_role=5
has "$OUT/um-dup.html" 'The username you entered already exists.' "a taken username is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_users WHERE user_name='u6_live_user'")" "1" "still one"

# A rename onto another user's username is refused (#165: only create was
# tested, so a revert of the edit-time check stayed green).
get "202-account/user-management.php" "$OUT/um.html"
submit "$OUT/um.html" user_fname "202-account/user-management.php" "$OUT/um-other.html" \
  user_fname=Other user_lname=Person user_email=u6-other@example.test user_name=u6_live_other \
  user_password=LivePass-1 user_password2=LivePass-1 user_role=5
OTHER=$(Q "SELECT user_id FROM 202_users WHERE user_name='u6_live_other' AND user_deleted=0")
eq "$([ -n "$OTHER" ] && echo yes)" "yes" "a second user is added"
get "202-account/user-management.php?edit_user_id=$OTHER" "$OUT/um-edit-other.html"
submit "$OUT/um-edit-other.html" user_fname "202-account/user-management.php?edit_user_id=$OTHER" "$OUT/um-rename.html" user_name=u6_live_user
has "$OUT/um-rename.html" 'The username you entered already exists.' "renaming it onto the first user's name is refused"
eq "$(Q "SELECT user_name FROM 202_users WHERE user_id=$OTHER")" "u6_live_other" "its name is unchanged"

# A user, its role and its preferences land together (#165, #173): with the
# role write failing, nobody is added.
fail_writes rv3_fail_role 202_user_role INSERT "1 = 1"
get "202-account/user-management.php" "$OUT/um.html"
submit "$OUT/um.html" user_fname "202-account/user-management.php" "$OUT/um-norole.html" \
  user_fname=No user_lname=Role user_email=u6-norole@example.test user_name=u6_live_norole \
  user_password=LivePass-1 user_password2=LivePass-1 user_role=5
clear_fail rv3_fail_role
has "$OUT/um-norole.html" 'The user could not be created, and nothing was added. Try again.' "a create whose role write fails says so"
eq "$(Q "SELECT COUNT(*) FROM 202_users WHERE user_name='u6_live_norole'")" "0" "and leaves no user row behind"
# And an edit whose role write fails keeps the user as it was.
fail_writes rv3_fail_role 202_user_role INSERT "1 = 1"
get "202-account/user-management.php?edit_user_id=$OTHER" "$OUT/um-edit-other.html"
submit "$OUT/um-edit-other.html" user_fname "202-account/user-management.php?edit_user_id=$OTHER" "$OUT/um-edit-norole.html" user_lname=Changed user_role=4
clear_fail rv3_fail_role
has "$OUT/um-edit-norole.html" 'The changes could not be saved, and nothing about the user changed. Try again.' "an edit whose role write fails says so"
eq "$(Q "SELECT user_lname FROM 202_users WHERE user_id=$OTHER")" "Person" "the user row is as it was"
eq "$(Q "SELECT GROUP_CONCAT(role_id) FROM 202_user_role WHERE user_id=$OTHER")" "5" "and keeps its one role"

get "202-account/user-management.php?edit_user_id=$NEWUSER" "$OUT/um-edit.html"
has "$OUT/um-edit.html" 'value="u6-live@example.test"' "the edit form opens with the user in it"
# Keeping the email as it is used to be refused as a duplicate of itself.
submit "$OUT/um-edit.html" user_fname "202-account/user-management.php?edit_user_id=$NEWUSER" "$OUT/um-edited.html" \
  user_lname=Renamed user_role=3
msgs "$OUT/um-edited.html"
eq "$FIRST_STATUS" "303" "an edit that keeps the email saves"
eq "$(Q "SELECT user_lname FROM 202_users WHERE user_id=$NEWUSER")" "Renamed" "the rename is stored"
eq "$(Q "SELECT role_id FROM 202_user_role WHERE user_id=$NEWUSER")" "3" "the role changed to Campaign manager"
eq "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$NEWUSER")" "$HASH_NEW" "leaving the password alone keeps it"
get "202-account/user-management.php?edit_user_id=$NEWUSER" "$OUT/um-edit.html"
submit "$OUT/um-edit.html" user_fname "202-account/user-management.php?edit_user_id=$NEWUSER" "$OUT/um-pw.html" \
  user_password=Another-Pass-2 user_password2=Another-Pass-2
if [ "$(Q "SELECT user_pass FROM 202_users WHERE user_id=$NEWUSER")" != "$HASH_NEW" ]; then ok "setting a new password under Advanced changes it"; else bad "the edit's new password was not stored"; fi
get "202-account/user-management.php?edit_user_id=$NEWUSER" "$OUT/um-edit.html"
submit "$OUT/um-edit.html" user_fname "202-account/user-management.php?edit_user_id=$NEWUSER" "$OUT/um-inact.html" user_active=
eq "$(Q "SELECT user_active FROM 202_users WHERE user_id=$NEWUSER")" "0" "switching Active off is stored (an unchecked box posts nothing)"

# Removing: the retired GET address writes nothing.
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/202-account/user-management.php?delete_user_id=$NEWUSER" -o "$OUT/um-get.html"
has "$OUT/um-get.html" 'Removing a user now asks first.' "the old GET link is answered, not obeyed"
eq "$(Q "SELECT user_deleted FROM 202_users WHERE user_id=$NEWUSER")" "0" "the user is still there"
get "202-account/user-management.php" "$OUT/um.html"
has "$OUT/um.html" 'data-p202-confirm="Remove Live Renamed?' "remove asks first, naming the user"
submit "$OUT/um.html" "delete_user_id=$NEWUSER" "202-account/user-management.php" "$OUT/um-del-bad.html" token=forged
has "$OUT/um-del-bad.html" "$REFUSED" "a remove with a bad token is refused"
eq "$(Q "SELECT user_deleted FROM 202_users WHERE user_id=$NEWUSER")" "0" "the user is still there"
get "202-account/user-management.php" "$OUT/um.html"
submit "$OUT/um.html" "delete_user_id=$NEWUSER" "202-account/user-management.php" "$OUT/um-del.html"
msgs "$OUT/um-del.html"
has "$OUT/um-del.html" 'u6_live_user is removed and can no longer sign in.' "removing says so"
eq "$(Q "SELECT user_deleted FROM 202_users WHERE user_id=$NEWUSER")" "1" "user_deleted is set"
# The super user cannot be removed by id, even though no form offers it.
get "202-account/user-management.php" "$OUT/um.html"
TOK=$(python3 "$HERE/form-body.py" "$OUT/um.html" user_fname | tr '&' '\n' | grep '^token=' | cut -d= -f2-)
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/202-account/user-management.php" --data "token=$TOK&delete_user_id=1" -o "$OUT/um-del1.html"
has "$OUT/um-del1.html" 'That user cannot be removed here.' "user 1 is refused by id"
eq "$(Q "SELECT user_deleted FROM 202_users WHERE user_id=1")" "0" "user 1 untouched"

# ─────────────────────────────────────────────────────────────────────
say "API integrations: a key saves, an empty one is refused, a bad token writes nothing"
get "202-account/api-integrations.php" "$OUT/ai.html"
submit "$OUT/ai.html" change_ipqs_api_key "202-account/api-integrations.php" "$OUT/ai-bad.html" token=forged ipqs_api_key=u6-forged
has "$OUT/ai-bad.html" "$REFUSED" "a bad token is refused"
eq "$(Q "SELECT IFNULL(ipqs_api_key,'') FROM 202_users_pref WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f11)" "IPQS key unchanged"
get "202-account/api-integrations.php" "$OUT/ai.html"
submit "$OUT/ai.html" change_ipqs_api_key "202-account/api-integrations.php" "$OUT/ai-empty.html" ipqs_api_key=
msgs "$OUT/ai-empty.html"
has "$OUT/ai-empty.html" "The IPQualityScore API Key can&#039;t be empty!" "an empty key is refused under the field"
get "202-account/api-integrations.php" "$OUT/ai.html"
submit "$OUT/ai.html" change_ipqs_api_key "202-account/api-integrations.php" "$OUT/ai-ok.html" ipqs_api_key=u6-ipqs-key
has "$OUT/ai-ok.html" 'Your IPQualityScore API key was saved.' "a key saves"
eq "$(Q "SELECT ipqs_api_key FROM 202_users_pref WHERE user_id=$OWNER")" "u6-ipqs-key" "stored"

say "API integrations: a DNI network is removed by POST only"
mysql_q "$DB" -e "INSERT INTO 202_dni_networks (user_id, networkId, name, type, apiKey, affiliateId, time, processed, shortDescription, favIcon) VALUES (1, 'u6net', 'U6 Live Network', 'HasOffers', 'u6-dni-secret-key-0001', NULL, UNIX_TIMESTAMP(), 1, 'probe', '')"
DNI=$(Q "SELECT id FROM 202_dni_networks WHERE networkId='u6net'")
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/202-account/api-integrations.php?delete_dni_network=$DNI" -o "$OUT/dni-get.html"
has "$OUT/dni-get.html" 'Removing a network now asks first.' "the old GET link is answered, not obeyed"
eq "$(Q "SELECT COUNT(*) FROM 202_dni_networks WHERE id=$DNI")" "1" "the network is still there"
has "$OUT/dni-get.html" 'data-p202-value="u6-dni-secret-key-0001"' "its API key is behind Reveal"
hasnt "$OUT/dni-get.html" '>u6-dni-secret-key-0001<' "and not printed in the clear"
submit "$OUT/dni-get.html" "delete_dni_network=$DNI" "202-account/api-integrations.php" "$OUT/dni-bad.html" token=forged
has "$OUT/dni-bad.html" "$REFUSED" "a bad token removes nothing"
eq "$(Q "SELECT COUNT(*) FROM 202_dni_networks WHERE id=$DNI")" "1" "still there"
get "202-account/api-integrations.php" "$OUT/ai.html"
submit "$OUT/ai.html" "delete_dni_network=$DNI" "202-account/api-integrations.php" "$OUT/dni-del.html"
has "$OUT/dni-del.html" 'The network is removed.' "removing says so"
eq "$(Q "SELECT COUNT(*) FROM 202_dni_networks WHERE id=$DNI")" "0" "the network is gone"

say "the DNI progress endpoint's write now asks for the token"
mysql_q "$DB" -e "INSERT INTO 202_dni_networks (user_id, networkId, name, type, apiKey, affiliateId, time, processed, shortDescription, favIcon) VALUES ($OWNER, 'u6net2', 'U6 Progress', 'HasOffers', 'k', NULL, UNIX_TIMESTAMP(), 0, '', '')"
DNI2=$(Q "SELECT id FROM 202_dni_networks WHERE networkId='u6net2'")
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$OUT/dni-up.html" -w '%{http_code}' -X POST "$BASE/202-account/ajax/dni.php?updateStatus=true&dni=$DNI2")
eq "$CODE" "403" "no token: 403"
has "$OUT/dni-up.html" 'Invalid token.' "with the guard's sentence"
eq "$(Q "SELECT processed FROM 202_dni_networks WHERE id=$DNI2")" "0" "processed unchanged"
get "202-account/api-integrations.php" "$OUT/ai.html"
TOK=$(python3 "$HERE/form-body.py" "$OUT/ai.html" change_ipqs_api_key | tr '&' '\n' | grep '^token=' | cut -d= -f2-)
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data "token=$TOK" "$BASE/202-account/ajax/dni.php?updateStatus=true&dni=$DNI2")
eq "$CODE" "200" "with the page's token: 200"
eq "$(Q "SELECT processed FROM 202_dni_networks WHERE id=$DNI2")" "1" "processed set"
mysql_q "$DB" -e "DELETE FROM 202_dni_networks WHERE networkId IN ('u6net','u6net2')"

say "the survey endpoint asks for the token too"
MODAL_BEFORE=$(Q "SELECT modal_status FROM 202_users WHERE user_id=$OWNER")
mysql_q "$DB" -e "UPDATE 202_users SET modal_status=0 WHERE user_id=$OWNER"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$OUT/sv.html" -w '%{http_code}' --data "skip=1" "$BASE/202-account/ajax/survey.php")
eq "$CODE" "403" "a skip without the token: 403"
eq "$(Q "SELECT modal_status FROM 202_users WHERE user_id=$OWNER")" "0" "modal status unchanged"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data "skip=1&token=$TOK" "$BASE/202-account/ajax/survey.php")
eq "$CODE" "200" "with the token: 200"
eq "$(Q "SELECT modal_status FROM 202_users WHERE user_id=$OWNER")" "1" "modal status set"
mysql_q "$DB" -e "UPDATE 202_users SET modal_status='$MODAL_BEFORE' WHERE user_id=$OWNER"

# ─────────────────────────────────────────────────────────────────────
say "settings: click-data retention"
get "202-account/administration.php" "$OUT/ad.html"
submit "$OUT/ad.html" auto_database_management "202-account/administration.php" "$OUT/ad-bad.html" token=forged auto_database_management=30
has "$OUT/ad-bad.html" "$REFUSED" "a bad token is refused"
eq "$(Q "SELECT user_auto_database_optimization_days FROM 202_users_pref WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f12)" "retention unchanged"
get "202-account/administration.php" "$OUT/ad.html"
submit "$OUT/ad.html" auto_database_management "202-account/administration.php" "$OUT/ad-neg.html" auto_database_management=-5
msgs "$OUT/ad-neg.html"
has "$OUT/ad-neg.html" 'Enter a whole number of days, or 0 to keep all click data.' "a negative number is refused"
get "202-account/administration.php" "$OUT/ad.html"
submit "$OUT/ad.html" auto_database_management "202-account/administration.php" "$OUT/ad-ok.html" auto_database_management=30
has "$OUT/ad-ok.html" 'Click data older than 30 days is deleted automatically from now on.' "30 days saves"
eq "$(Q "SELECT user_auto_database_optimization_days FROM 202_users_pref WHERE user_id=$OWNER")" "30" "stored"

say "settings: deleting click data before a date asks for the date"
get "202-account/administration.php" "$OUT/ad.html"
has "$OUT/ad.html" 'data-p202-confirm="Are you sure you want to delete all your click data' "the delete form still confirms"
submit "$OUT/ad.html" database_management "202-account/administration.php" "$OUT/ad-blank.html" database_management=
msgs "$OUT/ad-blank.html"
has "$OUT/ad-blank.html" 'Pick the date: click data from before it is deleted.' "a blank date is refused (it used to mean today)"
eq "$(Q "SELECT IFNULL(user_delete_data_clickid,'NULL') FROM 202_users_pref WHERE user_id=$OWNER")" "$(echo "$ORIG_PREFS" | cut -d'|' -f13)" "nothing scheduled"

say "settings: the page asks for access_to_settings, as the menu does"
ROLE=$(Q "SELECT role_id FROM 202_user_role WHERE user_id=$OWNER")
PERM=$(Q "SELECT permission_id FROM 202_permissions WHERE permission_description='access_to_settings'")
mysql_q "$DB" -e "DELETE FROM 202_role_permission WHERE role_id=$ROLE AND permission_id=$PERM"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/202-account/administration.php")
mysql_q "$DB" -e "INSERT IGNORE INTO 202_role_permission (role_id, permission_id) VALUES ($ROLE, $PERM)"
case "$CODE" in
  "302 "*202-account/) ok "without it the page redirects home ($CODE)" ;;
  *) bad "without access_to_settings: $CODE" ;;
esac
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$OUT/ad-back.html" -w '%{http_code}' "$BASE/202-account/administration.php")
eq "$CODE" "200" "with it back, the page answers"

say "the 1-click upgrade pages ask for access_to_settings too"
mysql_q "$DB" -e "DELETE FROM 202_role_permission WHERE role_id=$ROLE AND permission_id=$PERM"
for page in auto-upgrade.php auto-upgrade-premium.php; do
  CODE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/202-account/$page")
  case "$CODE" in
    "302 "*202-account/) ok "$page without it redirects home ($CODE)" ;;
    *) bad "$page without access_to_settings: $CODE" ;;
  esac
done
mysql_q "$DB" -e "INSERT IGNORE INTO 202_role_permission (role_id, permission_id) VALUES ($ROLE, $PERM)"

say "the ClickServer switch asks for the permission, and uses this account's own key"
get "202-account/api-integrations.php" "$OUT/ai.html"
TOK=$(python3 "$HERE/form-body.py" "$OUT/ai.html" change_ipqs_api_key | tr '&' '\n' | grep '^token=' | cut -d= -f2-)
CS="$BASE/202-config/clickserver_api_management.php"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$OUT/cs-tok.txt" -w '%{http_code}' --data "clickserver_id=victim.example&method=deactivate&token=forged" "$CS")
eq "$CODE" "403" "a forged token: 403"
CSPERM=$(Q "SELECT permission_id FROM 202_permissions WHERE permission_description='access_to_clickservers'")
mysql_q "$DB" -e "DELETE FROM 202_role_permission WHERE role_id=$ROLE AND permission_id=$CSPERM"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$OUT/cs-perm.txt" -w '%{http_code}' --data "clickserver_id=victim.example&method=deactivate&token=$TOK" "$CS")
mysql_q "$DB" -e "INSERT IGNORE INTO 202_role_permission (role_id, permission_id) VALUES ($ROLE, $CSPERM)"
eq "$CODE" "403" "without access_to_clickservers: 403"
has "$OUT/cs-perm.txt" 'You do not have access to ClickServers.' "with the refusal's sentence"
CSKEY=$(Q "SELECT IFNULL(clickserver_api_key,'') FROM 202_users WHERE user_id=$OWNER")
mysql_q "$DB" -e "UPDATE 202_users SET clickserver_api_key='' WHERE user_id=$OWNER"
CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$OUT/cs-key.txt" -w '%{http_code}' --data "clickserver_id=victim.example&method=deactivate&token=$TOK&api_key=$(printf 'someone-elses-key' | base64)" "$CS")
mysql_q "$DB" -e "UPDATE 202_users SET clickserver_api_key='$CSKEY' WHERE user_id=$OWNER"
eq "$CODE" "409" "with no key of its own, a posted key is not used: 409"
has "$OUT/cs-key.txt" 'There is no ClickServer API key on file for this account.' "and it says so"

# ─────────────────────────────────────────────────────────────────────
say "VIP Perks: a bad token is refused before anything leaves this install"
get "202-account/vip-perks.php" "$OUT/vp.html"
if grep -q 'id="survey-form"' "$OUT/vp.html"; then
  submit "$OUT/vp.html" token "202-account/vip-perks.php" "$OUT/vp-bad.html" token=forged
  has "$OUT/vp-bad.html" "$REFUSED" "a bad token is refused"
  # All radios cleared: the page refuses by count before calling the service.
  python3 "$HERE/form-body.py" "$OUT/vp.html" token > "$OUT/.vpbody"
  VP_TOK=$(tr '&' '\n' < "$OUT/.vpbody" | grep '^token=' | cut -d= -f2-)
  curl -sS -b "$JAR" -c "$JAR" -o "$OUT/vp-none.html" --data "token=$VP_TOK" "$BASE/202-account/vip-perks.php"
  msgs "$OUT/vp-none.html"
  has "$OUT/vp-none.html" 'questions are not answered yet.' "unanswered questions are refused by count"
  has "$OUT/vp-none.html" 'Answer Yes or No.' "and each one says so under it"
else
  has "$OUT/vp.html" 'The questions could not be loaded' "the VIP Perks service is unreachable, and the page says so"
fi

# ─────────────────────────────────────────────────────────────────────
say "put the account back"
# The 13th field, user_delete_data_clickid, is only asserted unchanged above
# (the pass never schedules a deletion), so there is nothing to put back.
IFS='|' read -r TZ DE KW BID REF PRIV CLOAK ADS DOM CUR IPQS DAYS _ <<< "$ORIG_PREFS"
mysql_q "$DB" -e "UPDATE 202_users SET user_timezone='$TZ' WHERE user_id=$OWNER; UPDATE 202_users_pref SET user_daily_email='$DE', user_keyword_searched_or_bidded='$KW', user_pref_dynamic_bid='$BID', user_pref_referer_data='$REF', user_pref_privacy='$PRIV', user_pref_cloak_referer='$CLOAK', user_pref_ad_settings='$ADS', user_tracking_domain='$DOM', user_account_currency='$CUR', ipqs_api_key='$IPQS', user_auto_database_optimization_days='$DAYS' WHERE user_id=$OWNER"
drop_probe_users
J3=$(mktemp)
curl -sS -c "$J3" -b "$J3" "$BASE/202-login.php" -o "$OUT/l3.html"
LT3=$(grep -oE 'name="token" value="[^"]+"' "$OUT/l3.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$J3" -b "$J3" -L "$BASE/202-login.php" --data-urlencode "token=$LT3" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o /dev/null
curl -sS -b "$J3" -c "$J3" -L "$BASE/202-account/account.php" -o "$OUT/l3-acc.html"
has "$OUT/l3-acc.html" 'change_user_pass' "the original password signs in again"
rm -f "$J3"

if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then
  say "the server logged no PHP warnings from the Account pages"
  NOISE=$(tail -n +"$((LOG_START+1))" "$SERVER_LOG" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' | grep -c '/202-account/')
  eq "$NOISE" "0" "no PHP warning, notice or fatal under 202-account/ in $SERVER_LOG since the pass began"
fi

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" = 0 ]
