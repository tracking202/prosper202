#!/bin/bash
# Live pass for the app core (PR 3): the registry both platforms share, the
# app token and the public schema it selects, the SKAN encodings, the scope
# area, the registration and user-deletion cascades, and retention — driven
# over HTTP against a running instance with the database read back after
# every step:
#
#   - a postback that arrives before its app is registered is stored
#     unclaimed, and registering the app (from an App Store link) claims it;
#     a second registration of the same app is a 409;
#   - a Google Play link registers an Android app; package names are
#     case-sensitive, so two that differ only in case are two apps;
#     `store_link` with `app_key`, and a platform that contradicts the link,
#     are refused by field; the app's identity cannot be changed on update;
#   - GET /apps/schema is selected by the X-P202-App-Token header only: the
#     iOS document carries the goals and encodings and never the revenue, the Android
#     one carries its identity; 304 on a matching ETag, 400 for a malformed or
#     missing header (a token in the query string is not read), 404 for a
#     well-formed token nobody holds, 405 for anything but GET/HEAD;
#   - an encoding names one of the caller's own iOS registrations, or 0, and
#     a goal (PR 4; PR 8: the device evaluates the goal, see ios-sdk.sh);
#   - `apps` is its own scope area: an `apps:read` key reads and cannot
#     write, an `attribution:write` key cannot reach /apps at all, and the old
#     /attribution app routes answer 404;
#   - rotating the token stops the old one at once;
#   - DELETE /users/{id} purges the user's registrations and encodings and
#     releases their postbacks, after which another user's registration of
#     the same app claims them;
#   - deleting a registration keeps its postbacks' owner, unlinks them and
#     deletes its encodings; registering the app again relinks them;
#   - 202-cronjobs/app-retention.php previews with --dry-run and prunes
#     unclaimed rows past their window while a trusted, claimed row of the
#     same age stays.
#
# Truncates the three app tables and creates (then deletes) its own user, so
# it needs a scratch database.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
# The retention job runs from THIS checkout and reads the database named by
# its 202-config.php, which must be the instance's (install-instance.sh
# writes it). Set P202_PHP to run it with another interpreter.
PHP=${P202_PHP:-php}

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass drives the REST API as the instance's admin." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
Q() { mysql_q -N "$DB" -e "$1"; }

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
ne()   { if [ "$1" != "$2" ]; then ok "$3"; else bad "$3 (both '$1')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }

# api METHOD PATH [JSON-BODY] [KEY] — the body lands in $OUT/body, the
# headers in $OUT/head, and the status is printed.
api() {
    local key=${4-$P202_API_KEY}
    local args=(-s -o "$OUT/body" -D "$OUT/head" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $key")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# schema [curl args...] — the public schema route, with no API key.
schema() { curl -s -o "$OUT/body" -D "$OUT/head" -w '%{http_code}' "$@" "$BASE/api/v3/apps/schema"; }
# field EXPR — a value out of the last body, as Python reads it (d = the JSON).
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else (json.dumps(v) if isinstance(v,(dict,list,bool)) else v))" "$OUT/body" 2>/dev/null; }
header() { grep -i "^$1:" "$OUT/head" | head -1 | cut -d' ' -f2- | tr -d '\r'; }

# A postback posted to the public SKAdNetwork receiver. The signature is not
# Apple's, so it is stored refuted (trusted = 0) — which is fine: this pass is
# about ownership, and ownership does not depend on trust.
skan() { # app-id transaction-id
    curl -s -o "$OUT/pb" -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
      -d "{\"version\":\"4.0\",\"ad-network-id\":\"core.skadnetwork\",\"source-identifier\":\"11\",\"app-id\":$1,\"transaction-id\":\"$2\",\"redownload\":false,\"fidelity-type\":1,\"did-win\":true,\"postback-sequence-index\":0,\"conversion-value\":3,\"attribution-signature\":\"AA==\"}" \
      "$BASE/.well-known/skadnetwork/report-attribution/"
}

OWNER=$(Q "SELECT user_id FROM 202_api_keys WHERE api_key='$P202_API_KEY'" 2>/dev/null)
[ -n "$OWNER" ] || OWNER=$(Q "SELECT user_id FROM 202_users ORDER BY user_id LIMIT 1")
[ -n "$OWNER" ] || { echo "no user in $DB" >&2; exit 2; }
IOS=990088001; SHARED=990088002
RUN=$(date +%s)

mysql_q "$DB" -e "TRUNCATE 202_app_registrations; TRUNCATE 202_app_postbacks; TRUNCATE 202_app_skan_encodings; TRUNCATE 202_app_skan_encoding_history; TRUNCATE 202_goals; TRUNCATE 202_goal_versions;"

say "a postback that arrives before its app is registered waits unclaimed"
eq "$(skan $IOS "core-a-$RUN")" 200 "the receiver accepts it"
eq "$(Q "SELECT CONCAT(user_id, '/', IFNULL(registration_id, 'NULL'), '/', IFNULL(trusted, 'NULL')) FROM 202_app_postbacks WHERE transaction_id='core-a-$RUN'")" \
   "0/NULL/0" "stored with no owner, no registration, and refuted (not Apple's signature)"

say "registering from an App Store link claims it"
code=$(api POST /apps "{\"store_link\":\"https://apps.apple.com/us/app/summit-run/id$IOS\",\"app_name\":\"Summit Run\"}")
eq "$code" 201 "POST /apps with a store link answers 201"
RID=$(field "d['data']['registration_id']")
eq "$(field "d['data']['platform']")" ios "the platform is read from the link"
eq "$(field "d['data']['app_key']")" "$IOS" "the app key is the App Store id, as a string"
TOKEN=$(field "d['data']['app_token']")
[[ "$TOKEN" =~ ^[0-9a-f]{64}$ ]] && ok "an app token is minted (64 hex)" || bad "an app token is minted (got '$TOKEN')"
eq "$(field "'store_link' in d['data']")" false "the link is read, not stored"
eq "$(Q "SELECT CONCAT(user_id, '/', registration_id) FROM 202_app_postbacks WHERE transaction_id='core-a-$RUN'")" \
   "$OWNER/$RID" "the waiting postback now belongs to the owner and the registration"
eq "$(api POST /apps "{\"app_key\":\"$IOS\",\"app_name\":\"Again\"}")" 409 "the same app a second time is a 409"
eq "$(Q "SELECT COUNT(*) FROM 202_app_registrations WHERE app_key='$IOS'")" 1 "and there is still one registration"

say "Android, from a Google Play link; package names are case-sensitive"
code=$(api POST /apps '{"store_link":"https://play.google.com/store/apps/details?id=com.Example.Game&hl=en","app_name":"Game"}')
eq "$code" 201 "a Google Play link registers an Android app"
AID=$(field "d['data']['registration_id']")
eq "$(field "d['data']['platform']")/$(field "d['data']['app_key']")" "android/com.Example.Game" "platform and package read from the link, case kept"
ATOKEN=$(field "d['data']['app_token']")
eq "$(api POST /apps '{"platform":"android","app_key":"com.example.game","app_name":"Other game"}')" 201 \
   "a package differing only in case is a different app"
LOWER=$(field "d['data']['registration_id']")
eq "$(api POST /apps '{"app_key":"com.Example.Game","app_name":"Dup"}')" 409 "the exact package again is a 409"
eq "$(api DELETE "/apps/$LOWER")" 204 "the lower-case one is removed again"

say "the identity is read strictly and fixed once registered"
eq "$(api POST /apps "{\"store_link\":\"id$IOS\",\"app_key\":\"$IOS\",\"app_name\":\"x\"}")" 422 "store_link with app_key is refused"
has "$OUT/body" "not both" "saying to send one of them"
eq "$(api POST /apps '{"platform":"ios","store_link":"market://details?id=com.example.other","app_name":"x"}')" 422 \
   "a platform that contradicts the link is refused"
eq "$(field "list(d['field_errors'])")" '["platform"]' "naming the platform"
eq "$(api POST /apps '{"app_key":"not a key","app_name":"x"}')" 422 "a key that is neither an App Store id nor a package is refused"
eq "$(api PUT "/apps/$RID" '{"app_key":"990088099"}')" 422 "update cannot change which app it is"
eq "$(api PUT "/apps/$RID" "{\"app_key\":\"$IOS\",\"app_name\":\"Summit Run Pro\"}")" 200 "naming the same app on update is accepted"
eq "$(Q "SELECT CONCAT(app_key, '/', app_name) FROM 202_app_registrations WHERE registration_id=$RID")" "$IOS/Summit Run Pro" \
   "and only the name changed"

say "SKAN encodings name one of your own iOS registrations, and a goal"
# An encoding names the goal a value means (PR 4); the device evaluates it
# (PR 8, tests/live/ios-sdk.sh covers the goal shapes).
api POST /goals "{\"scope\":\"registration\",\"scope_id\":$RID,\"definition\":{\"name\":\"purchase\",\"trigger\":{\"event\":\"purchase\"}}}" > /dev/null
G_BUY=$(field "d['data']['goal_id']")
api POST /goals '{"scope":"account","definition":{"name":"whale","trigger":{"event":"whale"},"value":{"type":"fixed","amount":20}}}' > /dev/null
G_WHALE=$(field "d['data']['goal_id']")
[ -n "$G_BUY" ] && [ -n "$G_WHALE" ] && ok "the goals the encodings name exist" || bad "the goals the encodings name exist"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$RID,\"fine_value\":3,\"goal_id\":$G_BUY,\"revenue_override\":4.99}")" 201 \
   "an encoding for the iOS registration"
ENC=$(field "d['data']['encoding_id']")
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$AID,\"fine_value\":4,\"goal_id\":$G_WHALE}")" 422 "not for an Android registration"
has "$OUT/body" "is an Android app; SKAN encodings apply to iOS apps only" "which the error says, in a sentence"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":987654,\"fine_value\":4,\"goal_id\":$G_WHALE}")" 422 "not for a registration that does not exist"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":\"1e3\",\"fine_value\":4,\"goal_id\":$G_WHALE}")" 422 \
   "a registration id an integer cast would change is refused, not rounded"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":0,\"coarse_value\":\"high\",\"goal_id\":$G_WHALE}")" 201 \
   "registration 0 is the account-wide set"

say "GET /apps/schema: selected by the header, shaped by the platform"
eq "$(schema -H "X-P202-App-Token: $TOKEN")" 200 "the iOS token gets its document"
eq "$(field "d['data']['platform']")/$(field "d['data']['app_key']")/$(field "d['data']['app_id']")" "ios/$IOS/$IOS" "naming the app"
enc() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(next((e[sys.argv[3]] for e in d['data']['encodings'] if e['goal_id']==int(sys.argv[2])), 'none'))" "$OUT/body" "$1" "$2"; }
eq "$(enc "$G_BUY" fine_value)" 3 "the encodings carry the registration's encoding"
eq "$(enc "$G_WHALE" coarse_value)" high "and the account-wide one"
eq "$(field "sorted(g['goal_id'] for g in d['data']['goals'])")" "$(python3 -c "print(sorted([$G_BUY, $G_WHALE]))")" "with the goals they name"
hasnt "$OUT/body" '"value": {' "and no goal's value"
hasnt "$OUT/body" '"value":{' "in any spelling"
hasnt "$OUT/body" "4.99" "revenue is never served to a device"
hasnt "$OUT/body" "revenue" "not even the key"
ETAG=$(header ETag)
[ -n "$ETAG" ] && ok "an ETag is sent ($ETAG)" || bad "an ETag is sent"
eq "$(header Vary)" "X-P202-App-Token" "Vary names the token header"
eq "$(schema -H "X-P202-App-Token: $TOKEN" -H "If-None-Match: $ETAG")" 304 "the same ETag back is a 304"
eq "$(schema -H "X-P202-App-Token: $ATOKEN")" 200 "the Android token gets its document"
eq "$(field "d['data']['platform']")/$(field "d['data']['app_key']")" "android/com.Example.Game" "naming the Android app"
eq "$(field "'goals' in d['data'] or 'encodings' in d['data'] or 'app_id' in d['data']")" false "with no goals, no SKAN map and no App Store id"
eq "$(schema)" 400 "no header is a 400"
eq "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v3/apps/schema?token=$TOKEN")" 400 "a token in the query string is not read"
eq "$(schema -H 'X-P202-App-Token: not-a-token')" 400 "a malformed token is a 400"
eq "$(schema -H "X-P202-App-Token: $(printf '0%.0s' $(seq 64))")" 404 "a well-formed token nobody holds is a 404"
eq "$(schema -X POST -H "X-P202-App-Token: $TOKEN")" 405 "POST is a 405"
eq "$(header Allow)" "GET" "naming what is allowed (HEAD is implied by GET)"
eq "$(schema -I -H "X-P202-App-Token: $TOKEN")" 200 "HEAD is served"
api PUT "/apps/skan-encodings/$ENC" '{"fine_value":5}' > /dev/null
eq "$(schema -H "X-P202-App-Token: $TOKEN" -H "If-None-Match: $ETAG")" 200 "an encoding change changes the document"
ne "$(header ETag)" "$ETAG" "and its ETag"

say "apps is its own scope area"
mint() { # scope -> a new key for the owner; empty if refused, which the caller checks
    local code
    code=$(api POST "/users/$OWNER/api-keys" "{\"scope\":\"$1\"}")
    [ "$code" = 201 ] || { echo "    minting a $1 key answered $code: $(cat "$OUT/body")" >&2; return; }
    field "d['data'].get('api_key') or d['data'].get('key')"
}
READ_KEY=$(mint apps:read)
ATTR_KEY=$(mint attribution:write)
[ -n "$READ_KEY" ] && ok "minted an apps:read key" || bad "minted an apps:read key"
eq "$(api GET /apps '' "$READ_KEY")" 200 "apps:read lists registrations"
eq "$(api GET "/apps/$RID" '' "$READ_KEY")" 200 "and reads one"
eq "$(api POST /apps '{"app_key":"990088050","app_name":"x"}' "$READ_KEY")" 403 "but cannot register"
eq "$(api POST /apps/verify '{"version":"4.0"}' "$READ_KEY")" "$(api POST /apps/verify '{"version":"4.0"}')" \
   "POST /apps/verify is a read: the read key gets the admin's answer"
[ -n "$ATTR_KEY" ] && ok "minted an attribution:write key" || bad "minted an attribution:write key"
eq "$(api GET /attribution/models '' "$ATTR_KEY")" 200 "which reads its own area"
eq "$(api GET /apps '' "$ATTR_KEY")" 403 "and cannot reach /apps"
eq "$(api GET /attribution/apps)" 404 "the old /attribution/apps route is gone"
eq "$(api GET /attribution/postbacks)" 404 "and /attribution/postbacks"
eq "$(api GET /attribution/conversion-values)" 404 "and /attribution/conversion-values"
eq "$(curl -s -o /dev/null -w '%{http_code}' -H "X-P202-Schema-Token: $TOKEN" "$BASE/api/v3/attribution/schema")" 401 \
   "the old public schema route is no longer public"
for k in "$READ_KEY" "$ATTR_KEY"; do api DELETE "/users/$OWNER/api-keys/$k" > /dev/null; done

say "rotating the token stops the old one at once"
eq "$(api POST "/apps/$RID/app-token/rotate")" 200 "rotate answers 200"
NEW=$(field "d['data']['app_token']")
ne "$NEW" "$TOKEN" "with a new token"
eq "$(schema -H "X-P202-App-Token: $TOKEN")" 404 "the old token is unknown"
eq "$(schema -H "X-P202-App-Token: $NEW")" 200 "the new one is served"
TOKEN=$NEW

say "deleting a user purges their apps and releases their postbacks"
code=$(api POST /users "{\"user_name\":\"appcore$RUN\",\"user_email\":\"appcore$RUN@example.com\",\"user_pass\":\"appcore-pass-$RUN\"}")
eq "$code" 201 "an admin creates a second user"
OTHER=$(field "d['data']['user_id']")
api POST "/users/$OTHER/api-keys" '{}' > /dev/null
OKEY=$(field "d['data'].get('api_key') or d['data'].get('key')")
[ -n "$OKEY" ] && ok "and a key for them" || bad "and a key for them"
eq "$(skan $SHARED "core-b-$RUN")" 200 "a postback for their app arrives"
eq "$(api POST /apps "{\"app_key\":\"$SHARED\",\"app_name\":\"Their app\",\"accept_test_signals\":1}" "$OKEY")" 201 "they register the app"
ORID=$(field "d['data']['registration_id']")
api POST /goals "{\"scope\":\"registration\",\"scope_id\":$ORID,\"definition\":{\"name\":\"theirs\",\"trigger\":{\"event\":\"theirs\"}}}" "$OKEY" > /dev/null
OGOAL=$(field "d['data']['goal_id']")
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$ORID,\"fine_value\":1,\"goal_id\":$OGOAL}" "$OKEY")" 201 "and give it an encoding"
eq "$(api POST /apps/skan-encodings "{\"registration_id\":$ORID,\"fine_value\":1,\"goal_id\":$G_WHALE}")" 422 \
   "the admin cannot hang an encoding on another user's registration"
has "$OUT/body" "No registration $ORID in this account" "and is told it is not theirs, not that it exists"
eq "$(Q "SELECT CONCAT(user_id, '/', registration_id) FROM 202_app_postbacks WHERE transaction_id='core-b-$RUN'")" \
   "$OTHER/$ORID" "their registration claimed the postback"
eq "$(api DELETE "/users/$OTHER?dry_run=1")" 200 "the delete preview answers"
eq "$(field "[c['resource'] + ':' + c['action'].split(' ')[0] for c in d['data']['cascade'] if c['resource'].startswith(('202_app_', '202_api_'))]")" \
   '["202_api_keys:delete", "202_app_postbacks:release", "202_app_skan_encodings:delete", "202_app_skan_encoding_history:delete", "202_app_installs:delete", "202_app_integrity_credentials:delete", "202_app_registrations:delete"]' \
   "and names the keys and app tables with what happens to each"
eq "$(Q "SELECT COUNT(*) FROM 202_app_registrations WHERE user_id=$OTHER")" 1 "the preview deleted nothing"
eq "$(api DELETE "/users/$OTHER")" 204 "DELETE /users/{id} answers 204"
eq "$(Q "SELECT user_deleted FROM 202_users WHERE user_id=$OTHER")" 1 "the user is marked deleted"
eq "$(Q "SELECT COUNT(*) FROM 202_app_registrations WHERE user_id=$OTHER")" 0 "their registrations are gone"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE user_id=$OTHER")" 0 "their encodings are gone"
eq "$(Q "SELECT COUNT(*) FROM 202_goals WHERE user_id=$OTHER")" 0 "and their goals"
eq "$(Q "SELECT CONCAT(user_id, '/', IFNULL(registration_id, 'NULL')) FROM 202_app_postbacks WHERE transaction_id='core-b-$RUN'")" \
   "0/NULL" "their postback is released, not deleted"
eq "$(api GET /apps '' "$OKEY")" 401 "and their key no longer works"
eq "$(api POST /apps "{\"app_key\":\"$SHARED\",\"app_name\":\"Now ours\"}")" 201 "the app can be registered by someone else"
SRID=$(field "d['data']['registration_id']")
eq "$(Q "SELECT CONCAT(user_id, '/', registration_id) FROM 202_app_postbacks WHERE transaction_id='core-b-$RUN'")" \
   "$OWNER/$SRID" "whose registration claims the released postback"

say "deleting a registration unlinks its postbacks and takes its encodings"
eq "$(api DELETE "/apps/$RID?dry_run=1")" 200 "the delete preview (dry_run=1) answers"
hasnt "$OUT/body" "$TOKEN" "without the app token in it"
eq "$(api DELETE "/apps/$RID")" 204 "DELETE /apps/{id} answers 204"
eq "$(Q "SELECT CONCAT(user_id, '/', IFNULL(registration_id, 'NULL')) FROM 202_app_postbacks WHERE transaction_id='core-a-$RUN'")" \
   "$OWNER/NULL" "the postback keeps its owner and loses the registration"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE registration_id=$RID")" 0 "the registration's encodings are gone"
eq "$(Q "SELECT COUNT(*) FROM 202_app_skan_encodings WHERE registration_id=0 AND user_id=$OWNER")" 1 "the account-wide one stays"
eq "$(schema -H "X-P202-App-Token: $TOKEN")" 404 "its token is unknown"
eq "$(api POST /apps "{\"app_key\":\"$IOS\",\"app_name\":\"Summit Run\"}")" 201 "registering it again"
RID2=$(field "d['data']['registration_id']")
eq "$(Q "SELECT registration_id FROM 202_app_postbacks WHERE transaction_id='core-a-$RUN'")" "$RID2" "relinks the owner's postback"

say "retention: a preview first, then only what is past its window"
OLD=$(( $(date +%s) - 40 * 86400 ))
mysql_q "$DB" -e "INSERT INTO 202_app_postbacks
  (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id,
   signature_state, trusted, attribution_signature, raw_payload, remote_ip, dedupe_hash, created_at)
  VALUES (0, NULL, $OLD, 'skadnetwork', '4.0', 'old.skadnetwork', 'core-old-unclaimed-$RUN', 990088077,
   'valid', 1, 'sig', '{}', '127.0.0.1', SHA1('core-old-u-$RUN'), $OLD),
  ($OWNER, $RID2, $OLD, 'skadnetwork', '4.0', 'old.skadnetwork', 'core-old-trusted-$RUN', $IOS,
   'valid', 1, 'sig', '{}', '127.0.0.1', SHA1('core-old-t-$RUN'), $OLD)"
(cd "$ROOT" && "$PHP" 202-cronjobs/app-retention.php --dry-run) > "$OUT/dry.txt" 2>&1
eq "$?" 0 "the dry run exits 0"
sed 's/^/    | /' "$OUT/dry.txt"
grep -qE '^postbacks/unclaimed: 30-day window, cutoff .*, 1 aged row\(s\)$' "$OUT/dry.txt" \
  && ok "it reports the one aged unclaimed row" || bad "it reports the one aged unclaimed row"
has "$OUT/dry.txt" "dry run: nothing deleted" "and says it deleted nothing"
eq "$(Q "SELECT COUNT(*) FROM 202_app_postbacks WHERE transaction_id='core-old-unclaimed-$RUN'")" 1 "which it did not"
(cd "$ROOT" && P202_APP_RETENTION_DAYS_POSTBACKS_UNCLAIMED=soon "$PHP" 202-cronjobs/app-retention.php) > "$OUT/bad.txt" 2>&1
has "$OUT/bad.txt" "P202_APP_RETENTION_DAYS_POSTBACKS_UNCLAIMED" "a malformed window is named"
eq "$(Q "SELECT COUNT(*) FROM 202_app_postbacks WHERE transaction_id='core-old-unclaimed-$RUN'")" 1 "and prunes nothing, rather than the default"
(cd "$ROOT" && "$PHP" 202-cronjobs/app-retention.php) > "$OUT/run.txt" 2>&1
eq "$?" 0 "the real run exits 0"
grep -q '^postbacks/unclaimed: 1 aged row(s) removed, 0 remaining$' "$OUT/run.txt" \
  && ok "it reports the removal" || bad "it reports the removal"
eq "$(Q "SELECT COUNT(*) FROM 202_app_postbacks WHERE transaction_id='core-old-unclaimed-$RUN'")" 0 "the aged unclaimed row is gone"
eq "$(Q "SELECT COUNT(*) FROM 202_app_postbacks WHERE transaction_id='core-old-trusted-$RUN'")" 1 "the trusted, claimed row of the same age stays"
eq "$(Q "SELECT COUNT(*) FROM 202_app_postbacks WHERE transaction_id='core-a-$RUN'")" 1 "a fresh refuted row inside its window stays"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
