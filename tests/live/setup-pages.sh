#!/bin/bash
# Live pass for the Setup family (U4): every form driven over HTTP, and the
# database read after every step.
#
# A form that looks right and posts the wrong field is the failure a
# screenshot cannot see, so each step here does three things: it reads the
# rendered page and asserts the fields it is about to post are really in the
# form (under the names the handler reads), it posts them, and it reads the
# row the handler wrote. Refusals are asserted positively — the sentence the
# handler says, next to its field — and by the row that was NOT written: "not
# succeeded" is not "refused" (CLAUDE.md, Verify your assumptions).
#
# --- environment -------------------------------------------------------
# P202_DB must be a SCRATCH database: this pass TRUNCATEs every setup table.
# The guard below refuses a name that does not read as disposable.
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

JAR=$(mktemp)
OUT=$(mktemp -d)
PASS=0; FAIL=0
Q() { mysql_q -N "$DB" -e "$1"; }

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
# What the page said: flashes and the sentences under fields.
msgs() { grep -oE '<div class="p202-flash__body">[^<]*|<div class="invalid-feedback[^"]*">[^<]*' "$1" \
           | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }

# The sentence under a field: the invalid-feedback that follows the control
# carrying id="$2". Asserting the sentence is merely on the page would pass
# with the message in a flash at the top; the standard puts it by its field.
field_error() { # file id sentence label
  if python3 - "$1" "$2" "$3" <<'PY'
import html as entities, re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
field, sentence = sys.argv[2], sys.argv[3]
m = re.search(r'<(input|select|textarea)[^>]*\bid="' + re.escape(field) + r'"[^>]*>', html)
if not m:
    sys.exit(1)
control = m.group(0)
if 'is-invalid' not in control:
    sys.exit(2)
rest = html[m.end():m.end() + 4000]
fb = re.search(r'<div class="invalid-feedback[^"]*">([^<]*)</div>', rest)
sys.exit(0 if fb and sentence in entities.unescape(fb.group(1)) else 3)
PY
  then ok "$4"; else bad "$4 (no '$3' under #$2)"; fi
}

# The form that posts carries the field: the named control sits between the
# <form> that contains marker $2 and its </form>.
form_has() { # file marker name label
  if python3 - "$1" "$2" "$3" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
marker, name = sys.argv[2], sys.argv[3]
for m in re.finditer(r'<form\b[^>]*>(.*?)</form>', html, re.S):
    body = m.group(0)
    if marker in body and re.search(r'name="' + re.escape(name) + r'"', body):
        sys.exit(0)
sys.exit(1)
PY
  then ok "$4"; else bad "$4"; fi
}

# The page's shell and PHP hygiene, for every page this pass loads.
clean() { # file label
  hasnt "$1" "Fatal error" "$2: no PHP fatal"
  hasnt "$1" "Warning:" "$2: no PHP warning"
  hasnt "$1" "Notice:" "$2: no PHP notice"
  hasnt "$1" "Deprecated:" "$2: no PHP deprecation"
  has "$1" "p202-shell-v2" "$2: renders on the v2 shell"
}

SETUP="$BASE/tracking202/setup"
AJAX="$BASE/tracking202/ajax"
get() { curl -sS -b "$JAR" -c "$JAR" -L "$1" -o "$2" -w '%{http_code}' > "$OUT/.code"; }
token_of() { grep -oE 'name="token" value="[^"]+"' "$1" | head -1 | sed 's/.*value="//; s/"//'; }
# POST a form. -w reports the redirect count, so a PRG success (1 redirect)
# and an error re-render (0) are told apart rather than hidden by -L.
post() { # url out args...
  local url="$1" out="$2"; shift 2
  curl -sS -b "$JAR" -c "$JAR" -L "$url" "$@" -o "$out" \
    -w "%{num_redirects} %{http_code}" > "$OUT/.w"
  LAST_REDIRECTS=$(cut -d' ' -f1 "$OUT/.w"); LAST_CODE=$(cut -d' ' -f2 "$OUT/.w")
  printf '    redirects=%s final=%s\n' "$LAST_REDIRECTS" "$LAST_CODE"
}
# An AJAX endpoint: no redirects followed, the status and body kept.
ajax() { # url out args...
  local url="$1" out="$2"; shift 2
  curl -sS -b "$JAR" -c "$JAR" "$url" "$@" -o "$out" -w '%{http_code}' > "$OUT/.code"
  LAST_CODE=$(cat "$OUT/.code")
}

mysql_q "$DB" -e "TRUNCATE 202_aff_networks; TRUNCATE 202_aff_campaigns; TRUNCATE 202_ppc_networks;
  TRUNCATE 202_ppc_accounts; TRUNCATE 202_ppc_account_pixels; TRUNCATE 202_ppc_network_variables;
  TRUNCATE 202_landing_pages; TRUNCATE 202_text_ads; TRUNCATE 202_rotators; TRUNCATE 202_rotator_rules;
  TRUNCATE 202_rotator_rules_criteria; TRUNCATE 202_rotator_rules_redirects; TRUNCATE 202_trackers;"
OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(token_of "$OUT/login.html")
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "$BASE/tracking202/setup/" "$OUT/home.html"
hasnt "$OUT/home.html" 'name="user_pass"' "session established"

# ─── Every page renders ─────────────────────────────────────────────────
say "every page renders on the v2 shell, with no PHP noise"
for page in aff_networks ppc_accounts aff_campaigns landing_pages text_ads rotator get_trackers \
            get_postback get_simple_landing_code get_adv_landing_code get_dynamic_smart_component_code; do
  get "$SETUP/$page.php" "$OUT/$page.html"
  eq "$(cat "$OUT/.code")" "200" "$page answers 200"
  clean "$OUT/$page.html" "$page"
done
get "$SETUP/" "$OUT/index.html"
has "$OUT/index.html" 'id="ppc_network_name"' "the Setup index lands on Traffic Sources"
get "$SETUP/get_landing_code.php" "$OUT/glc.html"
has "$OUT/glc.html" 'get_simple_landing_code' "Get LP Code lands on the simple code page"

# ─── Campaign categories ────────────────────────────────────────────────
say "categories: the form posts what the handler reads"
P="$OUT/aff_networks.html"
form_has "$P" 'aff_network_name' token "the category form carries the session token"
form_has "$P" 'id="aff_network_name"' aff_network_name "and the name field"
has "$P" "p202-empty" "an empty account shows the empty state"

say "categories: a bad token writes nothing and says why"
post "$SETUP/aff_networks.php" "$OUT/n-csrf.html" --data-urlencode "token=wrong" --data-urlencode "aff_network_name=Forged"
msgs "$OUT/n-csrf.html"
has "$OUT/n-csrf.html" "Invalid token, please reload the page and try again." "the token refusal is said"
eq "$(Q 'SELECT COUNT(*) FROM 202_aff_networks')" "0" "no category was written"

say "categories: an empty name is refused under its field"
get "$SETUP/aff_networks.php" "$P"; T=$(token_of "$P")
post "$SETUP/aff_networks.php" "$OUT/n-empty.html" --data-urlencode "token=$T" --data-urlencode "aff_network_name=  "
msgs "$OUT/n-empty.html"
field_error "$OUT/n-empty.html" aff_network_name "Type in the name of your campaign's category." "the sentence sits under the name field"
eq "$(Q 'SELECT COUNT(*) FROM 202_aff_networks')" "0" "still no category"

say "categories: create, edit, delete"
post "$SETUP/aff_networks.php" "$OUT/n-add.html" --data-urlencode "token=$T" --data-urlencode "aff_network_name=EVAL Offer Network"
msgs "$OUT/n-add.html"
eq "$LAST_REDIRECTS" "1" "a successful add redirects (post-redirect-get)"
eq "$(Q "SELECT COUNT(*) FROM 202_aff_networks WHERE aff_network_name='EVAL Offer Network' AND user_id=$OWNER AND aff_network_deleted=0")" "1" "the category row was written"
has "$OUT/n-add.html" "EVAL Offer Network" "the list shows it"
NET=$(Q "SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='EVAL Offer Network'")
get "$SETUP/aff_networks.php?edit_aff_network_id=$NET" "$P"; T=$(token_of "$P")
has "$P" 'value="EVAL Offer Network"' "the edit form is filled"
post "$SETUP/aff_networks.php?edit_aff_network_id=$NET" "$OUT/n-edit.html" --data-urlencode "token=$T" --data-urlencode "aff_network_name=EVAL Network Renamed"
eq "$(Q "SELECT aff_network_name FROM 202_aff_networks WHERE aff_network_id=$NET")" "EVAL Network Renamed" "the edit was saved to the same row"
eq "$(Q 'SELECT COUNT(*) FROM 202_aff_networks')" "1" "and no second row appeared"
post "$SETUP/aff_networks.php" "$OUT/n-add2.html" --data-urlencode "token=$T" --data-urlencode "aff_network_name=Doomed Network"
DOOMED=$(Q "SELECT aff_network_id FROM 202_aff_networks WHERE aff_network_name='Doomed Network'")
get "$SETUP/aff_networks.php?delete_aff_network_id=$DOOMED&delete_aff_network_name=Doomed&token=wrong" "$OUT/n-del-csrf.html"
eq "$(Q "SELECT aff_network_deleted FROM 202_aff_networks WHERE aff_network_id=$DOOMED")" "0" "a delete with a bad token deletes nothing"
get "$SETUP/aff_networks.php" "$P"; T=$(token_of "$P")
has "$P" "delete_aff_network_id" "the list offers remove"
get "$SETUP/aff_networks.php?delete_aff_network_id=$DOOMED&delete_aff_network_name=Doomed&token=$T" "$OUT/n-del.html"
msgs "$OUT/n-del.html"
eq "$(Q "SELECT aff_network_deleted FROM 202_aff_networks WHERE aff_network_id=$DOOMED")" "1" "remove soft-deletes the category"

# ─── Traffic sources, accounts, pixels, variables ───────────────────────
say "traffic sources: the add form carries the token (it did not before U4)"
P="$OUT/ppc_accounts.html"
get "$SETUP/ppc_accounts.php" "$P"; T=$(token_of "$P")
form_has "$P" 'id="ppc_network_name"' token "the traffic-source form carries the session token"
post "$SETUP/ppc_accounts.php" "$OUT/s-csrf.html" --data-urlencode "token=wrong" --data-urlencode "ppc_network_name=Forged Source"
has "$OUT/s-csrf.html" "Invalid or expired form token" "a bad token is refused in words"
eq "$(Q 'SELECT COUNT(*) FROM 202_ppc_networks')" "0" "and writes nothing"
post "$SETUP/ppc_accounts.php" "$OUT/s-empty.html" --data-urlencode "token=$T" --data-urlencode "ppc_network_name="
field_error "$OUT/s-empty.html" ppc_network_name "Type in the name the traffic source." "an empty source name is refused under its field"
post "$SETUP/ppc_accounts.php" "$OUT/s-add.html" --data-urlencode "token=$T" --data-urlencode "ppc_network_name=EVAL Search Ads"
msgs "$OUT/s-add.html"
eq "$(Q "SELECT COUNT(*) FROM 202_ppc_networks WHERE ppc_network_name='EVAL Search Ads' AND user_id=$OWNER")" "1" "the traffic source was written"
SRC=$(Q "SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_name='EVAL Search Ads'")

say "traffic sources: an account with a pixel"
get "$SETUP/ppc_accounts.php" "$P"; T=$(token_of "$P")
for f in ppc_network_id ppc_account_name do_edit_ppc_account 'pixel_type_id[]' 'pixel_code[]' 'pixel_id[]' token; do
  form_has "$P" 'id="ppc_account_name"' "$f" "the account form posts $f"
done
post "$SETUP/ppc_accounts.php" "$OUT/a-noname.html" --data-urlencode "token=$T" --data-urlencode "ppc_network_id=$SRC" \
  --data-urlencode "ppc_account_name=" --data-urlencode "do_edit_ppc_account=1" \
  --data-urlencode "pixel_type_id[]=" --data-urlencode "pixel_code[]=" --data-urlencode "pixel_id[]="
field_error "$OUT/a-noname.html" ppc_account_name "What is the username for this account?" "an unnamed account is refused under its field"
eq "$(Q 'SELECT COUNT(*) FROM 202_ppc_accounts')" "0" "no account written"
post "$SETUP/ppc_accounts.php" "$OUT/a-add.html" --data-urlencode "token=$T" --data-urlencode "ppc_network_id=$SRC" \
  --data-urlencode "ppc_account_name=eval-account-1" --data-urlencode "do_edit_ppc_account=1" \
  --data-urlencode "pixel_type_id[]=1" --data-urlencode "pixel_code[]=https://pixel.example/p.gif" --data-urlencode "pixel_id[]="
eq "$(Q "SELECT COUNT(*) FROM 202_ppc_accounts WHERE ppc_account_name='eval-account-1' AND ppc_network_id=$SRC")" "1" "the account was written under its source"
ACC=$(Q "SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_name='eval-account-1'")
eq "$(Q "SELECT CONCAT(pixel_type_id,'|',pixel_code) FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")" "1|https://pixel.example/p.gif" "and its pixel, type and code"
PIX=$(Q "SELECT pixel_id FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")
get "$SETUP/ppc_accounts.php?edit_ppc_account_id=$ACC" "$P"; T=$(token_of "$P")
has "$P" 'https://pixel.example/p.gif' "editing the account shows its pixel"
has "$P" "value=\"$PIX\"" "with the pixel id, so a save updates rather than duplicates it"
post "$SETUP/ppc_accounts.php?edit_ppc_account_id=$ACC" "$OUT/a-edit.html" --data-urlencode "token=$T" --data-urlencode "ppc_network_id=$SRC" \
  --data-urlencode "ppc_account_name=eval-account-renamed" --data-urlencode "do_edit_ppc_account=1" \
  --data-urlencode "pixel_type_id[]=1" --data-urlencode "pixel_code[]=https://pixel.example/p2.gif" --data-urlencode "pixel_id[]=$PIX"
eq "$(Q "SELECT ppc_account_name FROM 202_ppc_accounts WHERE ppc_account_id=$ACC")" "eval-account-renamed" "the account edit was saved"
eq "$(Q "SELECT COUNT(*) FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")" "1" "the pixel was updated in place"
eq "$(Q "SELECT pixel_code FROM 202_ppc_account_pixels WHERE ppc_account_id=$ACC")" "https://pixel.example/p2.gif" "with its new code"

say "traffic sources: custom variables (a token is required now)"
ajax "$AJAX/custom_variables.php" "$OUT/v-csrf.txt" --data-urlencode "post_vars=1" --data-urlencode "ppc_network_id=$SRC" \
  --data-urlencode "vars[0][id]=false" --data-urlencode "vars[0][name]=Forged" --data-urlencode "vars[0][parameter]=f" --data-urlencode "vars[0][placeholder]={f}"
eq "$LAST_CODE" "403" "a variables save without the token is answered 403"
has "$OUT/v-csrf.txt" "Invalid token" "and says why"
eq "$(Q 'SELECT COUNT(*) FROM 202_ppc_network_variables')" "0" "and writes nothing"
ajax "$AJAX/custom_variables.php" "$OUT/v-ok.txt" --data-urlencode "token=$T" --data-urlencode "post_vars=1" --data-urlencode "ppc_network_id=$SRC" \
  --data-urlencode "vars[0][id]=false" --data-urlencode "vars[0][name]=Keyword" --data-urlencode "vars[0][parameter]=kw" --data-urlencode "vars[0][placeholder]={keyword}"
has "$OUT/v-ok.txt" "DONE" "a variables save with the token is accepted"
eq "$(Q "SELECT CONCAT(name,'|',parameter,'|',placeholder) FROM 202_ppc_network_variables WHERE ppc_network_id=$SRC AND deleted=0")" "Keyword|kw|{keyword}" "the variable row was written"
get "$SETUP/ppc_accounts.php" "$P"
has "$P" '{keyword}' "the page carries the saved variable for the editor"

# ─── Campaigns ──────────────────────────────────────────────────────────
say "campaigns: the form posts what the handler reads"
P="$OUT/aff_campaigns.html"
get "$SETUP/aff_campaigns.php" "$P"; T=$(token_of "$P")
for f in token aff_campaign_id aff_network_id aff_campaign_name aff_campaign_url aff_campaign_payout aff_campaign_cloaking dni_id dni_offer_id; do
  form_has "$P" 'id="aff_campaign_name"' "$f" "the campaign form posts $f"
done
post "$SETUP/aff_campaigns.php" "$OUT/c-bad.html" --data-urlencode "token=$T" --data-urlencode "aff_campaign_id=" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_name=EVAL Campaign A" \
  --data-urlencode "aff_campaign_url=example.com/offer" --data-urlencode "aff_campaign_payout=abc" --data-urlencode "aff_campaign_cloaking=0"
msgs "$OUT/c-bad.html"
field_error "$OUT/c-bad.html" aff_campaign_payout "Please enter in a numeric number for the payout." "a non-numeric payout is refused under its field"
field_error "$OUT/c-bad.html" aff_campaign_url "must start with http:// or https://" "a scheme-less URL is refused under its field"
has "$OUT/c-bad.html" 'value="EVAL Campaign A"' "what was typed is kept"
eq "$(Q 'SELECT COUNT(*) FROM 202_aff_campaigns')" "0" "no campaign was written"
post "$SETUP/aff_campaigns.php" "$OUT/c-csrf.html" --data-urlencode "token=wrong" --data-urlencode "aff_campaign_id=" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_name=Forged" \
  --data-urlencode "aff_campaign_url=https://example.com/" --data-urlencode "aff_campaign_payout=1" --data-urlencode "aff_campaign_cloaking=0"
has "$OUT/c-csrf.html" "Invalid or expired form token" "a bad token is refused in words"
eq "$(Q 'SELECT COUNT(*) FROM 202_aff_campaigns')" "0" "and writes nothing"
post "$SETUP/aff_campaigns.php" "$OUT/c-add.html" --data-urlencode "token=$T" --data-urlencode "aff_campaign_id=" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_name=EVAL Campaign A" \
  --data-urlencode "aff_campaign_url=https://offer.example/?sub=[[subid]]" --data-urlencode "aff_campaign_payout=12.50" \
  --data-urlencode "aff_campaign_cloaking=1" --data-urlencode "dni_id=" --data-urlencode "dni_offer_id="
msgs "$OUT/c-add.html"
eq "$LAST_REDIRECTS" "1" "a successful add redirects"
eq "$(Q "SELECT CONCAT(aff_network_id,'|',aff_campaign_url,'|',aff_campaign_payout,'|',aff_campaign_cloaking) FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'")" \
   "$NET|https://offer.example/?sub=[[subid]]|12.50|1" "category, URL, payout and cloaking were stored as posted"
CAMP=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='EVAL Campaign A'")
eq "$(Q "SELECT aff_campaign_id_public > 0 FROM 202_aff_campaigns WHERE aff_campaign_id=$CAMP")" "1" "the campaign got its public id"
get "$SETUP/aff_campaigns.php?edit_aff_campaign_id=$CAMP" "$P"; T=$(token_of "$P")
has "$P" 'value="EVAL Campaign A"' "the edit form is filled"
post "$SETUP/aff_campaigns.php?edit_aff_campaign_id=$CAMP" "$OUT/c-edit.html" --data-urlencode "token=$T" --data-urlencode "aff_campaign_id=$CAMP" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_name=EVAL Campaign A" \
  --data-urlencode "aff_campaign_url=https://offer.example/?sub=[[subid]]" --data-urlencode "aff_campaign_payout=15" --data-urlencode "aff_campaign_cloaking=0"
eq "$(Q "SELECT CONCAT(aff_campaign_payout,'|',aff_campaign_cloaking) FROM 202_aff_campaigns WHERE aff_campaign_id=$CAMP")" "15.00|0" "the edit saved payout and cloaking"
get "$SETUP/aff_campaigns.php?copy_aff_campaign_id=$CAMP" "$P"; T=$(token_of "$P")
has "$P" 'value="EVAL Campaign A (Copy)"' "copy prefills a named copy"
post "$SETUP/aff_campaigns.php?copy_aff_campaign_id=$CAMP" "$OUT/c-copy.html" --data-urlencode "token=$T" --data-urlencode "aff_campaign_id=$CAMP" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_name=Doomed Campaign" \
  --data-urlencode "aff_campaign_url=https://offer.example/b" --data-urlencode "aff_campaign_payout=4" --data-urlencode "aff_campaign_cloaking=0"
eq "$(Q 'SELECT COUNT(*) FROM 202_aff_campaigns WHERE aff_campaign_deleted=0')" "2" "the copy is a second campaign, not an edit"
DCAMP=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_name='Doomed Campaign'")
get "$SETUP/aff_campaigns.php" "$P"; T=$(token_of "$P")
get "$SETUP/aff_campaigns.php?delete_aff_campaign_id=$DCAMP&token=$T" "$OUT/c-del.html"
eq "$(Q "SELECT aff_campaign_deleted FROM 202_aff_campaigns WHERE aff_campaign_id=$DCAMP")" "1" "remove soft-deletes the campaign"

# ─── Landing pages ──────────────────────────────────────────────────────
say "landing pages: simple and advanced"
P="$OUT/landing_pages.html"
get "$SETUP/landing_pages.php" "$P"; T=$(token_of "$P")
for f in token landing_page_id landing_page_type aff_campaign_id landing_page_nickname landing_page_url; do
  form_has "$P" 'id="landing_page_nickname"' "$f" "the landing-page form posts $f"
done
has "$P" "value=\"$CAMP\"" "the campaign list offers the campaign"
post "$SETUP/landing_pages.php" "$OUT/l-bad.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=" \
  --data-urlencode "landing_page_type=0" --data-urlencode "aff_campaign_id=$CAMP" \
  --data-urlencode "landing_page_nickname=EVAL LP" --data-urlencode "landing_page_url="
field_error "$OUT/l-bad.html" landing_page_url "What is the URL of your landing page?" "a missing URL is refused under its field"
eq "$(Q 'SELECT COUNT(*) FROM 202_landing_pages')" "0" "no landing page written"
post "$SETUP/landing_pages.php" "$OUT/l-add.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=" \
  --data-urlencode "landing_page_type=0" --data-urlencode "aff_campaign_id=$CAMP" \
  --data-urlencode "landing_page_nickname=EVAL LP" --data-urlencode "landing_page_url=https://lp.example/quiz"
eq "$(Q "SELECT CONCAT(aff_campaign_id,'|',landing_page_type,'|',landing_page_url) FROM 202_landing_pages WHERE landing_page_nickname='EVAL LP'")" \
   "$CAMP|0|https://lp.example/quiz" "the simple landing page was written under its campaign"
LP=$(Q "SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_nickname='EVAL LP'")
post "$SETUP/landing_pages.php" "$OUT/l-adv.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=" \
  --data-urlencode "landing_page_type=1" --data-urlencode "aff_campaign_id=" \
  --data-urlencode "landing_page_nickname=EVAL Advanced LP" --data-urlencode "landing_page_url=https://lp.example/adv"
ALP=$(Q "SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_nickname='EVAL Advanced LP' AND landing_page_type=1")
eq "$([ -n "$ALP" ] && echo yes)" "yes" "the advanced landing page was written"
get "$SETUP/landing_pages.php?edit_landing_page_id=$LP" "$P"; T=$(token_of "$P")
has "$P" 'value="EVAL LP"' "the edit form is filled"
post "$SETUP/landing_pages.php?edit_landing_page_id=$LP" "$OUT/l-edit.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=$LP" \
  --data-urlencode "landing_page_type=0" --data-urlencode "aff_campaign_id=$CAMP" \
  --data-urlencode "landing_page_nickname=EVAL LP Renamed" --data-urlencode "landing_page_url=https://lp.example/quiz?v=2"
eq "$(Q "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id=$LP")" "EVAL LP Renamed" "the edit saved to the same row"
post "$SETUP/landing_pages.php" "$OUT/l-add3.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=" \
  --data-urlencode "landing_page_type=0" --data-urlencode "aff_campaign_id=$CAMP" \
  --data-urlencode "landing_page_nickname=Doomed LP" --data-urlencode "landing_page_url=https://lp.example/doomed"
DLP=$(Q "SELECT landing_page_id FROM 202_landing_pages WHERE landing_page_nickname='Doomed LP'")
get "$SETUP/landing_pages.php" "$P"; T=$(token_of "$P")
get "$SETUP/landing_pages.php?delete_landing_page_id=$DLP&delete_landing_page_name=Doomed&delete_landing_page_type=0&token=$T" "$OUT/l-del.html"
eq "$(Q "SELECT landing_page_deleted FROM 202_landing_pages WHERE landing_page_id=$DLP")" "1" "remove soft-deletes the landing page"

# ─── Text ads ───────────────────────────────────────────────────────────
say "text ads"
P="$OUT/text_ads.html"
get "$SETUP/text_ads.php" "$P"; T=$(token_of "$P")
for f in token text_ad_id text_ad_type aff_campaign_id landing_page_id text_ad_name text_ad_headline text_ad_description text_ad_display_url; do
  form_has "$P" 'id="text_ad_name"' "$f" "the text-ad form posts $f"
done
post "$SETUP/text_ads.php" "$OUT/t-bad.html" --data-urlencode "token=$T" --data-urlencode "text_ad_id=" --data-urlencode "text_ad_type=0" \
  --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "landing_page_id=0" --data-urlencode "text_ad_name=EVAL Ad" \
  --data-urlencode "text_ad_headline=" --data-urlencode "text_ad_description=Save big" --data-urlencode "text_ad_display_url=example.com"
field_error "$OUT/t-bad.html" text_ad_headline "What is your ad headline?" "a missing headline is refused under its field"
eq "$(Q 'SELECT COUNT(*) FROM 202_text_ads')" "0" "no ad written"
post "$SETUP/text_ads.php" "$OUT/t-add.html" --data-urlencode "token=$T" --data-urlencode "text_ad_id=" --data-urlencode "text_ad_type=0" \
  --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "landing_page_id=0" --data-urlencode "text_ad_name=EVAL Ad" \
  --data-urlencode "text_ad_headline=Cruise to Mars" --data-urlencode "text_ad_description=Save big" --data-urlencode "text_ad_display_url=example.com"
eq "$(Q "SELECT CONCAT(aff_campaign_id,'|',text_ad_type,'|',text_ad_headline) FROM 202_text_ads WHERE text_ad_name='EVAL Ad'")" "$CAMP|0|Cruise to Mars" "the text ad was written under its campaign"
AD=$(Q "SELECT text_ad_id FROM 202_text_ads WHERE text_ad_name='EVAL Ad'")
post "$SETUP/text_ads.php" "$OUT/t-adv.html" --data-urlencode "token=$T" --data-urlencode "text_ad_id=" --data-urlencode "text_ad_type=1" \
  --data-urlencode "aff_campaign_id=" --data-urlencode "landing_page_id=$ALP" --data-urlencode "text_ad_name=EVAL Adv Ad" \
  --data-urlencode "text_ad_headline=Pick one" --data-urlencode "text_ad_description=Several offers" --data-urlencode "text_ad_display_url=example.com/adv"
eq "$(Q "SELECT CONCAT(landing_page_id,'|',text_ad_type) FROM 202_text_ads WHERE text_ad_name='EVAL Adv Ad'")" "$ALP|1" "the advanced-LP ad was written under its landing page"
get "$SETUP/text_ads.php?edit_text_ad_id=$AD" "$P"; T=$(token_of "$P")
post "$SETUP/text_ads.php?edit_text_ad_id=$AD" "$OUT/t-edit.html" --data-urlencode "token=$T" --data-urlencode "text_ad_id=$AD" --data-urlencode "text_ad_type=0" \
  --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "landing_page_id=0" --data-urlencode "text_ad_name=EVAL Ad" \
  --data-urlencode "text_ad_headline=Cruise to Mars, now" --data-urlencode "text_ad_description=Save big" --data-urlencode "text_ad_display_url=example.com"
eq "$(Q "SELECT text_ad_headline FROM 202_text_ads WHERE text_ad_id=$AD")" "Cruise to Mars, now" "the edit saved to the same row"
get "$SETUP/text_ads.php" "$P"; T=$(token_of "$P")
DAD=$(Q "SELECT text_ad_id FROM 202_text_ads WHERE text_ad_name='EVAL Adv Ad'")
get "$SETUP/text_ads.php?delete_text_ad_id=$DAD&delete_text_ad_name=x&token=$T" "$OUT/t-del.html"
eq "$(Q "SELECT text_ad_deleted FROM 202_text_ads WHERE text_ad_id=$DAD")" "1" "remove soft-deletes the ad"

# ─── Rotators ───────────────────────────────────────────────────────────
say "rotators: a redirector with a rule"
P="$OUT/rotator.html"
get "$SETUP/rotator.php" "$P"; T=$(token_of "$P")
form_has "$P" 'id="rotator_name"' token "the redirector form carries the token"
post "$SETUP/rotator.php" "$OUT/r-empty.html" --data-urlencode "token=$T" --data-urlencode "rotator_name="
field_error "$OUT/r-empty.html" rotator_name "Type in the name of your rotator!" "an empty name is refused under its field"
eq "$(Q 'SELECT COUNT(*) FROM 202_rotators')" "0" "no redirector written"
post "$SETUP/rotator.php" "$OUT/r-add.html" --data-urlencode "token=$T" --data-urlencode "rotator_name=EVAL Redirector"
ROT=$(Q "SELECT id FROM 202_rotators WHERE name='EVAL Redirector' AND user_id=$OWNER")
eq "$([ -n "$ROT" ] && echo yes)" "yes" "the redirector was written"
get "$SETUP/rotator.php?rotator_id=$ROT" "$P"; T=$(token_of "$P")
has "$P" 'data-rule-id="none"' "choosing it opens the rules editor with a first, empty rule"
RULE_ARGS=(--data-urlencode "post_rules=1" --data-urlencode "rotator_id=$ROT" --data-urlencode "default_type=campaign" --data-urlencode "defaults=$CAMP"
  --data-urlencode "data[0][rule_id]=none" --data-urlencode "data[0][rule_name]=US mobile" --data-urlencode "data[0][status]=active" --data-urlencode "data[0][split]=false"
  --data-urlencode "data[0][redirects][0][id]=none" --data-urlencode "data[0][redirects][0][type]=url" --data-urlencode "data[0][redirects][0][value]=https://mobile.example/"
  --data-urlencode "data[0][criteria][0][criteria_id]=none" --data-urlencode "data[0][criteria][0][type]=country" --data-urlencode "data[0][criteria][0][statement]=is" --data-urlencode "data[0][criteria][0][value]=US,GB")
ajax "$AJAX/rotator.php" "$OUT/r-csrf.txt" --data-urlencode "token=wrong" "${RULE_ARGS[@]}"
has "$OUT/r-csrf.txt" "ERROR" "saving rules with a bad token is refused"
eq "$(Q "SELECT COUNT(*) FROM 202_rotator_rules WHERE rotator_id=$ROT")" "0" "and writes no rule"
ajax "$AJAX/rotator.php" "$OUT/r-rules.txt" --data-urlencode "token=$T" "${RULE_ARGS[@]}"
has "$OUT/r-rules.txt" "DONE" "saving rules with the token is accepted"
eq "$(Q "SELECT default_campaign FROM 202_rotators WHERE id=$ROT")" "$CAMP" "the default campaign was stored"
eq "$(Q "SELECT CONCAT(rule_name,'|',status,'|',splittest) FROM 202_rotator_rules WHERE rotator_id=$ROT")" "US mobile|1|0" "the rule was stored"
eq "$(Q "SELECT CONCAT(type,'|',statement,'|',value) FROM 202_rotator_rules_criteria WHERE rotator_id=$ROT")" "country|is|US,GB" "its criterion was stored"
RULE=$(Q "SELECT id FROM 202_rotator_rules WHERE rotator_id=$ROT")
eq "$(Q "SELECT redirect_url FROM 202_rotator_rules_redirects WHERE rule_id=$RULE")" "https://mobile.example/" "its redirect was stored"
get "$SETUP/rotator.php?rotator_id=$ROT" "$P"
has "$P" "data-rule-id=\"$RULE\"" "the editor reloads the saved rule"
has "$P" 'value="US,GB"' "with its criterion's values"
has "$P" 'value="https://mobile.example/"' "and its redirect"

# ─── Trackers ───────────────────────────────────────────────────────────
say "trackers: generate, list, delete"
P="$OUT/get_trackers.html"
get "$SETUP/get_trackers.php" "$P"; T=$(token_of "$P")
for f in tracker_type aff_network_id aff_campaign_id method_of_promotion landing_page_id text_ad_id click_cloaking tracker_rotator ppc_network_id ppc_account_id cost_type cpc_dollars cpc_cents cpa_dollars cpa_cents t202kw t202b t202ref c1 c2 c3 c4; do
  form_has "$P" 'id="tracking_form"' "$f" "the tracker form posts $f"
done
ajax "$AJAX/generate_tracking_link.php" "$OUT/k-csrf.html" --data-urlencode "token=wrong" --data-urlencode "tracker_type=0"
eq "$LAST_CODE" "403" "generating without the token is refused"
eq "$(Q 'SELECT COUNT(*) FROM 202_trackers')" "0" "and writes nothing"
ajax "$AJAX/generate_tracking_link.php" "$OUT/k-dl.html" --data-urlencode "token=$T" --data-urlencode "tracker_type=0" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=directlink" \
  --data-urlencode "landing_page_id=0" --data-urlencode "text_ad_id=$AD" --data-urlencode "click_cloaking=-1" \
  --data-urlencode "ppc_network_id=$SRC" --data-urlencode "ppc_account_id=$ACC" --data-urlencode "cost_type=cpc" \
  --data-urlencode "cpc_dollars=0" --data-urlencode "cpc_cents=25" --data-urlencode "c1=spring"
has "$OUT/k-dl.html" "tracking202/redirect/dl.php?t202id=" "a direct-link tracker answers with its dl.php link"
has "$OUT/k-dl.html" "c1=spring" "carrying c1"
has "$OUT/k-dl.html" "p202-code" "in the v2 code box"
eq "$(Q "SELECT CONCAT(aff_campaign_id,'|',text_ad_id,'|',ppc_account_id,'|',click_cpc,'|',landing_page_id) FROM 202_trackers")" "$CAMP|$AD|$ACC|0.25000|0" "the tracker row holds what was posted"
ajax "$AJAX/generate_tracking_link.php" "$OUT/k-lp.html" --data-urlencode "token=$T" --data-urlencode "tracker_type=0" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=landingpage" \
  --data-urlencode "landing_page_id=$LP" --data-urlencode "click_cloaking=-1" --data-urlencode "cost_type=cpa" --data-urlencode "cpa_dollars=3" --data-urlencode "cpa_cents=00"
has "$OUT/k-lp.html" "lp.example/quiz" "a landing-page tracker answers with the landing page URL"
ajax "$AJAX/generate_tracking_link.php" "$OUT/k-lpmissing.html" --data-urlencode "token=$T" --data-urlencode "tracker_type=0" \
  --data-urlencode "aff_network_id=$NET" --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=landingpage" --data-urlencode "landing_page_id=0"
has "$OUT/k-lpmissing.html" "You have not selected a landing page to use." "a landing-page tracker with no page is refused in words"
ajax "$AJAX/generate_tracking_link.php" "$OUT/k-rtr.html" --data-urlencode "token=$T" --data-urlencode "tracker_type=2" \
  --data-urlencode "tracker_rotator=$ROT" --data-urlencode "cost_type=cpc" --data-urlencode "cpc_dollars=0" --data-urlencode "cpc_cents=10"
has "$OUT/k-rtr.html" "tracking202/redirect/rtr.php?t202id=" "a redirector tracker answers with its rtr.php link"
eq "$(Q 'SELECT COUNT(*) FROM 202_trackers')" "3" "three trackers stored"
get "$SETUP/get_trackers.php" "$P"; T=$(token_of "$P")
KID=$(Q "SELECT tracker_id FROM 202_trackers WHERE rotator_id=$ROT")
has "$P" "data-tracker-id=\"$KID\"" "the list shows the redirector tracker"
ajax "$AJAX/delete_tracker.php" "$OUT/k-del-csrf.txt" --data-urlencode "tracker_id=$KID"
eq "$LAST_CODE" "403" "deleting a tracker without the token is answered 403"
eq "$(Q "SELECT COUNT(*) FROM 202_trackers WHERE tracker_id=$KID")" "1" "and deletes nothing"
ajax "$AJAX/delete_tracker.php" "$OUT/k-del.txt" --data-urlencode "token=$T" --data-urlencode "tracker_id=$KID"
eq "$(Q "SELECT COUNT(*) FROM 202_trackers WHERE tracker_id=$KID")" "0" "deleting with the token removes it"
PUB=$(Q "SELECT tracker_id_public FROM 202_trackers WHERE landing_page_id=$LP")
get "$SETUP/get_trackers.php?edit_tracker_id=$PUB" "$P"
has "$P" 'name="edit_tracker"' "editing a tracker carries edit_tracker"
has "$P" "value=\"$PUB\"" "and its public id"

# ─── Landing-page code ──────────────────────────────────────────────────
say "landing-page code"
get "$SETUP/get_simple_landing_code.php" "$P"; T=$(token_of "$P")
for f in aff_network_id aff_campaign_id method_of_promotion landing_page_id; do
  form_has "$P" 'id="tracking_form"' "$f" "the simple code form posts $f"
done
ajax "$AJAX/get_landing_code.php" "$OUT/lc.html" --data-urlencode "token=$T" --data-urlencode "aff_network_id=$NET" \
  --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=landingpage" --data-urlencode "landing_page_id=$LP"
has "$OUT/lc.html" "redirect/go.php?lpip=" "the simple code answers with the outbound link"
has "$OUT/lc.html" "p202-code" "in the v2 code box"
ajax "$AJAX/get_landing_code.php" "$OUT/lc-bad.html" --data-urlencode "token=$T" --data-urlencode "aff_network_id=$NET" \
  --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=landingpage" --data-urlencode "landing_page_id=0"
has "$OUT/lc-bad.html" "You have not selected a landing page to use." "no landing page is refused in words"
get "$SETUP/get_adv_landing_code.php" "$P"; T=$(token_of "$P")
for f in landing_page_id counter offer_type1 aff_campaign_id_1 rotator_id_1; do
  form_has "$P" 'id="tracking_form"' "$f" "the advanced code form posts $f"
done
ajax "$AJAX/get_adv_landing_code.php" "$OUT/alc.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=$ALP" --data-urlencode "counter=1" \
  --data-urlencode "offer_type1=campaign" --data-urlencode "aff_campaign_id_1=$CAMP" --data-urlencode "rotator_id_1=0" \
  --data-urlencode "offer_type2=rotator" --data-urlencode "aff_campaign_id_2=0" --data-urlencode "rotator_id_2=$ROT"
has "$OUT/alc.html" "redirect/go.php?acip=" "the advanced code answers with the campaign offer's outbound link"
has "$OUT/alc.html" "redirect/go.php?rpi=" "and the redirector offer's"
has "$OUT/alc.html" "p202-code" "in the v2 code box"
ajax "$AJAX/get_adv_landing_code.php" "$OUT/alc-bad.html" --data-urlencode "token=$T" --data-urlencode "landing_page_id=$ALP" --data-urlencode "counter=0" \
  --data-urlencode "offer_type1=campaign" --data-urlencode "aff_campaign_id_1=0" --data-urlencode "rotator_id_1=0"
has "$OUT/alc-bad.html" "Please select an affiliate campaign or rotator" "no offer is refused in words"

# ─── Clean-up: the deletes that depend on everything above ─────────────
say "traffic sources: delete the account, then the source"
get "$SETUP/ppc_accounts.php" "$P"; T=$(token_of "$P")
get "$SETUP/ppc_accounts.php?delete_ppc_account_id=$ACC&delete_ppc_account_name=x&token=wrong" "$OUT/a-del-csrf.html"
eq "$(Q "SELECT ppc_account_deleted FROM 202_ppc_accounts WHERE ppc_account_id=$ACC")" "0" "a bad token deletes no account"
get "$SETUP/ppc_accounts.php?delete_ppc_account_id=$ACC&delete_ppc_account_name=x&token=$T" "$OUT/a-del.html"
eq "$(Q "SELECT ppc_account_deleted FROM 202_ppc_accounts WHERE ppc_account_id=$ACC")" "1" "remove soft-deletes the account"
get "$SETUP/ppc_accounts.php?delete_ppc_network_id=$SRC&delete_ppc_network_name=x&token=$T" "$OUT/s-del.html"
eq "$(Q "SELECT ppc_network_deleted FROM 202_ppc_networks WHERE ppc_network_id=$SRC")" "1" "remove soft-deletes the source"

say "rotators: delete"
get "$SETUP/rotator.php" "$P"; T=$(token_of "$P")
get "$SETUP/rotator.php?delete_rotator_id=$ROT&delete_rotator_name=x&token=$T" "$OUT/r-del.html"
eq "$(Q "SELECT COUNT(*) FROM 202_rotators WHERE id=$ROT")" "0" "remove deletes the redirector"
eq "$(Q "SELECT COUNT(*) FROM 202_rotator_rules WHERE rotator_id=$ROT")" "0" "and its rules"

printf '\n\033[1mLive pass: %d passed, %d failed\033[0m  (artifacts: %s)\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" = 0 ]
