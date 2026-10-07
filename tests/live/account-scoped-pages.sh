#!/bin/bash
# Live pass: the legacy pages name nothing of another account (CLAUDE.md #27).
#
# Seeds a second account (user 2, every record named "ZZB ...", URLs on
# zzb.example) and, in account A (user 1, the installer's), Setup records and
# clicks that name B's records by id — what a legacy write left in installs
# (the API refuses that write since 229df10). Then signs in as A and loads
# every page that read one of those ids through an untied join or lookup,
# and reports which of them show any of B's names, URLs or settings.
#
# A page that shows one FAILs and lists what it showed; the run then says how
# many pages leaked. Point it at the base tree to see the leak, at the fixed
# tree to see it closed: the seed is the same.
#
#   P202_BASE=http://127.0.0.1:8119 P202_DB=p202_wj_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_USER=acctA P202_PASS=... \
#   P202_DB_ALLOW_DESTRUCTIVE=yes-i-mean-it bash tests/live/account-scoped-pages.sh
#
# It writes rows with fixed ids (7001-7004 in the Setup tables, clicks
# 700101-700104) and user 2: run it against a scratch instance only.

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
ONLY=${P202_ONLY:-}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
if [ -z "$P202_PASS" ]; then
    echo "P202_PASS must be set: the pass reads the pages as $P202_USER (user 1)." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
JAR="$OUT/jar"
PASS=0; FAIL=0; LEAKED=()
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

say "seed: account B (user 2) and A's rows that name B's records"
if [ "$(Q "SELECT user_id FROM 202_users WHERE user_id = 1")" != "1" ]; then
    echo "no user 1: install the instance first" >&2
    exit 2
fi
mysql "${MYSQL_ARGS[@]}" "$DB" < "$HERE/account-scoped-pages.sql" || exit 2
ok "seeded"

say "login as $P202_USER"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/" -o "$OUT/home.html"
if grep -q 'name="user_pass"' "$OUT/home.html" || ! grep -q '202-account/signout.php' "$OUT/home.html"; then
    bad "session established"
    exit 1
fi
ok "session established"

# page LABEL FILE — what the saved response shows of B: its names, its URLs.
check() {
    local label="$1" file="$2" code="$3" must="${4:-}"
    if [ "$code" != "200" ]; then
        bad "$label: HTTP $code"
        return
    fi
    for e in "Fatal error" "Parse error" "Uncaught" "record_mysql_error" "database error"; do
        if grep -qi "$e" "$file"; then bad "$label: the page shows \"$e\""; return; fi
    done
    if [ -n "$must" ] && ! grep -q -- "$must" "$file"; then
        bad "$label: the page does not show \"$must\" (it did not render what it reads)"
        return
    fi
    local shown
    shown=$(grep -oiE 'ZZB [A-Za-z]+|zzb\.example[^"<> ]*|zzb-[a-z]+|\{zzbv\}|zzbv=' "$file" | sort -u | paste -sd ',' -)
    if [ -n "$shown" ]; then
        bad "$label shows account B's: $shown"
        LEAKED+=("$label")
    else
        ok "$label names nothing of account B"
    fi
}
fname() { printf '%s/%s.html' "$OUT" "$(printf '%s' "$1" | tr -c 'A-Za-z0-9' _)"; }
get() { # LABEL PATH [MUST]
    [ -n "$ONLY" ] && [[ "$1" != *$ONLY* ]] && return
    local f
    f=$(fname "$1")
    local code
    code=$(curl -sS -b "$JAR" -c "$JAR" "$BASE/$2" -o "$f" -w '%{http_code}')
    check "$1" "$f" "$code" "${3:-}"
}
post() { # LABEL PATH DATA [MUST]
    [ -n "$ONLY" ] && [[ "$1" != *$ONLY* ]] && return
    local f
    f=$(fname "$1")
    local code
    code=$(curl -sS -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' --data "$3" "$BASE/$2" -o "$f" -w '%{http_code}')
    check "$1" "$f" "$code" "${4:-}"
}
groups() { Q "UPDATE 202_users_pref SET user_pref_group_1 = $1, user_pref_group_2 = $2, user_pref_group_3 = $3, user_pref_group_4 = $4 WHERE user_id = 1"; }

say "legacy reports (the account's four clicks name B's campaign, category, source, account, page, ad and redirector)"
get "Overview: account overview" "tracking202/ajax/account_overview.php" "A Offer"
groups 4 3 1 2
get "Group Overview: campaign > category > source > account" "tracking202/ajax/group_overview.php" "A Offer"
groups 5 11 32 33
get "Group Overview: landing page > text ad > redirector > rule" "tracking202/ajax/group_overview.php" "A Rotator"
groups 34 0 0 0
get "Group Overview: rule redirect" "tracking202/ajax/group_overview.php"
groups 4 0 0 0
post "Visitors list" "tracking202/ajax/click_history.php" "offset=0" "A Offer"
get "Spy" "tracking202/ajax/click_history.php?spy=1" "A Offer"
get "Visitors download" "tracking202/visitors/download/" "A Offer"
get "Rotator Breakdown" "tracking202/ajax/sort_rotator.php" "A Rotator"
get "Analyze: text ads" "tracking202/analyze/text_ads.php" "A Ad"
get "Analyze: landing pages" "tracking202/analyze/landing_pages.php"
get "Analyze: variables" "tracking202/analyze/variables.php"
get "Analyze: text ads download" "tracking202/analyze/text_ads_download.php" "A Ad"
get "Analyze: landing pages download" "tracking202/analyze/landing_pages_download.php"
get "Analyze: variables download" "tracking202/analyze/variables_download.php"
get "Visitors page (filter menus)" "tracking202/visitors/" "A Account"
get "Group Overview page (filter menus)" "tracking202/overview/group-overview.php" "A Account"

say "Setup and Get Links (A's links, category, account and pages name B's records)"
get "Get Links: the links list" "tracking202/setup/get_trackers.php" "970039"
if [ -z "$ONLY" ] || [[ "Get Links" == *$ONLY* ]]; then
    if grep -qE 't202id=9700[34]9' "$(fname 'Get Links: the links list')"; then
        bad "Get Links offers a link to copy for a link on B's landing page or redirector"
    else
        ok "Get Links offers no link for a link on B's landing page or redirector"
    fi
fi
get "Get Links: editing a link on B's records" "tracking202/setup/get_trackers.php?edit_tracker_id=970039" 'name="tracker_id" value="970039"'
get "Campaigns" "tracking202/setup/aff_campaigns.php" "A Category"
if [ -z "$ONLY" ] || [[ "Campaigns" == *$ONLY* ]]; then
    if grep -q 'data-dni-id="7002"' "$(fname Campaigns)"; then
        bad "Campaigns offers a search of B's network integration (data-dni-id 7002)"
        LEAKED+=("Campaigns (network integration)")
    else
        ok "Campaigns offers no search of B's network integration"
    fi
fi
get "Update CPC" "tracking202/update/cpc.php" "A Account"
get "Landing Pages: editing A's page under B's campaign" "tracking202/setup/landing_pages.php?edit_landing_page_id=7003" "A Stray Page"
get "Text Ads: editing A's ad on B's campaign and page" "tracking202/setup/text_ads.php?edit_text_ad_id=7003" "A Stray Ad"
get "Get Simple LP Code" "tracking202/setup/get_simple_landing_code.php" "A Page"
get "Home (Get Started)" "202-account/"

# The endpoints whose foreign names went only into Slack notices: the
# webhook is pointed at a capture endpoint the operator serves on the
# instance (P202_SLACK_CAPTURE_URL, appending each POST body to
# P202_SLACK_CAPTURE_FILE), and the notices each request sent are read.
if [ -n "${P202_SLACK_CAPTURE_URL:-}" ] && [ -n "${P202_SLACK_CAPTURE_FILE:-}" ]; then
    say "Slack notices (captured at $P202_SLACK_CAPTURE_URL)"
    Q "UPDATE 202_users_pref SET user_slack_incoming_webhook = '$P202_SLACK_CAPTURE_URL' WHERE user_id = 1"
    TOKEN=$(grep -oE 'token: "[0-9a-f]+"' "$OUT/home.html" | head -1 | grep -oE '[0-9a-f]{20,}')
    notices() { # LABEL PATH DATA — the notices one request sent, checked for B's names
        [ -n "$ONLY" ] && [[ "$1" != *$ONLY* ]] && return
        : > "$P202_SLACK_CAPTURE_FILE"
        local f code
        f=$(fname "$1")
        code=$(curl -sS -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' --data "$3&token=$TOKEN" "$BASE/$2" -o "$f" -w '%{http_code}')
        # The notices are form-encoded JSON: decoded, they read as sent.
        php -r 'echo urldecode((string) file_get_contents($argv[1]));' "$P202_SLACK_CAPTURE_FILE" > "$f.slack"
        echo "    sent $(grep -c . "$f.slack") notice(s):"
        grep -oE '"text":"[^"]*"' "$f.slack" | sed 's/^/      /' | cut -c1-200
        check "$1" "$f.slack" "$code"
    }
    # Editing the link on B's records, to A's own campaign, account and
    # landing page: the notices name what the link named before.
    notices "Slack: editing a link on B's records" "tracking202/ajax/generate_tracking_link.php" \
        "edit_tracker=1&tracker_id=970039&tracker_type=0&method_of_promotion=landingpage&aff_network_id=7001&aff_campaign_id=7001&landing_page_id=7001&text_ad_id=7001&ppc_network_id=7001&ppc_account_id=7001&click_cloaking=1&cost_type=cpc&cpc_dollars=0&cpc_cents=10"
    notices "Slack: code for A's page under B's campaign" "tracking202/ajax/get_landing_code.php" \
        "aff_network_id=7001&aff_campaign_id=7001&method_of_promotion=landingpage&landing_page_id=7003"
    Q "UPDATE 202_rotators SET default_campaign = NULL, default_lp = 7002 WHERE id = 7003"
    notices "Slack: saving A's redirector whose default is B's page" "tracking202/ajax/rotator.php" \
        "post_rules=true&rotator_id=7003&default_type=url&defaults=https%3A%2F%2Fa.example%2Fdefault&data%5B0%5D%5Brule_id%5D=7003&data%5B0%5D%5Brule_name%5D=A+Rule&data%5B0%5D%5Bstatus%5D=active&data%5B0%5D%5Bsplit%5D=false&data%5B0%5D%5Bcriteria%5D%5B0%5D%5Bcriteria_id%5D=none&data%5B0%5D%5Bcriteria%5D%5B0%5D%5Btype%5D=country&data%5B0%5D%5Bcriteria%5D%5B0%5D%5Bstatement%5D=is&data%5B0%5D%5Bcriteria%5D%5B0%5D%5Bvalue%5D=US&data%5B0%5D%5Bredirects%5D%5B0%5D%5Bid%5D=7003&data%5B0%5D%5Bredirects%5D%5B0%5D%5Btype%5D=campaign&data%5B0%5D%5Bredirects%5D%5B0%5D%5Bvalue%5D=7001&data%5B0%5D%5Bredirects%5D%5B0%5D%5Bweight%5D=100"
    Q "UPDATE 202_users_pref SET user_slack_incoming_webhook = '' WHERE user_id = 1"
else
    say "Slack notices: not checked (set P202_SLACK_CAPTURE_URL and P202_SLACK_CAPTURE_FILE)"
fi

say "summary"
echo "  $PASS passed, $FAIL failed"
if [ ${#LEAKED[@]} -gt 0 ]; then
    echo "  pages that showed account B's records:"
    printf '    %s\n' "${LEAKED[@]}"
fi
echo "  responses kept in $OUT"
[ "$FAIL" -eq 0 ]
