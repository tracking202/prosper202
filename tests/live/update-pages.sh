#!/bin/bash
# Live pass for the Update pages on the v2 shell (U5): Update CPC, Reset
# Campaign Subids, Update Subids, Delete Subids and Upload Revenue Reports.
# Driven over HTTP against a running instance, with the database read back
# after every write, including every refusal: a missing token writes nothing
# and says so in the page's own sentence, a bad field shows its sentence
# under the field and writes nothing.
#
# tests/live/conversion-ledger.sh drives the ledger behaviour behind the
# subid, delete and upload pages (PR 1); this pass covers what that one does
# not: Update CPC and Reset Campaign Subids (both moved off AJAX fragments in
# U5 — the CPC write asked for no token before), the column picker's guesses,
# and each page's refusals.
#
# Every POST submits the form's OWN fields, serialized from the page by
# form-body.py, and changes only what the step means to change (error
# pattern #21: a token scraped from anywhere on the page proves nothing about
# the form a person submits).
#
# Seeds its own categories, campaigns, traffic source and clicks (ids
# 940001-940006) and removes them at both ends, so it can run repeatedly.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
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
Q() { mysql_q -N "$DB" -e "$1"; }

JAR=$(mktemp)
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
# A sentence in a flash, or under a field: asserted where the page puts it,
# not anywhere in the body.
flash()   { if grep -qF -- "<div class=\"p202-flash__body\">$2" "$1"; then ok "$3"; else bad "$3"; grep -oE '<div class="p202-flash__body">[^<]*' "$1" | sed 's/^/    | /'; fi; }
feedback(){ if grep -qF -- "<div class=\"invalid-feedback d-block\">$2" "$1"; then ok "$3"; else bad "$3"; grep -oE '<div class="invalid-feedback[^"]*">[^<]*' "$1" | sed 's/^/    | /'; fi; }

REFUSED='Your session expired before the form was sent. Nothing was changed; please send it again.'

get() { curl -sS -b "$JAR" -c "$JAR" -L "$BASE/$1" -o "$2" -w '%{http_code}' > "$OUT/.code"; }

# Submit one form of a saved page the way a browser would (never following
# a redirect: every Update write answers in place).
#   submit PAGE_FILE MARKER URL OUT_FILE [name=value ...]
submit() {
  local page="$1" marker="$2" url="$3" out="$4"; shift 4
  if ! python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body"; then
    bad "the form carrying '$marker' is on the page"
    : > "$out"
    return
  fi
  curl -sS -b "$JAR" -c "$JAR" -o "$out" -w '%{http_code}' \
    --data-binary @"$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url" > "$OUT/.code"
}
# The token inside the form that carries MARKER (for the multipart upload,
# which form-body.py does not serialize).
form_token() { python3 "$HERE/form-body.py" "$1" "$2" | tr '&' '\n' | sed -n 's/^token=//p'; }

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
[ -n "$USER_ID" ] || { echo "no user $P202_USER in $DB" >&2; exit 2; }
TZ_NAME=$(Q "SELECT user_timezone FROM 202_users WHERE user_id=$USER_ID")
# Noon on three days in the account's time zone: the CPC form speaks in the
# account's days.
at() { php -r 'date_default_timezone_set($argv[1]); echo mktime(12, 0, 0, (int) $argv[3], (int) $argv[4], (int) $argv[2]);' "$TZ_NAME" "$@"; }
D10=$(at 2021 3 10); D11=$(at 2021 3 11); D12=$(at 2021 3 12)
# Midnight starting the first day: where an Update CPC window begins.
M10=$(php -r 'date_default_timezone_set($argv[1]); echo mktime(0, 0, 0, 3, 10, 2021);' "$TZ_NAME")
NOW=$(date +%s)
C1=940001; C2=940002; C3=940003; C4=940004; C5=940005; C6=940006
CLICKS="$C1,$C2,$C3,$C4,$C5,$C6"
FOREIGN_USER=999940

cleanup() {
  mysql_q "$DB" <<SQL
DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($CLICKS));
DELETE FROM 202_conversion_logs WHERE click_id IN ($CLICKS);
DELETE FROM 202_dataengine WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_spy WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_tracking WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_site WHERE click_id IN ($CLICKS);
DELETE FROM 202_clicks_advance WHERE click_id IN ($CLICKS);
DELETE FROM 202_dirty_hours WHERE user_id=$USER_ID AND click_time_from IN ($D10, $M10);
DELETE FROM 202_aff_campaigns WHERE aff_campaign_name LIKE 'u5-pass-%';
DELETE FROM 202_aff_networks WHERE aff_network_name LIKE 'u5-pass-%';
DELETE FROM 202_ppc_accounts WHERE ppc_account_name LIKE 'u5-pass-%';
DELETE FROM 202_ppc_networks WHERE ppc_network_name LIKE 'u5-pass-%';
DELETE FROM 202_conversion_uploads WHERE file_name='${FILE:-none}';
SQL
}
cleanup
trap cleanup EXIT

mysql_q "$DB" <<SQL
INSERT INTO 202_aff_networks (user_id, aff_network_name, aff_network_time) VALUES ($USER_ID, 'u5-pass-network', $NOW), ($USER_ID, 'u5-pass-other', $NOW);
INSERT INTO 202_ppc_networks (user_id, ppc_network_name, ppc_network_time) VALUES ($USER_ID, 'u5-pass-source', $NOW);
SQL
NET=$(Q "SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='u5-pass-network'")
NET2=$(Q "SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='u5-pass-other'")
PNET=$(Q "SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_name='u5-pass-source'")
mysql_q "$DB" <<SQL
INSERT INTO 202_aff_campaigns (aff_campaign_id_public, user_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_time, aff_campaign_foreign_payout, payout_mode)
  VALUES (940101, $USER_ID, $NET, 'u5-pass-campaign-a', 'http://example.test/', 3.00, $NOW, 3.00, 'replace'),
         (940102, $USER_ID, $NET2, 'u5-pass-campaign-b', 'http://example.test/', 3.00, $NOW, 3.00, 'replace'),
         (940103, $FOREIGN_USER, 0, 'u5-pass-foreign', 'http://example.test/', 1.00, $NOW, 1.00, 'replace');
INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES ($USER_ID, $PNET, 'u5-pass-account', $NOW);
SQL
CA=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='u5-pass-campaign-a'")
CB=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='u5-pass-campaign-b'")
CF=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='u5-pass-foreign'")
PPC=$(Q "SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name='u5-pass-account'")
[ -n "$CA" ] && [ -n "$CB" ] && [ -n "$CF" ] && [ -n "$PPC" ] || { echo "seeding failed" >&2; exit 2; }

seed_click() { # id campaign ppc time landing-site-url-id
  mysql_q "$DB" -e "
    INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)
      VALUES ($1, $USER_ID, $2, 0, $3, 0.10, 3.00, 0, 0, 0, 0, $4, 0, 0);
    INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)
      VALUES ($1, $USER_ID, $2, 0, $3, 0.10, 3.00, 0, 0, 0, 0, $4);
    INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES ($1, 0, 0, 0, 0);
    INSERT INTO 202_clicks_site (click_id, click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id) VALUES ($1, 0, $5, 0, 0, 0);"
}
seed_click $C1 "$CA" "$PPC" "$D10" 5
seed_click $C2 "$CA" "$PPC" "$D11" 5
seed_click $C3 "$CA" "$PPC" "$D11" 0
seed_click $C4 "$CB" "$PPC" "$D10" 0
seed_click $C5 "$CA" "$PPC" "$D12" 0
seed_click $C6 "$CA" 0 "$D10" 0
[ "$(Q "SELECT COUNT(*) FROM 202_clicks_site WHERE click_id IN ($CLICKS)")" = 6 ] || { echo "seeding clicks failed" >&2; exit 2; }

cpc()   { Q "SELECT GROUP_CONCAT(click_cpc ORDER BY click_id) FROM 202_clicks WHERE click_id IN ($CLICKS)"; }
rows()  { Q "SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=$1 AND deleted=0"; }
lead()  { Q "SELECT click_lead FROM 202_clicks WHERE click_id=$1"; }
gpb()   { curl -sS -o /dev/null -w '%{http_code}' "$BASE/tracking202/static/gpb.php?$1"; }

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(python3 "$HERE/form-body.py" "$OUT/login.html" user_pass | tr '&' '\n' | sed -n 's/^token=//p')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "tracking202/update/subids.php" "$OUT/s0.html"
hasnt "$OUT/s0.html" 'name="user_pass"' "session established (no login form)"

# ─────────────────────────────────────────────────────────────────────
say "every Update page renders on the v2 shell, with no PHP noise"
for page in tracking202/update/ tracking202/update/subids.php tracking202/update/cpc.php tracking202/update/clear-subids.php \
            tracking202/update/delete-subids.php tracking202/update/upload.php; do
  f="$OUT/render-$(echo "$page" | tr '/?=' '___').html"
  get "$page" "$f"
  code=$(cat "$OUT/.code")
  if [ "$code" = "200" ] && grep -q 'p202-shell-v2' "$f" && grep -q 'p202-page-header__title' "$f" && ! grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$f"; then
    ok "$page: 200, v2 shell, a page header, clean"
  else
    bad "$page: status $code, v2=$(grep -c p202-shell-v2 "$f"), noise=$(grep -cE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$f")"
  fi
done
# The fragments the classic pages posted to are retired: update_cpc2.php
# wrote with no token at all. (php -S answers a missing script with 200 and
# no PHP, so the proof is what the answer lacks and what did not change.)
for gone in tracking202/ajax/update_cpc.php tracking202/ajax/update_cpc2.php tracking202/ajax/clear_subids.php; do
  curl -sS -b "$JAR" -o "$OUT/gone.html" --data "cpc_dollars=9&cpc_cents=0&from=03/01/2021&to=03/31/2021&aff_network_id=$NET" "$BASE/$gone"
  if [ ! -e "$HERE/../../$gone" ] && ! grep -qE 'clicks updated|You have reset|BE VERY SURE' "$OUT/gone.html"; then
    ok "the retired fragment $gone is gone and answers nothing it used to"
  else
    bad "the retired fragment $gone is gone and answers nothing it used to"
  fi
done
eq "$(cpc)" "0.10000,0.10000,0.10000,0.10000,0.10000,0.10000" "and nothing was written through them"

# ─────────────────────────────────────────────────────────────────────
say "Update CPC: check first, then confirm; refusals are said and write nothing"
PREVIEW="tracking202/update/cpc.php?preview=1&from=2021-03-10&to=2021-03-11&aff_network_id=$NET&aff_campaign_id=$CA&ppc_network_id=0&ppc_account_id=0&cpc=0.12345"
get "${PREVIEW/cpc=0.12345/cpc=abc}" "$OUT/cpc-bad.html"
feedback "$OUT/cpc-bad.html" "&#039;abc&#039; is not a CPC." "a CPC that is not a number is refused under the field"
hasnt "$OUT/cpc-bad.html" 'id="cpc-apply"' "and no update is offered"
get "${PREVIEW/cpc=0.12345/cpc=250}" "$OUT/cpc-big.html"
feedback "$OUT/cpc-big.html" 'A CPC can be at most $99.99999.' "a CPC the column cannot hold is refused, not truncated"
get "${PREVIEW/to=2021-03-11/to=2021-03-01}" "$OUT/cpc-order.html"
feedback "$OUT/cpc-order.html" 'The last day is before the first day.' "a window that ends before it starts is refused"
get "${PREVIEW/aff_campaign_id=$CA/aff_campaign_id=$CF}" "$OUT/cpc-foreign.html"
feedback "$OUT/cpc-foreign.html" 'You can not modify other peoples cpc history.' "another account's campaign is refused, not widened to all"
get "${PREVIEW/aff_campaign_id=$CA/aff_campaign_id=12x}" "$OUT/cpc-junk.html"
feedback "$OUT/cpc-junk.html" 'Choose a campaign from the list.' "an id that is not a number is refused, not read as every campaign"
eq "$(cpc)" "0.10000,0.10000,0.10000,0.10000,0.10000,0.10000" "no check writes anything"

get "$PREVIEW" "$OUT/cpc-preview.html"
has "$OUT/cpc-preview.html" '<span class="p202-pill p202-pill--accent">4 clicks</span>' "the check counts the four clicks of campaign A on those two days (C1, C2, C3, C6)"
has "$OUT/cpc-preview.html" '>Update 4 clicks</button>' "and offers to update exactly those"
has "$OUT/cpc-preview.html" 'u5-pass-network · u5-pass-campaign-a' "naming the category and campaign"
eq "$(cpc)" "0.10000,0.10000,0.10000,0.10000,0.10000,0.10000" "the check itself wrote nothing"

get "${PREVIEW}&method_of_promotion=landingpage" "$OUT/cpc-lp.html"
has "$OUT/cpc-lp.html" '>Update 2 clicks</button>' "landing pages only: the two clicks with a landing page"
get "${PREVIEW}&method_of_promotion=directlink" "$OUT/cpc-dl.html"
has "$OUT/cpc-dl.html" '>Update 2 clicks</button>' "direct links only: the two without"
get "${PREVIEW/aff_campaign_id=$CA/aff_campaign_id=0}&ppc_network_id=$PNET&ppc_account_id=$PPC" "$OUT/cpc-src.html"
has "$OUT/cpc-src.html" '>Update 3 clicks</button>' "every campaign of the category, one traffic source account: C1-C3 (C6 has no account, C4 is in the other category)"

submit "$OUT/cpc-preview.html" token "tracking202/update/cpc.php" "$OUT/cpc-notoken.html" token=
flash "$OUT/cpc-notoken.html" "$REFUSED" "confirming without the session token is refused in words"
eq "$(cpc)" "0.10000,0.10000,0.10000,0.10000,0.10000,0.10000" "and writes nothing"
submit "$OUT/cpc-preview.html" token "tracking202/update/cpc.php" "$OUT/cpc-forged.html" token=0123456789abcdef
flash "$OUT/cpc-forged.html" "$REFUSED" "a token that is not the session's is refused too"
submit "$OUT/cpc-preview.html" token "tracking202/update/cpc.php" "$OUT/cpc-tamper.html" "aff_campaign_id=$CF"
feedback "$OUT/cpc-tamper.html" 'You can not modify other peoples cpc history.' "the confirm step checks ownership again (a tampered hidden field)"
eq "$(cpc)" "0.10000,0.10000,0.10000,0.10000,0.10000,0.10000" "and writes nothing"

DIRTY_BEFORE=$(Q "SELECT COUNT(*) FROM 202_dirty_hours WHERE user_id=$USER_ID AND aff_campaign_id=$CA")
submit "$OUT/cpc-preview.html" token "tracking202/update/cpc.php" "$OUT/cpc-apply.html"
flash "$OUT/cpc-apply.html" '4 clicks updated.' "confirming updates the four clicks and says so"
eq "$(cpc)" "0.12345,0.12345,0.12345,0.10000,0.10000,0.12345" "exactly C1-C3 and C6 cost \$0.12345; the other category and the next day are untouched"
eq "$(Q "SELECT COUNT(*) FROM 202_dirty_hours WHERE user_id=$USER_ID AND aff_campaign_id=$CA")" "$((DIRTY_BEFORE+1))" "the data engine is told to rebuild those hours"
submit "$OUT/cpc-preview.html" token "tracking202/update/cpc.php" "$OUT/cpc-again.html"
flash "$OUT/cpc-again.html" '0 clicks updated.' "sending it again changes nothing, and says so"

# ─────────────────────────────────────────────────────────────────────
say "Reset Campaign Subids: one category, or one campaign of it, through the ledger"
eq "$(gpb "subid=$C1&amount=3&txid=U5A")" "200" "a conversion on C1 (campaign A)"
gpb "subid=$C4&amount=3&txid=U5B" >/dev/null
eq "$(rows $C1)/$(rows $C4)" "1/1" "and one on C4 (campaign B, the other category)"
get "tracking202/update/clear-subids.php" "$OUT/clr0.html"
submit "$OUT/clr0.html" aff_network_id "tracking202/update/clear-subids.php" "$OUT/clr-notoken.html" "aff_network_id=$NET" token=
flash "$OUT/clr-notoken.html" "$REFUSED" "a reset without the session token is refused in words"
eq "$(rows $C1)" "1" "and clears nothing"
submit "$OUT/clr0.html" aff_network_id "tracking202/update/clear-subids.php" "$OUT/clr-none.html" "aff_network_id="
feedback "$OUT/clr-none.html" 'You have to at least select an affiliate network to clear out.' "no category is refused under the field"
submit "$OUT/clr0.html" aff_network_id "tracking202/update/clear-subids.php" "$OUT/clr-mismatch.html" "aff_network_id=$NET" "aff_campaign_id=$CB"
feedback "$OUT/clr-mismatch.html" 'That campaign is not in the category you chose.' "a campaign from another category is refused (without the script the list is not narrowed)"
submit "$OUT/clr0.html" aff_network_id "tracking202/update/clear-subids.php" "$OUT/clr-foreign.html" "aff_network_id=$NET" "aff_campaign_id=$CF"
feedback "$OUT/clr-foreign.html" 'Choose one of your campaigns from the list.' "another account's campaign is refused"
eq "$(rows $C1)/$(rows $C4)" "1/1" "no refusal cleared anything"
submit "$OUT/clr0.html" aff_network_id "tracking202/update/clear-subids.php" "$OUT/clr-ok.html" "aff_network_id=$NET"
flash "$OUT/clr-ok.html" 'You have reset 1 subids. You can now re-upload your subids.' "resetting the category says how many"
eq "$(rows $C1)/$(lead $C1)" "0/0" "C1's conversion is deleted and it is no longer a lead"
eq "$(rows $C4)/$(lead $C4)" "1/1" "C4, in the other category, keeps its conversion"
eq "$(Q "SELECT COUNT(*) FROM 202_dirty_hours WHERE user_id=$USER_ID AND aff_network_id=$NET AND click_time_from=$D10")" "1" "the rebuild window starts at the earliest cleared click"

# ─────────────────────────────────────────────────────────────────────
say "Update Subids: an empty list is said, unknown lines are listed"
get "tracking202/update/subids.php" "$OUT/sub0.html"
submit "$OUT/sub0.html" subids "tracking202/update/subids.php" "$OUT/sub-empty.html" "subids=   "
feedback "$OUT/sub-empty.html" 'Paste at least one subid, one per line.' "an empty list is refused under the field, not answered '0 marked'"
submit "$OUT/sub0.html" subids "tracking202/update/subids.php" "$OUT/sub-notoken.html" "subids=$C2" token=
flash "$OUT/sub-notoken.html" "$REFUSED" "without the session token it is refused in words"
has "$OUT/sub-notoken.html" ">$C2</textarea>" "and what was pasted is kept for another try"
eq "$(rows $C2)" "0" "and nothing is recorded"
submit "$OUT/sub0.html" subids "tracking202/update/subids.php" "$OUT/sub-ok.html" "subids=$C2
not-a-subid
999999999"
flash "$OUT/sub-ok.html" '1 subid(s) marked as converted.' "one subid marked"
flash "$OUT/sub-ok.html" 'Not found in your account, so not marked: not-a-subid, 999999999.' "the two it could not mark are listed"
eq "$(rows $C2)/$(lead $C2)" "1/1" "C2 is converted through the ledger"

# ─────────────────────────────────────────────────────────────────────
say "Delete Subids: refusals, unreadable lines, the conversion cleared"
get "tracking202/update/delete-subids.php" "$OUT/del0.html"
has "$OUT/del0.html" 'data-p202-confirm="Delete every conversion' "the form asks before it deletes"
submit "$OUT/del0.html" subids "tracking202/update/delete-subids.php" "$OUT/del-notoken.html" "subids=$C2" token=
flash "$OUT/del-notoken.html" "$REFUSED" "without the session token it is refused in words"
eq "$(rows $C2)" "1" "and nothing is cleared"
submit "$OUT/del0.html" subids "tracking202/update/delete-subids.php" "$OUT/del-empty.html" "subids="
feedback "$OUT/del-empty.html" 'Paste at least one subid, one per line.' "an empty list is refused under the field"
submit "$OUT/del0.html" subids "tracking202/update/delete-subids.php" "$OUT/del-ok.html" "subids=$C2
bogus"
flash "$OUT/del-ok.html" '1 subid(s) cleared.' "one subid cleared"
flash "$OUT/del-ok.html" 'Not subids, so nothing was cleared for them: bogus.' "a line that is not a subid is named, not dropped"
eq "$(rows $C2)/$(lead $C2)" "0/0" "C2's conversion is gone"

# ─────────────────────────────────────────────────────────────────────
say "Upload Revenue Reports: refusals said, columns guessed from the header"
get "tracking202/update/upload.php" "$OUT/up0.html"
UT=$(form_token "$OUT/up0.html" token)
[ -n "$UT" ] && ok "the upload form carries the session token inside it" || bad "the upload form carries the session token inside it"
printf 'Sub ID,Order,Commission\n%s,A-1,2.25\n%s,A-2,1.00\n' $C3 $C3 > "$OUT/u5-pass.csv"
cp "$OUT/u5-pass.csv" "$OUT/u5-pass.xls"
curl -sS -b "$JAR" -c "$JAR" -o "$OUT/up-ext.html" -F "token=$UT" -F "csv=@$OUT/u5-pass.xls" "$BASE/tracking202/update/upload.php"
feedback "$OUT/up-ext.html" 'Upload the report as a .csv or .txt file' "a file that is not CSV is refused under the field"
curl -sS -b "$JAR" -c "$JAR" -o "$OUT/up-nofile.html" -F "token=$UT" "$BASE/tracking202/update/upload.php"
feedback "$OUT/up-nofile.html" 'Choose the CSV report to upload.' "no file is refused under the field"
curl -sS -b "$JAR" -c "$JAR" -o "$OUT/up-notoken.html" -w '%{redirect_url}' -F "csv=@$OUT/u5-pass.csv" "$BASE/tracking202/update/upload.php" > "$OUT/.loc"
flash "$OUT/up-notoken.html" "$REFUSED" "an upload without the session token is refused in words"
eq "$(cat "$OUT/.loc")" "" "and does not go on to the column picker"
LOC=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -F "token=$UT" -F "csv=@$OUT/u5-pass.csv" "$BASE/tracking202/update/upload.php")
FILE=$(printf '%s' "$LOC" | sed -n 's/.*[?&]file=\([^&]*\).*/\1/p')
[ -n "$FILE" ] && ok "a CSV goes on to the column picker" || bad "a CSV goes on to the column picker (got '$LOC')"
get "tracking202/update/upload.php?case=1&file=$FILE" "$OUT/up-pick.html"
has "$OUT/up-pick.html" 'name="click_id" value="0" aria-label="Sub ID holds the subid" checked' "the Sub ID column is chosen as the subid"
has "$OUT/up-pick.html" 'name="click_payout" value="2" aria-label="Commission holds the commission" checked' "the Commission column as the commission"
has "$OUT/up-pick.html" 'Chosen from the column names' "and the page says it chose them"
has "$OUT/up-pick.html" '<td class="text-secondary">A-1</td>' "each column shows its first line"
submit "$OUT/up-pick.html" click_id "tracking202/update/upload.php?case=2" "$OUT/up-nocol.html" click_payout=
flash "$OUT/up-nocol.html" 'You forgot to check the subid and the commission column.' "applying without a commission column is refused in words"
eq "$(rows $C3)" "0" "and records nothing"
submit "$OUT/up-pick.html" click_id "tracking202/update/upload.php?case=2" "$OUT/up-notok2.html" token=
flash "$OUT/up-notok2.html" 'Your session expired before the report was applied.' "applying without the session token is refused in words"
eq "$(rows $C3)" "0" "and records nothing"
submit "$OUT/up-pick.html" click_id "tracking202/update/upload.php?case=2" "$OUT/up-apply.html"
flash "$OUT/up-apply.html" 'Your report has been uploaded: 2 line(s) recorded.' "the picker's own fields apply the report"
has "$OUT/up-apply.html" '$3.25' "the subid's income is the sum of its lines"
eq "$(Q "SELECT CONCAT(click_lead, '/', click_payout) FROM 202_clicks WHERE click_id=$C3")" "1/3.25000" "C3 is worth \$2.25 + \$1.00"
submit "$OUT/up-pick.html" click_id "tracking202/update/upload.php?case=2" "$OUT/up-twice.html"
flash "$OUT/up-twice.html" 'This file does not exist that you are trying to import' "applying the same file twice is refused: it was used up"
eq "$(rows $C3)" "2" "and records nothing more"

if [ -n "$SERVER_LOG" ] && [ -f "$SERVER_LOG" ]; then
  say "the server logged no PHP warnings from the Update pages"
  NOISE=$(tail -n +"$((LOG_START+1))" "$SERVER_LOG" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' | grep -cE '/tracking202/update/|update_ui.php|setup_ui.php')
  eq "$NOISE" "0" "no PHP warning, notice or fatal from the Update pages in $SERVER_LOG since the pass began"
fi

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" = 0 ]
