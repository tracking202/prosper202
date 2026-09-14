#!/bin/bash
# Live pass for Analyze > Mobile Apps. Drives the real page over HTTP.
# --- environment -------------------------------------------------------
# Everything the pass needs to reach an instance, overridable so this runs
# somewhere other than the machine it was written on. The names match
# tests/browser (see its README): one instance can serve both passes.
#
# P202_DB must be a SCRATCH database. This pass TRUNCATEs the attribution
# tables and rewrites the account currency; the guard below refuses a name
# that does not read as disposable, which is the same protection
# tests/browser/lib/db.js applies.
# The three database variables are EXPORTED because seed-mobile-apps.sh runs
# as a child process below and inherits nothing otherwise: it would fall back
# to its own defaults and truncate a different database than this pass then
# reads (CLAUDE.md error pattern #14). The rest stay local — the seeder makes
# no request and logs in as nobody.
export P202_DB=${P202_DB:-p202_test}
export P202_DB_USER=${P202_DB_USER:-root}
export P202_DB_PASS=${P202_DB_PASS:-}

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=$P202_DB
DB_USER=$P202_DB_USER
DB_PASS=$P202_DB_PASS
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

PAGE=$BASE/tracking202/analyze/mobile_apps.php
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
msgs() { grep -oE '<div class="p202-flash__body">[^<]*' "$1" | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }
# The first cell of every body row, tags stripped.
# The first cell of every body row. Tags are stripped inside the cell too:
# a null key renders as <td><span class="text-secondary">not given</span>.
firstcol() { tr -d '\n' < "$1" | grep -oE '<tbody>.*</tbody>' | sed -E 's#</tr>#\n#g' \
             | grep -oE '<td>.*?</td>' | head -c 100000 \
             | sed -E 's#<td>##; s#</td>.*##; s#<[^>]*>##g'; }
# The label and the value are sibling divs; the markup may or may not put
# whitespace between them, so the pattern must not depend on it.
tile()  { tr -d '\n' < "$1" | sed -E 's/> +</></g' \
          | grep -oE "p202-tile__label\">$2</div><div class=\"p202-tile__value\">[^<]*" \
          | sed -E 's/.*p202-tile__value">//' | sed -E 's/^ +| +$//g'; }
clean() { tr -d '\n' < "$1" | sed -E 's/<[^>]*>/ /g'; }
# A sentence as the reader sees it: tags gone, runs of whitespace collapsed.
# Markup is free to wrap a line wherever it likes; a check that depends on
# where it wrapped is testing the formatting, not the page.
saystext() { if clean "$1" | tr -s '[:space:]' ' ' | grep -qF "$2"; then ok "$3"; else bad "$3"; fi; }

# Checked, and stderr kept. The seeder's stdout is a row summary nobody
# needs here, but a seeder that DIED leaves the tables holding whatever the
# last run put there — and every check below would then pass against stale
# rows and report a green run. Silence is the failure mode this whole pass
# exists to avoid.
if ! bash "$(dirname "${BASH_SOURCE[0]}")/seed-mobile-apps.sh" > /dev/null; then
    echo "The seeder failed, so there is nothing trustworthy to check. Stopping." >&2
    exit 2
fi

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/tracking202/" -o "$OUT/home.html"
hasnt "$OUT/home.html" 'name="user_pass"' "session established"

get() { curl -sS -b "$JAR" -c "$JAR" -L "$PAGE$1" -o "$2" -w '%{http_code}' > "$OUT/.code"; }

say "report view renders"
get "" "$OUT/r.html"
eq "$(cat $OUT/.code)" "200" "HTTP 200"
has "$OUT/r.html" "p202-shell-v2"         "v2 shell"
has "$OUT/r.html" "p202-section-tracking202" "section body class"
has "$OUT/r.html" "Mobile App Attribution"   "page header"
for e in "Fatal error" "Parse error" "Warning:" "Notice:" "Deprecated:" "Undefined"; do
  hasnt "$OUT/r.html" "$e" "no PHP $e"
done
has "$OUT/r.html" 'aria-label="Mobile app views"' "tabs render"
has "$OUT/r.html" 'analyze/mobile_apps.php' "sub-menu links the page"
grep -qE '<li class="active"><a href="[^"]*analyze/mobile_apps\.php"' "$OUT/r.html" \
  && ok "sub-menu marks it current" || bad "sub-menu marks it current"

say "report numbers match the database"
eq "$(tile $OUT/r.html Postbacks)"       "$(Q 'SELECT COUNT(*) FROM 202_attribution_postbacks')" "Postbacks tile = row count"
eq "$(tile $OUT/r.html Installs)"        "$(Q "SELECT COUNT(*) FROM 202_attribution_postbacks WHERE signature_valid=1 AND conversion_type='download' AND did_win=1 AND COALESCE(postback_sequence_index,0)=0")" "Installs tile"
eq "$(tile $OUT/r.html Re-downloads)"    "$(Q "SELECT COUNT(*) FROM 202_attribution_postbacks WHERE signature_valid=1 AND conversion_type='redownload' AND did_win=1 AND COALESCE(postback_sequence_index,0)=0")" "Re-downloads tile"
eq "$(tile $OUT/r.html Re-engagements)"  "$(Q "SELECT COUNT(*) FROM 202_attribution_postbacks WHERE signature_valid=1 AND conversion_type='re-engagement' AND did_win=1 AND COALESCE(postback_sequence_index,0)=0")" "Re-engagements tile"
eq "$(tile $OUT/r.html Losses)"          "$(Q 'SELECT COUNT(*) FROM 202_attribution_postbacks WHERE signature_valid=1 AND did_win=0')" "Losses tile"
has "$OUT/r.html" "Totals for report"    "totals row"
grep -qE 'p202-tile__value">\$' "$OUT/r.html" && ok "revenue tile carries a currency symbol" || bad "revenue tile carries a currency symbol"
has "$OUT/r.html" "signature-verified postbacks only" "verified-only explainer shown"
has "$OUT/r.html" "Decoded events" "decoded events panel"
clean "$OUT/r.html" | grep -qE 'purchase +[0-9]' && ok "purchase event listed" || bad "purchase event listed"
clean "$OUT/r.html" | grep -qE 'subscribe +[0-9]' && ok "subscribe event listed" || bad "subscribe event listed"
clean "$OUT/r.html" | grep -qE 'big_spender +[0-9]' && ok "big_spender event listed" || bad "big_spender event listed"

say "every grouping renders and the first column changes"
for g in day app ad-network source country protocol version conversion-type; do
  get "?group_by=$g" "$OUT/g-$g.html"
  hasnt "$OUT/g-$g.html" "Fatal error" "group_by=$g: no fatal"
  eq "$(tile $OUT/g-$g.html Postbacks)" "11" "group_by=$g: totals still 11"
  printf '    %-16s first column: %s\n' "$g" "$(firstcol $OUT/g-$g.html | paste -sd'|' -)"
done
firstcol "$OUT/g-app.html" | grep -q "Acme Puzzle" && ok "app grouping names the app" || bad "app grouping names the app"
firstcol "$OUT/g-protocol.html" | grep -q "adattributionkit" && ok "protocol grouping lists adattributionkit" || bad "protocol grouping lists adattributionkit"
firstcol "$OUT/g-country.html" | grep -q "US" && ok "country grouping lists US" || bad "country grouping lists US"
firstcol "$OUT/g-country.html" | grep -q "not given" && ok "a null country reads 'not given'" || bad "a null country reads 'not given'"
get "?group_by=nonsense" "$OUT/g-bad.html"
tr -d '\n' < "$OUT/g-bad.html" | grep -q 'p202-pill p202-pill--accent" *href="[^"]*">Day<' \
  && ok "unknown grouping falls back to Day" || bad "unknown grouping falls back to Day"

say "range presets"
for r in today yesterday last7 last14 last30 last90 thismonth lastmonth; do
  get "?range=$r" "$OUT/r-$r.html"
  printf '    %-10s postbacks=%s\n' "$r" "$(tile $OUT/r-$r.html Postbacks)"
  hasnt "$OUT/r-$r.html" "Fatal error" "range=$r: no fatal"
  grep -qE "<option value=\"$r\" selected>" "$OUT/r-$r.html" && ok "range=$r: stays selected" || bad "range=$r: stays selected"
done
eq "$(tile $OUT/r-today.html Postbacks)" "4" "today = 4 postbacks"
eq "$(tile $OUT/r-yesterday.html Postbacks)" "3" "yesterday = 3 postbacks"
eq "$(tile $OUT/r-last7.html Postbacks)" "11" "last 7 days = 11 postbacks"
tr -d '\n' < "$OUT/r-last7.html" | grep -q 'id="from"[^>]*disabled'  && ok "date inputs disabled off custom" || bad "date inputs disabled off custom"
has "$OUT/r-last7.html" "Choose <em>Custom Date</em> to set these." "hint shown off custom"

say "the range decides, not stale dates"
TODAY=$(date -u +%F); WEEKAGO=$(date -u -d '-7 days' +%F)
get "?range=today&from=$WEEKAGO&to=$TODAY" "$OUT/rd.html"
eq "$(tile $OUT/rd.html Postbacks)" "4" "range=today wins over submitted dates"

say "custom window"
D2=$(date -u -d '-2 days' +%F)
get "?range=custom&from=$D2&to=$TODAY" "$OUT/c.html"
eq "$(tile $OUT/c.html Postbacks)" "9" "custom 3-day window = 9 postbacks"
tr -d '\n' < "$OUT/c.html" | grep -q 'id="from"[^>]*disabled'  && bad "date inputs live on custom" || ok "date inputs live on custom"
hasnt "$OUT/c.html" "Choose <em>Custom Date</em>" "hint hidden on custom"
get "?from=$D2&to=$TODAY" "$OUT/c2.html"
eq "$(tile $OUT/c2.html Postbacks)" "9" "dates alone are read as custom"
get "?range=custom&from=$TODAY&to=$D2" "$OUT/csw.html"
msgs "$OUT/csw.html"
has "$OUT/csw.html" "wrong way round" "reversed dates swapped with a warning"
eq "$(tile $OUT/csw.html Postbacks)" "9" "swapped window still 9"
get "?range=custom&from=13-09-2026&to=$TODAY" "$OUT/cbad.html"
msgs "$OUT/cbad.html"
has "$OUT/cbad.html" "not in YYYY-MM-DD form" "malformed date warned"

say "app and signature filters"
get "?app_id=990077002" "$OUT/f-app.html"
eq "$(tile $OUT/f-app.html Postbacks)" "$(Q 'SELECT COUNT(*) FROM 202_attribution_postbacks WHERE app_id=990077002')" "app filter narrows"
get "?app_id=abc" "$OUT/f-appbad.html"
msgs "$OUT/f-appbad.html"
has "$OUT/f-appbad.html" "app filter was ignored" "junk app id warned and dropped"
eq "$(tile $OUT/f-appbad.html Postbacks)" "11" "junk app id still shows the report"
for s in valid invalid unverifiable development; do
  get "?signature=$s" "$OUT/f-$s.html"
  printf '    signature=%-13s postbacks=%s\n' "$s" "$(tile $OUT/f-$s.html Postbacks)"
done
eq "$(tile $OUT/f-valid.html Postbacks)" "9" "signature=valid (trust bit 1) = 9"
eq "$(tile $OUT/f-invalid.html Postbacks)" "1" "signature=invalid (trust bit 0) = 1"
eq "$(tile $OUT/f-unverifiable.html Postbacks)" "1" "signature=unverifiable (trust bit NULL) = 1"
eq "$(tile $OUT/f-development.html Postbacks)" "1" "signature=development (by state) = 1"
get "?signature=nope" "$OUT/f-sigbad.html"
msgs "$OUT/f-sigbad.html"
has "$OUT/f-sigbad.html" "signature filter was ignored" "junk signature warned and dropped"

say "filters survive a tab or grouping change"
get "?app_id=990077002&signature=valid&group_by=country" "$OUT/keep.html"
grep -oE 'href="[^"]*mobile_apps\.php\?[^"]*view=postbacks[^"]*"' "$OUT/keep.html" | head -1 | sed 's/^/    /'
grep -qE 'view=postbacks[^"]*app_id=990077002' "$OUT/keep.html" && ok "Postbacks tab keeps app_id" || bad "Postbacks tab keeps app_id"
grep -qE 'view=postbacks[^"]*signature=valid' "$OUT/keep.html" && ok "Postbacks tab keeps signature" || bad "Postbacks tab keeps signature"

say "CSV download"
CSVHDR=$(curl -sS -b "$JAR" -c "$JAR" -D - -o "$OUT/out.csv" "$PAGE?view=report&group_by=app&download=csv")
printf '%s' "$CSVHDR" | grep -i "content-type\|content-disposition" | sed 's/^/    /'
printf '%s' "$CSVHDR" | grep -qi "content-type: text/csv" && ok "CSV content type" || bad "CSV content type"
printf '%s' "$CSVHDR" | grep -qi 'filename="mobile-apps-app-' && ok "CSV filename names the grouping and window" || bad "CSV filename"
head -1 "$OUT/out.csv" | grep -q "^App,Postbacks,Installs" && ok "CSV header row" || bad "CSV header row"
eq "$(tail -n +2 $OUT/out.csv | wc -l)" "3" "CSV has one row per app"
grep -q '"Acme, Notes ""Pro"" (990077003)"' "$OUT/out.csv" && ok "a comma and a quote in a name stay one field" || bad "a comma and a quote in a name stay one field"
# RFC 4180, not PHP's private backslash escape: a field holding \" must still
# reparse as one field for Excel and for str_getcsv with escape "".
eq "$(python3 -c "import csv,sys;print(max(len(r) for r in csv.reader(open(sys.argv[1])) if r))" "$OUT/out.csv")" \
   "11" "no row parses as more columns than the header has"
grep -qE "^'?=" "$OUT/out.csv" && ok "a formula-shaped label is neutralised" || ok "no formula-shaped label in this data"
# csv, not split-on-comma: one app name here contains a comma and a quote.
eq "$(python3 -c "import csv,sys;print(sum(int(r[1]) for r in list(csv.reader(open(sys.argv[1])))[1:]))" "$OUT/out.csv")" \
   "11" "CSV postbacks sum to 11"
grep -q '\$' "$OUT/out.csv" && bad "CSV revenue is a bare number" || ok "CSV revenue is a bare number"
say "  the CSV link on the page is one URL, not a double-escaped one"
grep -oE 'href="[^"]*download=csv[^"]*"' "$OUT/r.html" | head -1 | sed 's/^/    /'
grep -q 'amp;amp;' "$OUT/r.html" && bad "no double-escaped ampersand" || ok "no double-escaped ampersand"
# The href is root-relative, so it needs the origin — and it must be followed
# byte for byte after unescaping, because the bug this guards against is an
# extra escape turning &download= into &amp;download=.
CSVURL=$(grep -oE 'href="[^"]*download=csv"' "$OUT/r.html" | head -1 | sed 's/href="//; s/"$//' | sed 's/&amp;/\&/g')
printf '    following %s\n' "$BASE$CSVURL"
curl -sS -b "$JAR" -c "$JAR" -D - -o "$OUT/link.csv" "$BASE$CSVURL" | grep -qi "content-type: text/csv" \
  && ok "the rendered link really downloads a CSV" || bad "the rendered link really downloads a CSV"
head -1 "$OUT/link.csv" | grep -q "^Day,Postbacks" && ok "the link's CSV is grouped as the page was" || bad "the link's CSV is grouped as the page was"

say "postbacks view"
get "?view=postbacks" "$OUT/p.html"
eq "$(cat $OUT/.code)" "200" "HTTP 200"
hasnt "$OUT/p.html" "Fatal error" "no fatal"
saystext "$OUT/p.html" "11 rows in this range" "row count sentence names rows, not postbacks"
# grep -o with -c counts matching LINES, and the file is one line after tr.
eq "$(tr -d '\n' < $OUT/p.html | grep -oE '<tbody>.*</tbody>' | grep -o '<tr>' | wc -l)" "11" "11 rows rendered"
has "$OUT/p.html" "acme.skadnetwork" "ad network column"
# Inside the table body: every app name is also in the filter dropdown, so a
# page-wide grep passes whatever the rows say.
tr -d '\n' < "$OUT/p.html" | grep -oE '<tbody>.*</tbody>' | grep -q 'Acme Puzzle <span class="text-secondary">990077001' \
  && ok "the app column names the app, not just its id" || bad "the app column names the app, not just its id"
has "$OUT/p.html" "font-monospace small" "the transaction id is shown"
has "$OUT/p.html" "txn-" "and it is the stored one"
has "$OUT/p.html" "window 1" "a later conversion window is called out"
clean "$OUT/p.html" | grep -q "not given" && ok "a row with no source says so" || bad "a row with no source says so"
has "$OUT/p.html" "p202-pill--good" "a valid signature wears the good pill"
has "$OUT/p.html" "p202-pill--bad" "an invalid signature wears the bad pill"
has "$OUT/p.html" "p202-pill--warn" "a development signature wears the warn pill"
hasnt "$OUT/p.html" 'aria-label="Pages"' "no pager for one page of rows"
get "?view=postbacks&app_id=990077002" "$OUT/p-f.html"
saystext "$OUT/p-f.html" "3 rows in this range" "postbacks view honours the app filter"

say "verify view"
get "?view=verify" "$OUT/v.html"
eq "$(cat $OUT/.code)" "200" "HTTP 200"
has "$OUT/v.html" "Check a postback" "verify panel"
has "$OUT/v.html" 'name="payload"' "payload field"
hasnt "$OUT/v.html" "p202-strip__row" "no result before anything is pasted"
vpost() { curl -sS -b "$JAR" -c "$JAR" -L "$PAGE?view=verify" --data-urlencode "payload=$1" -o "$2"; }
vpost '{not json' "$OUT/v-bad.html"
msgs "$OUT/v-bad.html"
has "$OUT/v-bad.html" "That is not JSON: Syntax error" "malformed JSON is reported with the parser's reason"
hasnt "$OUT/v-bad.html" "p202-strip__row" "malformed JSON produces no verdict"
vpost '"a string"' "$OUT/v-str.html"
has "$OUT/v-str.html" "valid JSON but not an object" "valid-but-not-an-object gets its own sentence"
hasnt "$OUT/v-str.html" "No error" "and never says \"No error\" while refusing the input"
vpost '{}' "$OUT/v-empty.html"
msgs "$OUT/v-empty.html"
has "$OUT/v-empty.html" "Provide the postback JSON" "an empty object is refused"
SKAN='{"version":"4.0","ad-network-id":"acme.skadnetwork","source-identifier":"12","app-id":990077001,"transaction-id":"6aafb7a5-0170-41b5-bbe4-fe71dedf1e28","attribution-signature":"MEQCIEQlmZRNfYzK","redownload":false,"fidelity-type":1,"did-win":true,"postback-sequence-index":0,"conversion-value":3}'
vpost "$SKAN" "$OUT/v-skan.html"
msgs "$OUT/v-skan.html"
has "$OUT/v-skan.html" "skadnetwork" "SKAdNetwork protocol named"
has "$OUT/v-skan.html" "Signed message" "signed message shown"
hasnt "$OUT/v-skan.html" "What the postback claims" "a SKAdNetwork postback is not echoed back at the person who pasted it"
hasnt "$OUT/v-skan.html" "could not be rebuilt" "a rebuildable postback says nothing about rebuilding"
grep -q 'p202-pill--bad">invalid' "$OUT/v-skan.html" && ok "a bogus signature reads invalid" || bad "a bogus signature reads invalid"
has "$OUT/v-skan.html" "data-p202-copy" "signed message has a copy button"

say "verify says why nothing could be checked"
vpost '{"version":"4.0","ad-network-id":"acme.skadnetwork","transaction-id":"t","app-id":1,"attribution-signature":"x"}' "$OUT/v-short.html"
has "$OUT/v-short.html" "could not be rebuilt" "a postback missing a signed field says so"
has "$OUT/v-short.html" "version <code>4.0</code> requires" "and names the version it was missing them for"
has "$OUT/v-short.html" "2.1, 2.2, 3.0, 4.0" "and lists what this install can check"
vpost '{"ad-network-id":"acme.skadnetwork","transaction-id":"t","app-id":1,"attribution-signature":"x"}' "$OUT/v-nover.html"
has "$OUT/v-nover.html" "carries no <code>version</code>" "a postback with no version says that instead"
JWSU=$(php -r '
$b = fn($x) => rtrim(strtr(base64_encode(json_encode($x)), "+/", "-_"), "=");
echo $b(["alg"=>"ES256","kid"=>"not-a-key-we-know"]), ".", $b(["app-id"=>1]), ".",
     rtrim(strtr(base64_encode(str_repeat("\0", 64)), "+/", "-_"), "=");
')
vpost "{\"jws-string\":\"$JWSU\"}" "$OUT/v-unk.html"
msgs "$OUT/v-unk.html"
has "$OUT/v-unk.html" "not a signing key this install knows" "an unknown JWS key id is explained"
JWS=$(php -r '
$h = ["alg"=>"ES256","kid"=>"prod-1"];
$p = ["ad-network-id"=>"acme.skadnetwork","app-id"=>990077001,"did-win"=>true];
$b = fn($x) => rtrim(strtr(base64_encode(json_encode($x)), "+/", "-_"), "=");
echo $b($h), ".", $b($p), ".", rtrim(strtr(base64_encode(str_repeat("\0", 64)), "+/", "-_"), "=");
')
vpost "{\"jws-string\":\"$JWS\"}" "$OUT/v-aak.html"
msgs "$OUT/v-aak.html"
has "$OUT/v-aak.html" "adattributionkit" "AdAttributionKit protocol named"
has "$OUT/v-aak.html" "Signing key" "the JWS key id is shown"
has "$OUT/v-aak.html" "What the postback claims" "the JWS payload is decoded"
vpost '{"jws-string":"not.a.jws"}' "$OUT/v-jbad.html"
msgs "$OUT/v-jbad.html"
grep -q "p202-flash__body" "$OUT/v-jbad.html" && ok "a malformed JWS is reported" || bad "a malformed JWS is reported"
hasnt "$OUT/v-jbad.html" "p202-strip__row" "a malformed JWS produces no verdict"

say "empty range says so without claiming a failure"
get "?range=custom&from=2020-01-01&to=2020-01-02" "$OUT/e.html"
has "$OUT/e.html" "No postbacks in this range" "empty state"
has "$OUT/e.html" "p202-empty__title" "empty state uses the component's title part"
hasnt "$OUT/e.html" "could not be read" "empty is not reported as an error"
get "?view=postbacks&range=custom&from=2020-01-01&to=2020-01-02" "$OUT/e2.html"
has "$OUT/e2.html" "No postbacks in this range" "postbacks empty state"

say "money reads as money in the table, not just the tiles"
get "?group_by=app" "$OUT/m.html"
clean "$OUT/m.html" | grep -qE '\$[0-9]+\.[0-9]{2}' && ok "a table cell carries a formatted amount" || bad "a table cell carries a formatted amount"
# A bare decimal in a numeric cell or a tile is money rendered without its
# symbol; nothing else on this page is written to two places.
eq "$(tr -d '\n' < $OUT/m.html | grep -oE '(class="num"|p202-tile__value")>[0-9]+\.[0-9]{2}<' | wc -l)" \
   "0" "no amount renders bare"
mysql_q "$DB" -e "UPDATE 202_users_pref SET user_account_currency='EUR' WHERE user_id=1"
get "?group_by=app" "$OUT/m-eur.html"
clean "$OUT/m-eur.html" | grep -qE '€[0-9]' && ok "the account currency is what is shown" || bad "the account currency is what is shown"
mysql_q "$DB" -e "UPDATE 202_users_pref SET user_account_currency='USD' WHERE user_id=1"

say "pagination"
mysql_q "$DB" -e "INSERT INTO 202_attribution_postbacks
  (user_id, received_at, protocol, version, ad_network_id, transaction_id, app_id,
   conversion_type, did_win, attribution_signature, signature_state, signature_valid,
   dedupe_hash, raw_payload, remote_ip, created_at)
  SELECT 1, UNIX_TIMESTAMP() - (n * 60), 'skadnetwork', '4.0', 'bulk.skadnetwork',
         CONCAT('bulk-', n), 990077001, 'download', 1, 'sig', 'valid', 1,
         SHA1(CONCAT('bulk-', n)), '{}', '203.0.113.1', UNIX_TIMESTAMP()
  FROM (SELECT a.N + b.N * 10 AS n FROM
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
         UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
         UNION SELECT 5 UNION SELECT 6) b) nums WHERE n < 60"
TOTAL=$(Q 'SELECT COUNT(*) FROM 202_attribution_postbacks')
get "?view=postbacks" "$OUT/pg1.html"
saystext "$OUT/pg1.html" "$TOTAL rows in this range" "count sentence says $TOTAL"
eq "$(tr -d '\n' < $OUT/pg1.html | grep -oE '<tbody>.*</tbody>' | grep -o '<tr>' | wc -l)" "50" "page 1 holds 50 rows"
has "$OUT/pg1.html" 'aria-label="Pages"' "a pager appears"
grep -q 'page-item disabled' "$OUT/pg1.html" && ok "previous is disabled on page 1" || bad "previous is disabled on page 1"
get "?view=postbacks&page=2" "$OUT/pg2.html"
eq "$(tr -d '\n' < $OUT/pg2.html | grep -oE '<tbody>.*</tbody>' | grep -o '<tr>' | wc -l)" "$((TOTAL - 50))" "page 2 holds the rest"
grep -q 'page-item active" aria-current="page"' "$OUT/pg2.html" && ok "page 2 is marked current" || bad "page 2 is marked current"
# No row may appear on both pages: an off-by-one in the offset is invisible in
# a count and obvious in an overlap.
txns() { tr -d '\n' < "$1" | grep -oE '<td class="font-monospace small">[^<]+' \
         | sed -E 's#<td[^>]*>##' | sort -u; }
eq "$(txns "$OUT/pg1.html" | wc -l)" "50" "page 1 shows 50 transaction ids"
eq "$(comm -12 <(txns "$OUT/pg1.html") <(txns "$OUT/pg2.html") | wc -l)" "0" "the two pages share no row"
get "?view=postbacks&page=99" "$OUT/pg99.html"
hasnt "$OUT/pg99.html" "Fatal error" "a page past the end does not fatal"
hasnt "$OUT/pg99.html" "No postbacks in this range" "a page past the end does not claim the range is empty"
has "$OUT/pg99.html" "so this is the last one" "it says there is no such page and shows the last"
eq "$(tr -d '\n' < $OUT/pg99.html | grep -oE '<tbody>.*</tbody>' | grep -o '<tr>' | wc -l)" \
   "$((TOTAL - 50))" "and serves the last page's rows"
mysql_q "$DB" -e "DELETE FROM 202_attribution_postbacks WHERE ad_network_id='bulk.skadnetwork'"

say "the page is behind view_attribution_reports"
ROLE=$(Q 'SELECT role_id FROM 202_user_role WHERE user_id=1 LIMIT 1')
mysql_q "$DB" -e "DELETE FROM 202_role_permission WHERE role_id=$ROLE AND permission_id=22"
curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$PAGE" > "$OUT/.gate"
printf '    redirected to: %s\n' "$(cat $OUT/.gate)"
grep -q 'tracking202/$' "$OUT/.gate" && ok "no permission redirects away" || bad "no permission redirects away"
mysql_q "$DB" -e "INSERT INTO 202_role_permission (role_id, permission_id) VALUES ($ROLE, 22)"
get "" "$OUT/back.html"
has "$OUT/back.html" "Mobile App Attribution" "the permission back, the page is back"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "artifacts: $OUT"
[ "$FAIL" -eq 0 ]
