#!/bin/bash
# Live pass for a click's public id (PublicClickId), the id a cloaked link
# (cl.php) and a click's way out (pci.php) are handed. Before 1.9.80 it was
# the click id with a random digit either side, so anyone holding one link
# could reach the clicks around it: 81 guesses named the next visitor's
# click, cl.php sent the guesser to its destination, and pci.php marked it
# as gone and set its cookie on the guesser.
#
# What it pins:
#   - dl.php, rtr.php, record_simple.php and record_adv.php hand out the new
#     shape (19 digits, a 1, the click id in twelve, a keyed suffix), made
#     with the install's key, which the first click minted;
#   - cl.php and pci.php take the new id to its own click;
#   - from one click's id, the next click is not reached: not by the 81 ids
#     of the old shape, nor by the new shape with the holder's own suffix,
#     and pci.php marks it gone for none of them;
#   - a click stored before the upgrade, with the old shape, still resolves
#     through both pages.
#
# Additive: it creates its own records (named "cpi-pass <time>") and fires
# real clicks; it truncates nothing, but run it against a scratch instance,
# since the clicks stay. Runs `php` from this checkout to compute an id from
# the stored key.
#
#   P202_BASE=http://127.0.0.1:8098 P202_DB=p202_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/cloaked-public-ids.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY must be set: this pass creates its records through the API." >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
trap 'rm -rf "$OUT"' EXIT
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else v)" "$OUT/body" 2>/dev/null; }
must() {
    case "$1" in 2??) ;; *) echo "$2 failed: HTTP $1 $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac
}

T=$(date +%s)
TAG="cpi-pass $T"
U="https://example.com/cpi-$T"
UA="cpi-pass-probe/1.0"
FIREFOX='Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0'
n=0
# get PATH [CURL ARGS...] — the Location it answers with, or "none"; the body in $OUT/page.
get() {
    local path=$1; shift
    n=$((n+1))
    local loc
    loc=$(curl -sS -o "$OUT/page" -w '%{redirect_url}' -A "$UA" -H "X-Forwarded-For: 198.51.100.$((n % 250 + 1))" "$@" "$BASE$path")
    echo "${loc:-none}"
}
ME=$(Q "SELECT user_id FROM 202_api_keys WHERE (key_hash = SHA2('$P202_API_KEY', 256) OR (key_hash IS NULL AND api_key COLLATE utf8mb4_bin = '$P202_API_KEY'))")
[ -n "$ME" ] || { echo "no account holds P202_API_KEY" >&2; exit 2; }

# pci_in TEXT — the public id in a cl.php or pci.php address in TEXT.
pci_in() { printf '%s' "$1" | grep -oE 'pci=[0-9]+' | head -1 | cut -d= -f2; }
# click_of PUBLIC — the click stored with that public id.
click_of() { Q "SELECT click_id FROM 202_clicks_record WHERE click_id_public = $1"; }
# shaped PUBLIC CLICK — "yes" when PUBLIC is 1, CLICK in twelve digits, six more.
shaped() {
    if printf '%s' "$1" | grep -qE "^1$(printf '%012d' "$2")[0-9]{6}\$"; then echo yes; else echo "no: $1"; fi
}
# found PAGE — whether a cl.php page sends on (cl2.php) or is the not-found page.
found() {
    if grep -q '202-404' "$OUT/page"; then echo missing
    elif grep -q 'cl2.php' "$OUT/page"; then echo found
    else echo "unreadable: $(head -c 200 "$OUT/page")"; fi
}
gone() { Q "SELECT click_out FROM 202_clicks_record WHERE click_id = $1"; }

say "setup: a cloaked campaign, a direct link, a redirector, two landing pages ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG\",\"aff_campaign_url\":\"$U/offer?s=[[subid]]\",\"aff_campaign_payout\":\"2\",\"aff_campaign_cloaking\":1,\"aff_network_id\":$NET}")" "campaign"
CA=$(field "d['data']['aff_campaign_id']")
must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"click_cloaking\":1}")" "direct-link tracker"
TR=$(field "d['data']['tracker_id']")
must "$(api GET "/trackers/$TR/url")" "direct-link tracker url"
DL_URL=$(field "d['data']['direct_url']")
case "$DL_URL" in *dl.php*) ;; *) echo "the tracker's URL is not dl.php: $DL_URL" >&2; exit 2 ;; esac
DL_PATH=${DL_URL#*://*/}
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/simple\",\"landing_page_nickname\":\"$TAG simple\",\"aff_campaign_id\":$CA}")" "simple landing page"
LPS=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id=$(field "d['data']['landing_page_id']")")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/adv\",\"landing_page_nickname\":\"$TAG adv\",\"aff_campaign_id\":$CA,\"landing_page_type\":1}")" "advanced landing page"
LPA=$(Q "SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id=$(field "d['data']['landing_page_id']")")
must "$(api POST /landing-pages "{\"landing_page_url\":\"$U/default\",\"landing_page_nickname\":\"$TAG default\",\"aff_campaign_id\":$CA}")" "rotator default landing page"
LPD=$(field "d['data']['landing_page_id']")
must "$(api POST /rotators "{\"name\":\"$TAG\",\"default_lp\":$LPD}")" "rotator"
RT=$(field "d['data']['id']")
must "$(api POST "/rotators/$RT/rules" "{\"rule_name\":\"$TAG cloaked\",\"criteria\":[{\"type\":\"browser\",\"statement\":\"is\",\"value\":\"Firefox\"}],\"redirects\":[{\"redirect_campaign\":$CA,\"weight\":100,\"name\":\"cloaked\"}]}")" "rotator rule"
must "$(api POST /trackers "{\"aff_campaign_id\":$CA,\"rotator_id\":$RT}")" "redirector tracker"
TRR=$(field "d['data']['tracker_id']")
must "$(api GET "/trackers/$TRR/url")" "redirector tracker url"
RTR_URL=$(field "d['data']['direct_url']")
case "$RTR_URL" in *rtr.php*) ;; *) echo "the redirector tracker's URL is not rtr.php: $RTR_URL" >&2; exit 2 ;; esac
RTR_PATH=${RTR_URL#*://*/}
# shellcheck source=tests/live/privacy.sh
. "$(dirname "${BASH_SOURCE[0]}")/privacy.sh"
p202_track_in_full "$BASE" "$P202_API_KEY" "$ME" || exit 2

say "a direct link (dl.php) hands out the new shape, made with the install's key"
loc=$(get "/$DL_PATH")
A_PCI=$(pci_in "$loc")
case "$loc" in *"/tracking202/redirect/cl.php?pci="*) ok "a cloaked click goes to cl.php" ;; *) bad "a cloaked click goes to cl.php (got $loc)" ;; esac
A=$(click_of "${A_PCI:-0}")
[ -n "$A" ] || { echo "no click is stored with the public id dl.php handed out (${A_PCI:-none})" >&2; exit 2; }
eq "$(shaped "$A_PCI" "$A")" "yes" "its public id is 1, click $A in twelve digits and a six-digit suffix"
loc=$(get "/$DL_PATH")
B_PCI=$(pci_in "$loc")
B=$(click_of "${B_PCI:-0}")
[ -n "$B" ] || { echo "the second click stored no public id (${B_PCI:-none})" >&2; exit 2; }
eq "$(shaped "$B_PCI" "$B")" "yes" "and so is the next visitor's (click $B)"
KEY=$(Q "SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = 'click_public_id'")
if printf '%s' "$KEY" | grep -qE '^[0-9a-f]{64}$'; then ok "the first click minted the install's key"; else bad "the first click minted the install's key (got '$KEY')"; fi
issued=$(php -r 'require $argv[1] . "/vendor/autoload.php"; echo \Prosper202\Click\PublicClickId::issue((int) $argv[2], hex2bin($argv[3]));' "$ROOT" "$A" "$KEY" 2>&1)
eq "$A_PCI" "$issued" "and click $A's id is the one that key makes, not a random fallback"

say "cl.php and pci.php take a new id to its own click"
get "/tracking202/redirect/cl.php?pci=$A_PCI" >/dev/null
eq "$(found)" "found" "cl.php sends click $A on"
if grep -q "$U/offer" "$OUT/page"; then ok "to the campaign's offer"; else bad "to the campaign's offer"; fi

say "from click $A's id, click $B is not reached"
# guesses TARGET HOLDER_PUBLIC — every id the old shape gave TARGET, and
# the holder's own suffix on TARGET: what a holder who took the suffix for
# one per install, not per click, would try. 82 lines in $OUT/guesses.
guesses() {
    : > "$OUT/guesses"
    for x in 1 2 3 4 5 6 7 8 9; do for y in 1 2 3 4 5 6 7 8 9; do echo "$x$1$y" >> "$OUT/guesses"; done; done
    echo "1$(printf '%012d' "$1")${2: -6}" >> "$OUT/guesses"
}
guesses "$B" "$A_PCI"
if grep -qx "$B_PCI" "$OUT/guesses"; then
    bad "no guess is click $B's id (click $A's suffix matched its: one in a million, rerun)"
fi
miss=0; reach=0
while read -r guess; do
    get "/tracking202/redirect/cl.php?pci=$guess" >/dev/null
    case "$(found)" in missing) miss=$((miss+1)) ;; *) reach=$((reach+1)) ;; esac
done < "$OUT/guesses"
eq "$miss/$reach" "82/0" "cl.php answers not found for the 81 old-shape ids and click $A's suffix"
sent=0; cookied=0
while read -r guess; do
    loc=$(curl -sS -D "$OUT/headers" -o /dev/null -w '%{redirect_url}' -A "$UA" "$BASE/tracking202/redirect/pci.php?pci=$guess")
    [ -n "$loc" ] && sent=$((sent+1))
    grep -qi "^set-cookie: tracking202subid=$B;" "$OUT/headers" && cookied=$((cookied+1))
done < "$OUT/guesses"
eq "$sent/$cookied" "0/0" "pci.php sends none of them on, nor sets click $B's cookie"
loc=$(curl -sS -D "$OUT/headers" -o /dev/null -w '%{redirect_url}' -A "$UA" "$BASE/tracking202/redirect/pci.php?pci=$B_PCI")
case "$loc" in *"cl.php?pci=$B_PCI") ok "while click $B's own id goes to its cloaked page" ;; *) bad "while click $B's own id goes to its cloaked page (got ${loc:-none})" ;; esac
if grep -qi "^set-cookie: tracking202subid=$B;" "$OUT/headers"; then ok "with its cookie"; else bad "with its cookie"; fi

say "a redirector link (rtr.php) to a cloaked campaign"
loc=$(curl -sS -o /dev/null -w '%{redirect_url}' -A "$FIREFOX" -H "X-Forwarded-For: 198.51.100.252" "$BASE/$RTR_PATH")
R_PCI=$(pci_in "$loc")
R=$(click_of "${R_PCI:-0}")
if [ -n "$R" ]; then eq "$(shaped "$R_PCI" "$R")" "yes" "rtr.php hands out the new shape (click $R)"; else bad "rtr.php stored the public id it handed out (got ${loc:-none})"; fi
get "/tracking202/redirect/cl.php?pci=${R_PCI:-0}" >/dev/null
eq "$(found)" "found" "and cl.php sends it on"

say "landing pages (record_simple.php, record_adv.php)"
get "/tracking202/static/record_simple.php?lpip=$LPS&t202kw=cpi" >/dev/null
S_PCI=$(pci_in "$(grep -oE 'var outbound =[^;]*' "$OUT/page")")
S=$(grep -oE 'var subid =[^;]*' "$OUT/page" | grep -oE '[0-9]+' | head -1)
eq "$(shaped "${S_PCI:-0}" "${S:-0}")" "yes" "a simple landing page's way out carries the new shape (click ${S:-none})"
loc=$(get "/tracking202/redirect/pci.php?pci=${S_PCI:-0}")
case "$loc" in *"cl.php?pci=$S_PCI") ok "and pci.php takes it to its cloaked page" ;; *) bad "and pci.php takes it to its cloaked page (got $loc)" ;; esac
# A landing page's click is marked gone only by pci.php: the guesses must
# leave the next visitor's unmarked.
get "/tracking202/static/record_simple.php?lpip=$LPS&t202kw=cpi" >/dev/null
S2_PCI=$(pci_in "$(grep -oE 'var outbound =[^;]*' "$OUT/page")")
S2=$(grep -oE 'var subid =[^;]*' "$OUT/page" | grep -oE '[0-9]+' | head -1)
guesses "${S2:-0}" "${S_PCI:-0}"
before=$(gone "${S2:-0}")
while read -r guess; do
    get "/tracking202/redirect/pci.php?pci=$guess" >/dev/null
done < "$OUT/guesses"
eq "$before/$(gone "${S2:-0}")" "0/0" "from click ${S:-none}'s way out, pci.php marks the next visitor's click ${S2:-none} gone for no guess"
get "/tracking202/redirect/pci.php?pci=${S2_PCI:-0}" >/dev/null
eq "$(gone "${S2:-0}")" "1" "while its own id does"
get "/tracking202/static/record_adv.php?lpip=$LPA&t202kw=cpi" >/dev/null
V_PCI=$(grep -oE 'var pci = "[0-9]+"' "$OUT/page" | grep -oE '[0-9]+' | head -1)
V=$(grep -oE 'var subid =[^;]*' "$OUT/page" | grep -oE '[0-9]+' | head -1)
eq "$(shaped "${V_PCI:-0}" "${V:-0}")" "yes" "an advanced landing page's click carries the new shape (click ${V:-none})"
eq "$(click_of "${V_PCI:-0}")" "${V:-none}" "and is stored with it"

say "a click stored before the upgrade keeps its link"
OC=$(Q "INSERT INTO 202_clicks_counter SET click_id = DEFAULT; SELECT LAST_INSERT_ID()")
OLD="4${OC}6"
Q "INSERT INTO 202_site_urls (site_domain_id, site_url_address) VALUES (0, '$U/legacy-$OC'), (0, '$BASE/tracking202/redirect/cl.php?pci=$OLD')"
OSU=$(Q "SELECT MAX(site_url_id) FROM 202_site_urls WHERE site_url_address = '$U/legacy-$OC'")
OCU=$(Q "SELECT MAX(site_url_id) FROM 202_site_urls WHERE site_url_address = '$BASE/tracking202/redirect/cl.php?pci=$OLD'")
Q "INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, ppc_account_id, click_cpc, click_time) VALUES ($OC, $ME, $CA, 0, 0, $T); INSERT INTO 202_clicks_record (click_id, click_id_public, click_cloaking) VALUES ($OC, $OLD, 1); INSERT INTO 202_clicks_site (click_id, click_redirect_site_url_id, click_cloaking_site_url_id) VALUES ($OC, $OSU, $OCU)"
get "/tracking202/redirect/cl.php?pci=$OLD" >/dev/null
eq "$(found)" "found" "cl.php?pci=$OLD sends the pre-upgrade click on"
if grep -q "$U/legacy-$OC" "$OUT/page"; then ok "to its own destination"; else bad "to its own destination"; fi
loc=$(get "/tracking202/redirect/pci.php?pci=$OLD")
eq "$loc" "$BASE/tracking202/redirect/cl.php?pci=$OLD" "pci.php takes it to its cloaked page"
eq "$(gone "$OC")" "1" "and marks it gone"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
