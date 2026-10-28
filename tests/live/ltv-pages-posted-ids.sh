#!/bin/bash
# Live pass: Customer LTV's pages read the record a button or the merge dialog
# names exactly, and refuse anything else in their own words. Companies'
# Merge read company_id and source_company_id with (int), so "12abc" merged
# company 12: one of the account's own, not the one named; the customer
# page's Merge, Erase and Unlink, and Products' Delete, read their ids the
# same way.
#
#   1. signed in through 202-login.php's own form; two companies, two
#      customers (the first with a linked identity) and a product made in the
#      database for that account;
#   2. Companies' Merge as its dialog posts it (merge_company, the kept id,
#      the one merged away, the page's token) with the merged-away id as
#      Nabc, 0, -1, empty and absent, then the kept id as Nabc: each refused
#      with the page's sentence, both companies still there; then the exact
#      ids merge them;
#   3. the customer page: Merge with source_customer_id Nabc, and Erase, Link
#      Identity and Unlink with customer_id Nabc, and Unlink with alias_id
#      Nabc: each refused, nothing merged, erased, linked or unlinked; then
#      the exact alias_id unlinks it;
#   4. Products' Delete with Nabc refused; the merge dialog's search with
#      exclude Nabc answered 422 with what to pick; Companies' Delete posted
#      with offset 50abc refused and nothing deleted.
#
# A button's POST is the one its script makes (the action, the ids, the
# page's own token). Deletes what it made on exit.
#
#   P202_BASE=http://127.0.0.1:8155 P202_DB=p202_test P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_USER=... P202_PASS=... \
#   bash tests/live/ltv-pages-posted-ids.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
PHP=${P202_PHP:-php}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is required: this pass signs in to press the buttons" >&2
    exit 2
fi
for tool in curl mysql python3; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
done

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

PASS=0; FAIL=0
WORK=$(mktemp -d)
JAR="$WORK/jar"
AJAX="$BASE/tracking202/ajax"
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
# The flashes the page drew, their entities decoded.
flash() { "$PHP" -r '$h = (string) file_get_contents($argv[1]); preg_match_all("#p202-flash__body\">(.*?)</div>#s", $h, $m); echo html_entity_decode(strip_tags(implode(" | ", $m[1])), ENT_QUOTES | ENT_HTML5, "UTF-8");' "$1"; }
enc() { "$PHP" -r 'echo rawurlencode($argv[1]);' -- "$1"; }

COMPANY_A=; COMPANY_B=; CUSTOMER_A=; CUSTOMER_B=; ALIAS=; PRODUCT=
cleanup() {
    [ -n "$CUSTOMER_A$CUSTOMER_B" ] && Q "DELETE FROM 202_customer_aliases WHERE customer_id IN (0$CUSTOMER_A, 0$CUSTOMER_B);
        DELETE FROM 202_customers WHERE customer_id IN (0$CUSTOMER_A, 0$CUSTOMER_B)"
    [ -n "$COMPANY_A$COMPANY_B" ] && Q "DELETE FROM 202_companies WHERE company_id IN (0$COMPANY_A, 0$COMPANY_B)"
    [ -n "$PRODUCT" ] && Q "DELETE FROM 202_products WHERE product_id = $PRODUCT"
    rm -rf "$WORK"
}
trap cleanup EXIT

# A button's POST: "field=value" pairs as given, a value of - leaving the field
# out, and the page's token. $1 page, $2 output, the pairs after.
press() {
    local page=$1 out=$2 body="token=$TOKEN" pair
    shift 2
    for pair in "$@"; do
        [ "${pair#*=}" = "-" ] && continue
        body="$body&${pair%%=*}=$(enc "${pair#*=}")"
    done
    printf '%s' "$body" > "$out.sent"
    curl -sS -b "$JAR" -c "$JAR" -o "$out" -w '%{http_code}' --data "$body" "$AJAX/$page" > "$out.status"
}
count() { Q "SELECT COUNT(*) FROM $1 WHERE $2"; }

say "1. signed in; two companies, two customers and a product for that account"
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/login.html" "$BASE/202-login.php"
python3 "$HERE/form-body.py" "$WORK/login.html" user_name "user_name=$P202_USER" "user_pass=$P202_PASS" > "$WORK/login.body" || exit 2
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-binary @"$WORK/login.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-login.php"
U=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$P202_USER'")
[[ "$U" =~ ^[1-9][0-9]*$ ]] || { echo "no user '$P202_USER' in $DB" >&2; exit 2; }
NOW=$(date +%s)
COMPANY_A=$(Q "INSERT INTO 202_companies (user_id, name, normalized_name, created_at, updated_at)
    VALUES ($U, 'Posted Ids Acme', 'posted ids acme', $NOW, $NOW); SELECT LAST_INSERT_ID()")
COMPANY_B=$(Q "INSERT INTO 202_companies (user_id, name, normalized_name, created_at, updated_at)
    VALUES ($U, 'Posted Ids Beta', 'posted ids beta', $NOW, $NOW); SELECT LAST_INSERT_ID()")
CUSTOMER_A=$(Q "INSERT INTO 202_customers (user_id, primary_ref, first_name, email, first_seen_time, last_activity_time, created_at, updated_at)
    VALUES ($U, 'posted-ids-a', 'Ada', 'ada@posted-ids.example', $NOW, $NOW, $NOW, $NOW); SELECT LAST_INSERT_ID()")
CUSTOMER_B=$(Q "INSERT INTO 202_customers (user_id, primary_ref, first_name, email, first_seen_time, last_activity_time, created_at, updated_at)
    VALUES ($U, 'posted-ids-b', 'Bob', 'bob@posted-ids.example', $NOW, $NOW, $NOW, $NOW); SELECT LAST_INSERT_ID()")
ALIAS=$(Q "INSERT INTO 202_customer_aliases (user_id, customer_id, alias_type, alias_value, alias_hash, created_at)
    VALUES ($U, $CUSTOMER_A, 'custom', 'posted-ids-1', UNHEX(SHA2('posted-ids-1', 256)), $NOW); SELECT LAST_INSERT_ID()")
PRODUCT=$(Q "INSERT INTO 202_products (user_id, external_product_id, name, created_at, updated_at)
    VALUES ($U, 'posted-ids-sku', 'Posted Ids Widget', $NOW, $NOW); SELECT LAST_INSERT_ID()")
for v in "$COMPANY_A" "$COMPANY_B" "$CUSTOMER_A" "$CUSTOMER_B" "$ALIAS" "$PRODUCT"; do
    [[ "$v" =~ ^[1-9][0-9]*$ ]] || { echo "the rows were not made" >&2; exit 2; }
done
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/companies.html" --data '' "$AJAX/ltv_companies.php"
TOKEN=$(grep -o 'token: "[0-9a-f]*"' "$WORK/companies.html" | head -1 | sed 's/token: "//; s/"//')
[ -n "$TOKEN" ] || { echo "the companies page did not render signed in: $(head -c 300 "$WORK/companies.html")" >&2; exit 2; }
grep -q "ltvCompanyMerge({&quot;id&quot;:$COMPANY_B,\|ltvCompanyMerge({\"id\":$COMPANY_B," "$WORK/companies.html" \
    && ok "company #$COMPANY_B's row has its Merge button" || bad "no Merge button for company #$COMPANY_B"

companies_there() { count 202_companies "company_id IN ($COMPANY_A, $COMPANY_B)"; }

say "2. Companies' Merge reads both ids exactly"
for v in "${COMPANY_B}abc" 0 -1 '' -; do
    press ltv_companies.php "$WORK/out.html" action=merge_company "company_id=$COMPANY_A" "source_company_id=$v"
    eq "$(flash "$WORK/out.html")" 'Pick the company to merge away from the list.' "source_company_id '$v' ($(cat "$WORK/out.html.sent" | sed "s/token=$TOKEN/token=…/")) is refused"
    eq "$(companies_there)" 2 'and both companies are still there'
done
press ltv_companies.php "$WORK/out.html" action=merge_company "company_id=${COMPANY_A}abc" "source_company_id=$COMPANY_B"
eq "$(flash "$WORK/out.html")" 'Pick the company to keep from the list.' "company_id '${COMPANY_A}abc' is refused"
eq "$(companies_there)" 2 'and both companies are still there'
press ltv_companies.php "$WORK/out.html" action=merge_company "company_id=$COMPANY_A" "source_company_id=$COMPANY_B"
eq "$(flash "$WORK/out.html")" 'Companies merged.' "the exact ids merge #$COMPANY_B into #$COMPANY_A"
eq "$(count 202_companies "company_id = $COMPANY_B")" 0 "company #$COMPANY_B is gone"
eq "$(count 202_companies "company_id = $COMPANY_A")" 1 "company #$COMPANY_A is kept"
COMPANY_B=

customer_state() { Q "SELECT CONCAT_WS(',', IFNULL(first_name, 'erased'), IFNULL(merged_into_customer_id, 'unmerged')) FROM 202_customers WHERE customer_id = $1"; }
aliases_of_a() { count 202_customer_aliases "customer_id = $CUSTOMER_A"; }

say "3. the customer page reads the customer, the one merged away and the identity exactly"
curl -sS -b "$JAR" -c "$JAR" -o "$WORK/customer.html" --data "customer_id=$CUSTOMER_A" "$AJAX/ltv_customer.php"
grep -q "ltvAliasDelete($CUSTOMER_A, $ALIAS)" "$WORK/customer.html" \
    && ok "identity #$ALIAS's row has its Unlink button" || bad "no Unlink button for identity #$ALIAS"
for v in "${CUSTOMER_B}abc" 0 -1 '' -; do
    press ltv_customer.php "$WORK/out.html" action=merge "customer_id=$CUSTOMER_A" "source_customer_id=$v"
    eq "$(flash "$WORK/out.html")" 'Pick the customer to merge away from the list.' "Merge with source_customer_id '$v' is refused"
done
eq "$(customer_state "$CUSTOMER_B")" 'Bob,unmerged' "customer #$CUSTOMER_B is not merged"
for action in erase add_alias delete_alias; do
    press ltv_customer.php "$WORK/out.html" "action=$action" "customer_id=${CUSTOMER_A}abc" \
        "alias_type=custom" "alias_value=posted-ids-2" "alias_id=$ALIAS"
    eq "$(flash "$WORK/out.html")" 'Pick the customer from the Customer LTV list.' "$action with customer_id '${CUSTOMER_A}abc' is refused"
done
eq "$(customer_state "$CUSTOMER_A")" 'Ada,unmerged' "customer #$CUSTOMER_A is not erased"
eq "$(aliases_of_a)" 1 'and has its one identity: none linked, none unlinked'
for v in "${ALIAS}abc" 0 -; do
    press ltv_customer.php "$WORK/out.html" action=delete_alias "customer_id=$CUSTOMER_A" "alias_id=$v"
    eq "$(flash "$WORK/out.html")" 'Pick the identity to unlink from the list.' "Unlink with alias_id '$v' is refused"
done
eq "$(aliases_of_a)" 1 'and the identity is still linked'
press ltv_customer.php "$WORK/out.html" action=delete_alias "customer_id=$CUSTOMER_A" "alias_id=$ALIAS"
eq "$(flash "$WORK/out.html")" 'Customer updated.' "alias_id '$ALIAS' unlinks it"
eq "$(aliases_of_a)" 0 'and it is gone'

say "4. Products' Delete, the merge search and a page nobody can name"
press ltv_products.php "$WORK/out.html" action=delete_product "product_id=${PRODUCT}abc"
eq "$(flash "$WORK/out.html")" 'Pick the product to delete from the list.' "product_id '${PRODUCT}abc' is refused"
eq "$(count 202_products "product_id = $PRODUCT")" 1 'and the product is still there'
press ltv_merge_search.php "$WORK/out.json" entity=company q=Posted "exclude=${COMPANY_A}abc"
eq "$(cat "$WORK/out.json.status") $(cat "$WORK/out.json")" '422 {"results":[],"error":"Pick the record to merge into from the list."}' \
    "the search with exclude '${COMPANY_A}abc' is refused"
press ltv_merge_search.php "$WORK/out.json" entity=company q=Posted "exclude=$COMPANY_A"
eq "$(cat "$WORK/out.json.status") $(cat "$WORK/out.json")" '200 {"results":[]}' "exclude '$COMPANY_A' leaves out the one merged into"
press ltv_companies.php "$WORK/out.html" action=delete_company "company_id=$COMPANY_A" offset=50abc
eq "$(flash "$WORK/out.html")" 'Pick a page with Previous or Next.' "a Delete with offset '50abc' is refused"
eq "$(count 202_companies "company_id = $COMPANY_A")" 1 'and nothing is deleted'

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
