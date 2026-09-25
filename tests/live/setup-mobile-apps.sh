#!/bin/bash
# Live pass for Setup > Mobile Apps. Drives the real page over HTTP with the
# --- environment -------------------------------------------------------
# Everything the pass needs to reach an instance, overridable so this runs
# somewhere other than the machine it was written on. The names match
# tests/browser (see its README): one instance can serve both passes.
#
# P202_DB must be a SCRATCH database. This pass TRUNCATEs the app
# tables and rewrites the account currency; the guard below refuses a name
# that does not read as disposable, which is the same protection
# tests/browser/lib/db.js applies.
# This pass registers and removes apps through the pages themselves, so it
# needs both an instance to log into and the database to check the writes
# landed. It spawns nothing, so these stay plain locals.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass logs in as $P202_USER and needs its password." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
# -----------------------------------------------------------------------

# field names the rendered forms actually carry.
JAR=$(mktemp)
OUT=$(mktemp -d)
PASS=0; FAIL=0
Q() { mysql_q -N "$DB" -e "$1"; }

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
msgs() { grep -oE '<div class="p202-flash__body">[^<]*|<div class="invalid-feedback[^"]*">[^<]*' "$1" \
           | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }

mysql_q "$DB" -e "TRUNCATE 202_app_registrations; TRUNCATE 202_app_skan_encodings; TRUNCATE 202_app_skan_encoding_history; TRUNCATE 202_app_postbacks; TRUNCATE 202_goals; TRUNCATE 202_goal_versions;"

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/" -o "$OUT/home.html"
# The login page is the only page with a password field; a page without one
# is proof the session took. A bare URL-string check passed against the
# login page itself.
hasnt "$OUT/home.html" 'name="user_pass"' "session established"
# ...and a login page that FAILED to render has no password field either (a
# fatal before the form passed the line above), so ask for what only a
# signed-in page carries.
has "$OUT/home.html" '202-account/signout.php' "the signed-in chrome offers a sign-out"

get()  { curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/mobile_apps.php$1" -o "$2"; }
# POST and follow the PRG redirect; -w reports the FIRST status so a 200 where
# a 302 belongs (the error re-render) is visible rather than hidden by -L.
post() {
  local out="$2"; shift 2
  LAST_REDIRECTS=0
  local tok
  tok=$(grep -oE 'name="csrf_token" value="[^"]+"' "$OUT/page.html" | head -1 | sed 's/.*value="//; s/"//')
  curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/mobile_apps.php" \
    --data-urlencode "csrf_token=$tok" "$@" -o "$out" \
    -w "%{num_redirects} %{http_code}" > "$OUT/.w"
  LAST_REDIRECTS=$(cut -d' ' -f1 "$OUT/.w")
  printf '    redirects=%s final=%s\n' "$(cut -d' ' -f1 "$OUT/.w")" "$(cut -d' ' -f2 "$OUT/.w")"
}

say "page renders in the v2 shell"
get "" "$OUT/page.html"
has "$OUT/page.html" "p202-shell-v2"  "body carries p202-shell-v2"
has "$OUT/page.html" "Mobile Apps"    "page title present"
has "$OUT/page.html" "csrf_token"     "CSRF field rendered"
hasnt "$OUT/page.html" "Fatal error"  "no PHP fatal"
hasnt "$OUT/page.html" "Warning:"     "no PHP warning"
hasnt "$OUT/page.html" "Notice:"      "no PHP notice"
hasnt "$OUT/page.html" "Deprecated:"  "no PHP deprecation"
has "$OUT/page.html" "setup/mobile_apps.php" "sub-menu links the page"
has "$OUT/page.html" ".well-known/skadnetwork/report-attribution"  "SKAdNetwork receiver URL shown"
has "$OUT/page.html" ".well-known/appattribution/report-attribution" "AdAttributionKit receiver URL shown"

say "register: a Play Store link is read as the Android app it names"
# No store answers in this pass (tests/live/mobile-apps-ui.sh runs a fake
# one), so the name cannot be looked up: the page asks for it once.
post x "$OUT/android.html" --data-urlencode action=register \
  --data-urlencode "app_reference=https://play.google.com/store/apps/details?id=com.example.app"
msgs "$OUT/android.html"
has "$OUT/android.html" "Google Play did not answer, so the name could not be looked up" "the link was read as a Google Play app, and the name is asked for"
eq "$(Q 'SELECT COUNT(*) FROM 202_app_registrations')" "0" "no app was created without one"
post x "$OUT/android2.html" --data-urlencode action=register \
  --data-urlencode "app_reference=https://play.google.com/store/apps/details?id=com.example.app" --data-urlencode "app_name=Example Android"
eq "$LAST_REDIRECTS" 1 "with the name typed, it registers (a redirect, not the re-render)"
eq "$(Q "SELECT CONCAT(platform, '|', app_key, '|', app_name) FROM 202_app_registrations")" "android|com.example.app|Example Android" "as the Android app the link names"
mysql_q "$DB" -e "DELETE FROM 202_app_registrations WHERE platform='android'"

say "register: junk gets the App Store sentence"
get "" "$OUT/page.html"
post x "$OUT/junk.html" --data-urlencode action=register --data-urlencode "app_reference=not-a-link"
msgs "$OUT/junk.html"
has "$OUT/junk.html" "Must be an App Store link" "junk refusal sentence"
eq "$(Q 'SELECT COUNT(*) FROM 202_app_registrations')" "0" "still no app"

say "register: empty asks for the link rather than failing oddly"
get "" "$OUT/page.html"
post x "$OUT/empty.html" --data-urlencode action=register --data-urlencode "app_reference="
msgs "$OUT/empty.html"
has "$OUT/empty.html" "Paste the app" "empty-field sentence"

# A PRG write must redirect; a 200 with no redirect is the error re-render,
# which the flash text alone does not distinguish from a success page.
redirected() { if [ "$LAST_REDIRECTS" = "1" ]; then ok "$2"; else bad "$2 (redirects=$LAST_REDIRECTS)"; fi; }

say "register: an App Store link works"
get "" "$OUT/page.html"
post x "$OUT/reg.html" --data-urlencode action=register \
  --data-urlencode "app_reference=https://apps.apple.com/us/app/summit-run/id990077001"
msgs "$OUT/reg.html"
eq "$(Q 'SELECT COUNT(*) FROM 202_app_registrations')" "1" "exactly one app row"
eq "$(Q 'SELECT app_key FROM 202_app_registrations')"   "990077001" "app_key derived from the link"
eq "$(Q 'SELECT platform FROM 202_app_registrations')" "ios" "platform derived as ios"
eq "$(Q "SELECT app_token REGEXP '^[0-9a-f]{64}\$' FROM 202_app_registrations")" "1" "app token is 64 hex characters"
printf '    name=%s\n' "$(Q 'SELECT app_name FROM 202_app_registrations')"
ROWID=$(Q 'SELECT registration_id FROM 202_app_registrations')

say "app detail"
get "?app=$ROWID" "$OUT/page.html"
hasnt "$OUT/page.html" "Warning:" "no PHP warning on detail"
hasnt "$OUT/page.html" "Fatal error" "no PHP fatal on detail"
has "$OUT/page.html" "$BASE" "origin resolved absolutely in the snippets"
has "$OUT/page.html" 'id="app-token"' "the app token is shown under its own name"
has "$OUT/page.html" "990077001" "app id shown on detail"
has "$OUT/page.html" "iOS" "platform label reads iOS"
hasnt "$OUT/page.html" ">IOS<" "platform label never upper-cased"

say "starter schema"
post x "$OUT/schema.html" --data-urlencode action=starter_schema \
  --data-urlencode "registration_id=$ROWID"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE registration_id=$ROWID")" "6" "starter schema added 6 rules"
eq "$(Q "SELECT GROUP_CONCAT(name ORDER BY name) FROM 202_goals WHERE scope='registration' AND scope_id=$ROWID")" "install,purchase,trial_started" \
   "naming three plain goals of the app, one per event, each shared by its fine and coarse rule"
say "starter schema is idempotent (never overwrites a decision)"
get "?app=$ROWID" "$OUT/page.html"
post x "$OUT/schema2.html" --data-urlencode action=starter_schema \
  --data-urlencode "registration_id=$ROWID"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE registration_id=$ROWID")" "6" "still 6 rules after a second click"

say "test signals toggle"
get "?app=$ROWID" "$OUT/page.html"
post x "$OUT/dev.html" --data-urlencode action=accept_dev \
  --data-urlencode "registration_id=$ROWID" --data-urlencode "accept=1"
eq "$(Q 'SELECT accept_test_signals FROM 202_app_registrations')" "1" "accept_test_signals set"

say "token rotation changes the token"
BEFORE=$(Q 'SELECT app_token FROM 202_app_registrations')
get "?app=$ROWID" "$OUT/page.html"
post x "$OUT/rot.html" --data-urlencode action=rotate_token --data-urlencode "registration_id=$ROWID"
AFTER=$(Q 'SELECT app_token FROM 202_app_registrations')
if [ "$BEFORE" != "$AFTER" ] && [ ${#AFTER} = 64 ]; then ok "token rotated to a new 64-char value"; else bad "token rotation: before=$BEFORE after=$AFTER"; fi

say "a rule can be added by hand"
get "?app=$ROWID" "$OUT/page.html"
post x "$OUT/rule.html" --data-urlencode action=rule_save \
  --data-urlencode "registration_id=$ROWID" \
  --data-urlencode "kind=fine" --data-urlencode "fine_value=7" \
  --data-urlencode "event_name=subscribed" --data-urlencode "revenue=9.99"
eq "$(Q "SELECT g.name FROM 202_app_skan_encodings e JOIN 202_goals g ON g.goal_id = e.goal_id WHERE e.registration_id=$ROWID AND e.fine_value=7")" "subscribed" \
   "rule stored, naming the app's plain goal for the event"
eq "$(Q "SELECT revenue_override FROM 202_app_skan_encodings WHERE registration_id=$ROWID AND fine_value=7")" "9.99000" "revenue stored as the rule's override"

say "a refused rule save says why, on the page it was submitted from"
# handleGet() reads the app id from the query string and a POST has none, so
# this used to re-render the apps LIST: no rules form, no field error, and a
# rejected value that the page never mentioned.
get "?app=$ROWID" "$OUT/page.html"
post x "$OUT/badrule.html" --data-urlencode action=rule_save \
  --data-urlencode "registration_id=$ROWID" \
  --data-urlencode "kind=fine" --data-urlencode "fine_value=33" \
  --data-urlencode "event_name=refused_probe" --data-urlencode "revenue=-5"
msgs "$OUT/badrule.html"
has "$OUT/badrule.html" "Conversion values" "lands back on the app, not the apps list"
has "$OUT/badrule.html" "invalid-feedback" "the API's sentence is shown under the field"
has "$OUT/badrule.html" 'value="refused_probe"' "what was typed is still in the form"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE fine_value=33")" "0" "and no rule was created"
eq "$(Q "SELECT COUNT(*) FROM 202_goals WHERE name='refused_probe'")" "0" "nor a goal for it"

say "revenue renders as money in the account's currency"
get "?app=$ROWID" "$OUT/page.html"
has "$OUT/page.html" '<td class="num">$9.99</td>' "the rule's revenue carries the currency symbol"
has "$OUT/page.html" '<span class="input-group-text">$</span>' "the revenue field names its currency"
has "$OUT/page.html" 'name="revenue" value="0.00"' "but the field's own value stays a bare number"
# The symbol is the account's, not a hardcoded dollar: a euro account must
# render euros, and a koruna account puts the symbol after the amount.
mysql_q "$DB" -e "UPDATE 202_users_pref SET user_account_currency='EUR' WHERE user_id=1"
get "?app=$ROWID" "$OUT/eur.html"
has "$OUT/eur.html" '<td class="num">€9.99</td>' "a EUR account renders euros"
mysql_q "$DB" -e "UPDATE 202_users_pref SET user_account_currency='CZK' WHERE user_id=1"
get "?app=$ROWID" "$OUT/czk.html"
has "$OUT/czk.html" '<td class="num">9.99Kč</td>' "a CZK account puts the symbol after the amount"
mysql_q "$DB" -e "UPDATE 202_users_pref SET user_account_currency='USD' WHERE user_id=1"

say "duplicate registration is refused, not duplicated"
get "" "$OUT/page.html"
post x "$OUT/dup.html" --data-urlencode action=register --data-urlencode "app_reference=id990077001"
msgs "$OUT/dup.html"
has "$OUT/dup.html" "already registered this app" "re-paste lands on the app you have"
redirected "$OUT/dup.html" "re-paste redirects rather than re-rendering the form"
eq "$(Q 'SELECT COUNT(*) FROM 202_app_registrations')" "1" "still exactly one row"

say "an app registered by SOMEBODY ELSE is refused, never revealed"
# The registration is unique across all users (the receiver resolves ownership
# by app id alone). The re-paste fast path above is user-scoped, so this must
# fall through to the API's conflict sentence and must not name the other app.
mysql_q "$DB" -e "INSERT INTO 202_app_registrations (user_id, platform, app_key, app_name, accept_test_signals, app_token, created_at, updated_at) VALUES (2, 'ios', '555000111', 'Somebody Elses App', 0, REPEAT('e5', 32), 1, 1)"
get "" "$OUT/page.html"
# A full store link so the name resolves from the slug without the network,
# which is what production does via the store lookup. With a bare id and no
# reachable store there is no name, and the page asks for one first.
post x "$OUT/other.html" --data-urlencode action=register \
  --data-urlencode "app_reference=https://apps.apple.com/us/app/their-app/id555000111"
msgs "$OUT/other.html"
has "$OUT/other.html" "already registered" "the API's conflict sentence is shown"
hasnt "$OUT/other.html" "Somebody Elses App" "the other user's app name is not leaked"
hasnt "$OUT/other.html" "already registered this app. Here it is" "no redirect into another user's app"
eq "$(Q "SELECT COUNT(*) FROM 202_app_registrations WHERE app_key='555000111'")" "1" "no second row for their app"
eq "$(Q "SELECT user_id FROM 202_app_registrations WHERE app_key='555000111'")" "2" "their app still theirs"
mysql_q "$DB" -e "DELETE FROM 202_app_registrations WHERE user_id=2"

say "the development nudge names the app that is WAITING, not the busiest one"
# The nudge asks one grouped question for every waiting app at once. Groups
# come back busiest first, so when the limit was the number of waiting apps
# and the query ranked EVERY app, one already-trusted app with more
# development postbacks took the only slot and the waiting app's nudge - the
# actionable one - was dropped. Two apps, the trusted one busier, is the
# smallest arrangement that shows it.
# The owner is read from the database rather than hardcoded, so the pass
# still works against an instance whose login is not user 1. Named OWNER
# because bash's own user-id variable is readonly and cannot be assigned.
OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
mysql_q "$DB" -e "DELETE FROM 202_app_registrations WHERE platform='ios' AND app_key IN ('910000001', '910000002')"
mysql_q "$DB" -e "INSERT INTO 202_app_registrations (user_id, platform, app_key, app_name, accept_test_signals, app_token, created_at, updated_at) VALUES ($OWNER, 'ios', '910000001', 'Nudge Waiting App', 0, REPEAT('f1', 32), 1, 1), ($OWNER, 'ios', '910000002', 'Nudge Trusted App', 1, REPEAT('f2', 32), 1, 1)"
reg_of() { Q "SELECT registration_id FROM 202_app_registrations WHERE platform='ios' AND app_key='$1'"; }
WAITING=$(reg_of 910000001); TRUSTED=$(reg_of 910000002)
mysql_q "$DB" -e "DELETE FROM 202_app_postbacks WHERE transaction_id LIKE 'nudge-%'"
for tx in w1 w2; do
  mysql_q "$DB" -e "INSERT INTO 202_app_postbacks (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id, signature_state, trusted, attribution_signature, raw_payload, remote_ip, dedupe_hash, created_at) VALUES ($OWNER, $WAITING, UNIX_TIMESTAMP(), 'skadnetwork', '4.0', 'nudge.skadnetwork', 'nudge-$tx', 910000001, 'development', NULL, 'sig', '{}', '127.0.0.1', SHA1('nudge-$tx'), UNIX_TIMESTAMP())"
done
# Strictly more, so it outranks the waiting app in a busiest-first ordering.
for tx in t1 t2 t3 t4 t5; do
  mysql_q "$DB" -e "INSERT INTO 202_app_postbacks (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id, signature_state, trusted, attribution_signature, raw_payload, remote_ip, dedupe_hash, created_at) VALUES ($OWNER, $TRUSTED, UNIX_TIMESTAMP(), 'skadnetwork', '4.0', 'nudge.skadnetwork', 'nudge-$tx', 910000002, 'development', 1, 'sig', '{}', '127.0.0.1', SHA1('nudge-$tx'), UNIX_TIMESTAMP())"
done
get "" "$OUT/nudge.html"
eq "$(grep -c 'value="accept_dev"' "$OUT/nudge.html")" "1" "exactly one nudge, for the one waiting app"
has "$OUT/nudge.html" "2 development postbacks have arrived" "it carries the waiting app's own count"
hasnt "$OUT/nudge.html" "5 development postbacks have arrived" "not the trusted app's, which needs no nudge"
hasnt "$OUT/nudge.html" "Fatal error" "no PHP fatal"
mysql_q "$DB" -e "DELETE FROM 202_app_postbacks WHERE transaction_id LIKE 'nudge-%'"
mysql_q "$DB" -e "DELETE FROM 202_app_registrations WHERE platform='ios' AND app_key IN ('910000001', '910000002')"

say "removal"
get "?app=$ROWID" "$OUT/page.html"
post x "$OUT/rm.html" --data-urlencode action=remove --data-urlencode "registration_id=$ROWID"
eq "$(Q 'SELECT COUNT(*) FROM 202_app_registrations')" "0" "app removed"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE registration_id=$ROWID")" "0" "its SKAN encodings went with it"

say "CSRF is enforced"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/setup/mobile_apps.php" \
  --data-urlencode "csrf_token=wrong" --data-urlencode action=register \
  --data-urlencode "app_reference=id123456789" -o "$OUT/csrf.html"
eq "$(Q 'SELECT COUNT(*) FROM 202_app_registrations')" "0" "a bad CSRF token writes nothing"

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" = 0 ]
