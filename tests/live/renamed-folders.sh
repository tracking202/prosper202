#!/bin/bash
# Live pass for the folders 1.9.79 renamed (#250): tracking202/setup/ and
# tracking202/analyze/ are Setup/ and Analyze/, and an old address answers
# the 404, which offers a signed-in visitor the page at its current address.
#
# Two ways an old address reaches the 404, and each is driven here:
#   - nothing is at the old path: the server's ErrorDocument / error_page, or
#     the eval router under php -S (a missing .php only);
#   - an upgrade copied the new files over the old ones on Linux and left the
#     old folders beside the new: connect.php refuses to run the old copy.
# The second needs the files: set P202_ROOT to the served tree and this pass
# copies Setup/ to setup/ and Analyze/ to analyze/ for the run, and removes
# only what it made. It then renames Setup/ to setup/, as files copied over
# the old folder on a Mac or Windows computer arrive, and names it back. On a
# disk that ignores case the two spellings are one folder, and that part is
# reported as not run.
#
# Additive: it writes no rows. Needs P202_PASS.
BASE=${P202_BASE:-http://127.0.0.1:8097}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_ROOT=${P202_ROOT:-}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass logs in as $P202_USER and needs its password." >&2
    exit 2
fi

JAR=$(mktemp)
ANON=$(mktemp)
OUT=$(mktemp -d)
PASS=0; FAIL=0; NOTRUN=0
MADE=()
RENAMED=()

remove_made() {
    for dir in "${MADE[@]}"; do
        rm -rf -- "$dir"
    done
    MADE=()
}
# Each entry "from:to" was renamed from "from" to "to"; name it back.
rename_back() {
    for pair in "${RENAMED[@]}"; do
        mv -- "${pair##*:}" "${pair%%:*}" || echo "  could not name ${pair##*:} back to ${pair%%:*}" >&2
    done
    RENAMED=()
}
cleanup() {
    remove_made
    rename_back
    rm -rf -- "$OUT"
    rm -f -- "$JAR" "$ANON"
}
trap cleanup EXIT

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

# The install's base path, as the page writes its links: "/" or "/p202/".
BASE_PATH=$(printf '%s' "$BASE" | sed -E 's#^[a-zA-Z]+://[^/]+##')
BASE_PATH="${BASE_PATH%/}/"

# GET without following redirects: a 404 must be answered where it was asked.
get() { # jar url out -> CODE, headers in out.h
    CODE=$(curl -sS -b "$1" -c "$1" "$2" -o "$3" -D "$3.h" -w '%{http_code}')
}
token_of() { grep -oE 'name="token" value="[^"]+"' "$1" | head -1 | sed 's/.*value="//; s/"//'; }
# The href of the card's first button, as written (entities left as they are).
first_button() { grep -oE '<a class="btn btn-primary" href="[^"]*"' "$1" | head -1 | sed 's/.*href="//; s/"$//'; }

# The signed-in 404 offering $3: status, sentence, the link, the dashboard.
offers() { # out code target label
    eq "$2" 404 "$4: answered 404"
    has "$1" 'This page is at another address.' "$4: says the page moved"
    eq "$(first_button "$1")" "$3" "$4: links the page at its current address"
    has "$1" "href=\"${BASE_PATH}202-account/\"" "$4: links the dashboard"
}
# The signed-in 404 with nothing to offer: the dashboard only.
offers_nothing() { # out code label
    eq "$2" 404 "$3: answered 404"
    has "$1" 'The address may be mistyped, or the page may have moved.' "$3: says it found nothing"
    eq "$(first_button "$1")" "${BASE_PATH}202-account/" "$3: offers the dashboard"
    hasnt "$1" 'This page is at another address.' "$3: offers no page"
}
# The 404 a visitor who is not signed in sees: no address inside the install.
anonymous() { # out code label
    eq "$2" 404 "$3: answered 404"
    has "$1" 'The link may have expired, or the address may be mistyped.' "$3: the plain page"
    hasnt "$1" 'tracking202/' "$3: names no page of the install"
    hasnt "$1" '202-account/' "$3: names no dashboard"
}

say "login"
get "$JAR" "$BASE/202-login.php" "$OUT/login.html"
LT=$(token_of "$OUT/login.html")
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
    --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
    --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "$JAR" "$BASE/202-account/" "$OUT/home.html"
eq "$CODE" 200 "the dashboard answers"
hasnt "$OUT/home.html" 'name="user_pass"' "session established"

say "the renamed folders serve their pages, each under its own tab"
get "$JAR" "$BASE/tracking202/Setup/ppc_accounts.php" "$OUT/setup.html"
eq "$CODE" 200 "Setup/ppc_accounts.php answers"
has "$OUT/setup.html" 'id="SetupPage" aria-current="page"' "the Setup tab is the current one"
get "$JAR" "$BASE/tracking202/Setup/" "$OUT/setup-index.html"
eq "$CODE" 302 "Setup/ answers its index, which redirects"
eq "$(grep -i '^location:' "$OUT/setup-index.html.h" | tr -d '\r' | sed 's/^[^:]*: *//')" \
    "${BASE_PATH}tracking202/Setup/ppc_accounts.php" "to Setup/ppc_accounts.php"
get "$JAR" "$BASE/tracking202/Analyze/keywords.php" "$OUT/analyze.html"
eq "$CODE" 200 "Analyze/keywords.php answers"
has "$OUT/analyze.html" 'id="AnalyzePage" aria-current="page"' "the Analyze tab is the current one"
for page in setup analyze home; do
    if grep -qE '/tracking202/(setup|analyze)([/"?]|$)' "$OUT/$page.html"; then
        bad "$page links no old spelling"
    else
        ok "$page links no old spelling"
    fi
done

say "an old address, signed in, offers the page where it is now"
get "$JAR" "$BASE/tracking202/setup/ppc_accounts.php?x=1&y=2" "$OUT/old.html"
offers "$OUT/old.html" "$CODE" "${BASE_PATH}tracking202/Setup/ppc_accounts.php?x=1&amp;y=2" "setup/ppc_accounts.php"
hasnt "$OUT/old.html" 'id="renamed-folder-warning"' "setup/ppc_accounts.php: no folder warning on a tree as shipped"
get "$JAR" "$BASE/tracking202/analyze/keywords.php" "$OUT/old-analyze.html"
offers "$OUT/old-analyze.html" "$CODE" "${BASE_PATH}tracking202/Analyze/keywords.php" "analyze/keywords.php"
get "$JAR" "$BASE/tracking202/SETUP/Text_Ads.php" "$OUT/typo.html"
offers "$OUT/typo.html" "$CODE" "${BASE_PATH}tracking202/Setup/text_ads.php" "another case, one match"

say "an address with no page offers the dashboard"
get "$JAR" "$BASE/tracking202/nowhere.php" "$OUT/nowhere.html"
offers_nothing "$OUT/nowhere.html" "$CODE" "tracking202/nowhere.php"
get "$JAR" "$BASE/tracking202/setup/gone.php" "$OUT/gone.html"
offers_nothing "$OUT/gone.html" "$CODE" "a page 1.9.79 does not have"
get "$JAR" "$BASE/tracking202/setup/_includes/setup_ui.php" "$OUT/include.html"
offers_nothing "$OUT/include.html" "$CODE" "an include, which is no page"

say "signed out, the 404 offers nothing of the install"
get "$ANON" "$BASE/tracking202/setup/ppc_accounts.php?x=1" "$OUT/anon.html"
anonymous "$OUT/anon.html" "$CODE" "setup/ppc_accounts.php"
get "$ANON" "$BASE/tracking202/nowhere.php" "$OUT/anon-nowhere.html"
anonymous "$OUT/anon-nowhere.html" "$CODE" "tracking202/nowhere.php"

say "the API is not the 404's"
get "$ANON" "$BASE/api/v3/system/health" "$OUT/api.json"
if [ "$CODE" != 404 ] && ! grep -qF 'Page not found' "$OUT/api.json"; then
    ok "api/v3/system/health answers the API ($CODE)"
else
    bad "api/v3/system/health answers the API (got $CODE)"
fi

say "a leftover copy of an old folder answers the 404, and runs nothing"
T202="$P202_ROOT/tracking202"
if [ -z "$P202_ROOT" ]; then
    NOTRUN=$((NOTRUN+1)); echo "  NOT RUN: P202_ROOT is not set"
elif [ ! -f "$P202_ROOT/202-config/connect.php" ] || [ ! -d "$T202/Setup" ] || [ ! -d "$T202/Analyze" ]; then
    bad "P202_ROOT ($P202_ROOT) holds no 1.9.79 tree"
elif [ -e "$T202/setup" ] || [ -e "$T202/analyze" ]; then
    # Already there: a case-insensitive disk (one folder, two names) or an
    # install with real leftovers, which this pass will not touch.
    NOTRUN=$((NOTRUN+1)); echo "  NOT RUN: $T202 already answers to setup/ or analyze/"
else
    for pair in Setup:setup Analyze:analyze; do
        if cp -a -- "$T202/${pair%%:*}" "$T202/${pair##*:}"; then
            MADE+=("$T202/${pair##*:}")
        else
            bad "copy ${pair%%:*}/ to ${pair##*:}/"
        fi
    done
    PROBE="p202-live-$(date +%s)-$$"
    printf '%s' "$PROBE" > "$T202/setup/$PROBE.txt"
    get "$ANON" "$BASE/tracking202/setup/$PROBE.txt" "$OUT/probe.txt"
    eq "$(cat "$OUT/probe.txt")" "$PROBE" "the server serves the leftover folder it was given"

    get "$JAR" "$BASE/tracking202/setup/ppc_accounts.php?x=1" "$OUT/left.html"
    offers "$OUT/left.html" "$CODE" "${BASE_PATH}tracking202/Setup/ppc_accounts.php?x=1" "a leftover setup/ page"
    hasnt "$OUT/left.html" 'id="SetupPage"' "a leftover setup/ page draws no Setup page"
    has "$OUT/left.html" '<code>tracking202/setup</code> is left from before 1.9.79' "and says to delete setup/"
    has "$OUT/left.html" '<code>tracking202/analyze</code> is left from before 1.9.79' "and analyze/"
    get "$JAR" "$BASE/tracking202/setup/" "$OUT/left-index.html"
    offers "$OUT/left-index.html" "$CODE" "${BASE_PATH}tracking202/Setup/index.php" "the leftover setup/ index"
    get "$JAR" "$BASE/tracking202/analyze/keywords_download.php" "$OUT/left-download.html"
    offers "$OUT/left-download.html" "$CODE" "${BASE_PATH}tracking202/Analyze/keywords_download.php" "a leftover download page"
    if grep -qi '^content-disposition:' "$OUT/left-download.html.h"; then
        bad "a leftover download page sends no attachment"
    else
        ok "a leftover download page sends no attachment"
    fi
    if grep -qi '^content-type: text/html' "$OUT/left-download.html.h"; then
        ok "a leftover download page answers HTML"
    else
        bad "a leftover download page answers HTML"
    fi
    get "$ANON" "$BASE/tracking202/setup/ppc_accounts.php" "$OUT/left-anon.html"
    anonymous "$OUT/left-anon.html" "$CODE" "a leftover setup/ page, signed out"
    hasnt "$OUT/left-anon.html" 'renamed-folder-warning' "a leftover setup/ page, signed out: no folder warning"

    get "$JAR" "$BASE/tracking202/Setup/ppc_accounts.php" "$OUT/beside.html"
    eq "$CODE" 200 "Setup/ppc_accounts.php still answers beside the leftover"
    has "$OUT/beside.html" 'id="SetupPage" aria-current="page"' "and draws its page"

    say "a renamed folder under its old name is named in the 404"
    remove_made
    if [ -e "$T202/setup" ]; then
        bad "the leftover copies are gone"
    elif mv -- "$T202/Setup" "$T202/setup"; then
        RENAMED+=("$T202/Setup:$T202/setup")
        get "$JAR" "$BASE/tracking202/Setup/ppc_accounts.php" "$OUT/misspelled.html"
        offers "$OUT/misspelled.html" "$CODE" "${BASE_PATH}tracking202/setup/ppc_accounts.php" "Setup/ppc_accounts.php under setup/"
        has "$OUT/misspelled.html" 'Since 1.9.79 <code>tracking202/setup</code> is <code>tracking202/Setup</code>' "and says to rename setup/"
        hasnt "$OUT/misspelled.html" 'tracking202/analyze</code>' "and names no folder that is in place"
        rename_back
        get "$JAR" "$BASE/tracking202/Setup/ppc_accounts.php" "$OUT/named-back.html"
        eq "$CODE" 200 "Setup/ppc_accounts.php answers once named back"
    else
        bad "rename Setup/ to setup/"
    fi
fi

echo
if [ "$NOTRUN" -gt 0 ]; then
    echo "$PASS passed, $FAIL failed, $NOTRUN not run"
else
    echo "$PASS passed, $FAIL failed"
fi
[ "$FAIL" -eq 0 ]
