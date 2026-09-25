#!/bin/bash
# Upgrade equals install (plan §7.6), from a real 1.9.55 database.
#
# The only upgrade origin this release has is 1.9.55: nothing after it was
# ever installed. So the one upgrade path to prove is 1.9.55 -> the code
# version, and the proof is that it lands on exactly the schema a fresh
# install creates:
#
#   1. install 1.9.55 with its own installer, from its own tree, over HTTP
#      (git archive of P202_ORIGIN_REF; a full-history clone is needed);
#   2. upgrade that database with this checkout, through the upgrade page a
#      user opens (GET the form, POST its own fields, token included);
#   3. install this checkout fresh into a second database, with the same
#      installer an operator runs (tests/fixtures/agent-eval/ci/install-instance.sh);
#   4. compare SHOW CREATE TABLE for every table of the two
#      (tests/live/schema-diff.php). Only what SchemaReconciler's docblock
#      lists as metadata may differ — index order and the table comment —
#      plus the AUTO_INCREMENT counter. A missing or extra table is a
#      difference.
#
# Which commit is 1.9.55. 202-config/version.php never read 1.9.55: it was
# created at 1.9.56 (f4fe692). Before it the version lived in connect.php,
# which read 1.9.55 from 4589787 ("Upgrade to 1.9.55", 2023-03-10), the
# release, until 75a381b moved it to 1.9.56. The commits in between are the
# 1.9.56 development line with the old string still in place; the last of
# them (fc613ef) cannot install at all (its installer dies on a strict_types
# TypeError before creating the account). The release is what every 1.9.55
# database was installed from, so it is the default origin.
#
# The release predates PHP 8 (it uses `$string{0}` offsets, a parse error
# since 8.0), so it runs on the PHP named by P202_ORIGIN_PHP, 7.4 by default —
# the PHP it shipped for. The upgrade and the fresh install run on `php`.
#
# --- environment -------------------------------------------------------
# P202_ORIGIN_REF      the 1.9.55 commit (4589787…)
# P202_ORIGIN_VERSION  what its 202_version must read after install (1.9.55)
# P202_ORIGIN_PHP      PHP binary for the origin tree (php7.4)
# P202_DB_UPGRADE      scratch DB installed at 1.9.55, then upgraded (p202_uei_upgrade_test)
# P202_DB_FRESH        scratch DB for the fresh install (p202_uei_fresh_test)
# P202_DB_HOST / P202_DB_PORT / P202_DB_USER / P202_DB_PASS
#                      MySQL over TCP (127.0.0.1 / 3306 / root / empty); the
#                      user must be able to DROP and CREATE both databases
# P202_UEI_PORT        HTTP port for the origin; the new code uses PORT+1 (8195)
# P202_UEI_WORKDIR     where the two trees are unpacked (mktemp -d)
# P202_UEI_KEEP        1 keeps the databases and the work dir for inspection
#
# Both databases are DROPPED and recreated: guard.sh must accept both names.

set -uo pipefail

ORIGIN_REF=${P202_ORIGIN_REF:-45897876895372162e83209167e750e078d3e3e5}
ORIGIN_VERSION=${P202_ORIGIN_VERSION:-1.9.55}
ORIGIN_PHP=${P202_ORIGIN_PHP:-php7.4}
DB_UP=${P202_DB_UPGRADE:-p202_uei_upgrade_test}
DB_FRESH=${P202_DB_FRESH:-p202_uei_fresh_test}
DB_HOST=${P202_DB_HOST:-127.0.0.1}
DB_PORT=${P202_DB_PORT:-3306}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
PORT=${P202_UEI_PORT:-8195}
NEW_PORT=$((PORT + 1))
KEEP=${P202_UEI_KEEP:-0}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB_UP" || exit 2
p202_require_scratch_db "$DB_FRESH" || exit 2
if [ "$DB_UP" = "$DB_FRESH" ]; then
    echo "P202_DB_UPGRADE and P202_DB_FRESH must be two databases" >&2
    exit 2
fi

WORK=${P202_UEI_WORKDIR:-$(mktemp -d)}
mkdir -p "$WORK"

MYSQL_ARGS=(-h "$DB_HOST" --protocol=TCP -P "$DB_PORT" -u "$DB_USER")
# The password through the environment: MySQL 8's client warns on every
# -p call, which buries the report.
mysql_q() { MYSQL_PWD="$DB_PASS" mysql "${MYSQL_ARGS[@]}" "$@"; }

PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
# A step the rest depends on: stop here, with the reason, rather than
# comparing whatever a failed step left behind.
die()  { bad "$1"; finish; }

SERVER_PIDS=()
stop_servers() {
    local pid
    for pid in "${SERVER_PIDS[@]}"; do
        kill "$pid" 2>/dev/null
        wait "$pid" 2>/dev/null
    done
    SERVER_PIDS=()
}
finish() {
    stop_servers
    if [ "$KEEP" != 1 ]; then
        mysql_q -e "DROP DATABASE IF EXISTS \`$DB_UP\`; DROP DATABASE IF EXISTS \`$DB_FRESH\`;" 2>/dev/null
        [ -z "${P202_UEI_WORKDIR:-}" ] && rm -rf "$WORK"
    else
        printf '\nkept: databases %s and %s, work dir %s\n' "$DB_UP" "$DB_FRESH" "$WORK"
    fi
    printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
    [ "$FAIL" -eq 0 ] && exit 0
    exit 1
}
trap 'stop_servers' EXIT

# Point a tree's 202-config.php at a database. Both samples are handled: the
# release's has literal values ('localhost'), the current one placeholders,
# so the value of each variable is replaced, whatever it is.
write_config() { # $1 = tree, $2 = database
    local esc_db esc_user esc_pass esc_host
    esc() { printf '%s' "$1" | sed -e 's/[\\/&]/\\&/g'; }
    esc_db=$(esc "$2"); esc_user=$(esc "$DB_USER"); esc_pass=$(esc "$DB_PASS"); esc_host=$(esc "$DB_HOST:$DB_PORT")
    sed -E \
        -e "s/^\\\$dbname = '[^']*'/\$dbname = '$esc_db'/" \
        -e "s/^\\\$dbuser = '[^']*'/\$dbuser = '$esc_user'/" \
        -e "s/^\\\$dbpass = '[^']*'/\$dbpass = '$esc_pass'/" \
        -e "s/^\\\$dbhost = '[^']*'/\$dbhost = '$esc_host'/" \
        -e "s/^\\\$dbhostro = '[^']*'/\$dbhostro = '$esc_host'/" \
        -e "s/^\\\$mchost = '[^']*'/\$mchost = '127.0.0.1'/" \
        "$1/202-config-sample.php" > "$1/202-config.php"
    grep -qF "'$2'" "$1/202-config.php"
}

wait_for() { # $1 = url, $2 = server log
    local i
    for i in $(seq 1 30); do
        curl -s -o /dev/null "$1" && return 0
        sleep 1
    done
    echo "server did not answer $1; last log lines:" >&2
    tail -5 "$2" >&2
    return 1
}

# ---------------------------------------------------------------------------
say "prerequisites"
if ! git -C "$REPO" cat-file -e "$ORIGIN_REF^{commit}" 2>/dev/null; then
    die "the 1.9.55 commit $ORIGIN_REF is not in this clone; fetch full history (git fetch --unshallow origin, or actions/checkout with fetch-depth: 0)"
fi
ok "origin commit $(git -C "$REPO" rev-parse --short "$ORIGIN_REF") is present"
if ! command -v "$ORIGIN_PHP" >/dev/null 2>&1; then
    die "P202_ORIGIN_PHP=$ORIGIN_PHP is not on PATH; 1.9.55 needs a PHP 7 interpreter with mysqli"
fi
ok "origin PHP: $("$ORIGIN_PHP" -r 'echo PHP_VERSION;')"
CODE=$(php -r 'require $argv[1]; echo PROSPER202_VERSION;' "$REPO/202-config/version.php")
[ -n "$CODE" ] || die "could not read PROSPER202_VERSION from this checkout"
ok "code version: $CODE"
for db in "$DB_UP" "$DB_FRESH"; do
    mysql_q -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` DEFAULT CHARACTER SET utf8mb4;" \
        || die "could not recreate $db as $DB_USER"
done
ok "recreated $DB_UP and $DB_FRESH"

# ---------------------------------------------------------------------------
say "1. install $ORIGIN_VERSION from its own tree"
ORIGIN="$WORK/origin"
rm -rf "$ORIGIN"; mkdir -p "$ORIGIN"
git -C "$REPO" archive "$ORIGIN_REF" | tar -x -C "$ORIGIN" || die "git archive $ORIGIN_REF failed"
TREE_VERSION=$(grep -hoE "^\\\$version = '[^']*'" "$ORIGIN/202-config/connect.php" | sed "s/.*'\\(.*\\)'/\\1/")
eq "$TREE_VERSION" "$ORIGIN_VERSION" "the origin tree's connect.php reads $ORIGIN_VERSION"
write_config "$ORIGIN" "$DB_UP" || die "could not write the origin's 202-config.php"

"$ORIGIN_PHP" -S "127.0.0.1:$PORT" -t "$ORIGIN" >"$WORK/origin-server.log" 2>&1 &
SERVER_PIDS+=($!)
wait_for "http://127.0.0.1:$PORT/202-config/install.php" "$WORK/origin-server.log" || die "the origin tree did not serve"

JAR="$WORK/origin.jar"
# The installer wants the customer-key cookie; any value skips the fetch-a-key page.
curl -sS -c "$JAR" -b "user_api=upgrade-equals-install" "http://127.0.0.1:$PORT/202-config/install.php" -o "$WORK/origin-form.html"
FIELDS=(-d "user_email=uei@example.com" -d "user_name=ueiadmin" -d "user_pass=uei-secret-1"
        -d "verify_user_pass=uei-secret-1" -d "user_timezone=America/New_York" -d "user_api=upgrade-equals-install")
# 1.9.55 has no token on this form; send one if a later origin does.
TOKEN=$(grep -oE '<input[^>]*name="token"[^>]*>' "$WORK/origin-form.html" | head -1 | grep -oE 'value="[^"]*"' | sed 's/^value="//; s/"$//')
[ -n "$TOKEN" ] && FIELDS+=(-d "token=$TOKEN")
STATUS=$(curl -sS -b "$JAR" -c "$JAR" -b "user_api=upgrade-equals-install" --max-time 600 \
    "${FIELDS[@]}" "http://127.0.0.1:$PORT/202-config/install.php" -o "$WORK/origin-result.html" -w '%{http_code}')
eq "$STATUS" 200 "the $ORIGIN_VERSION installer answered 200"
grep -qF 'Success!' "$WORK/origin-result.html" || die "the $ORIGIN_VERSION installer did not say Success! (see $WORK/origin-result.html, $WORK/origin-server.log)"
ok "the $ORIGIN_VERSION installer said Success!"
eq "$(mysql_q -N "$DB_UP" -e 'SELECT version FROM 202_version')" "$ORIGIN_VERSION" "202_version reads $ORIGIN_VERSION"
eq "$(mysql_q -N "$DB_UP" -e 'SELECT COUNT(*) FROM 202_users')" 1 "the installer created its account"
ORIGIN_TABLES=$(mysql_q -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_UP'")
ok "$ORIGIN_VERSION created $ORIGIN_TABLES tables"
stop_servers

# ---------------------------------------------------------------------------
# This checkout, copied so its own 202-config.php (which may name a live
# instance) is never touched. What is not served is left out.
NEW="$WORK/new"
mkdir -p "$NEW"
rsync -a --delete --exclude .git --exclude /202-config.php --exclude node_modules \
    --exclude /go-cli --exclude /sdk --exclude /documentation "$REPO/" "$NEW/" || die "could not copy this checkout"

say "2. install $CODE fresh"
write_config "$NEW" "$DB_FRESH" || die "could not write 202-config.php for $DB_FRESH"
mkdir -p "$WORK/fresh-runtime"
KEY=$(cd "$NEW" && P202_DB_HOST="$DB_HOST" P202_DB_PORT="$DB_PORT" P202_DB_NAME="$DB_FRESH" \
    P202_DB_USER="$DB_USER" P202_DB_PASS="$DB_PASS" P202_HTTP_PORT="$NEW_PORT" \
    P202_ADMIN_EMAIL=uei@example.com P202_ADMIN_NAME=ueiadmin P202_ADMIN_PASS=uei-secret-1 \
    P202_EVAL_RUNTIME_DIR="$WORK/fresh-runtime" \
    bash tests/fixtures/agent-eval/ci/install-instance.sh 2>"$WORK/fresh-install.log")
FRESH_PID=$(cat "$WORK/fresh-runtime/php-server.pid" 2>/dev/null)
[ -n "$FRESH_PID" ] && SERVER_PIDS+=("$FRESH_PID")
[ -n "$KEY" ] || die "the fresh install did not finish (see $WORK/fresh-install.log)"
ok "the fresh install finished and minted a REST key"
eq "$(mysql_q -N "$DB_FRESH" -e 'SELECT version FROM 202_version')" "$CODE" "fresh 202_version reads $CODE"
stop_servers

# ---------------------------------------------------------------------------
say "3. upgrade the $ORIGIN_VERSION database through the upgrade page"
write_config "$NEW" "$DB_UP" || die "could not write 202-config.php for $DB_UP"
# -t before the router: anything after the router script is its argv, and
# the docroot would silently be the current directory (this checkout, with
# its own 202-config.php) — measured, it served the wrong tree.
php -S "127.0.0.1:$NEW_PORT" -t "$NEW" "$NEW/tests/fixtures/agent-eval/ci/router.php" \
    >"$WORK/upgrade-server.log" 2>&1 &
SERVER_PIDS+=($!)
URL="http://127.0.0.1:$NEW_PORT/202-config/upgrade.php"
wait_for "$URL" "$WORK/upgrade-server.log" || die "this checkout did not serve"
JAR="$WORK/upgrade.jar"
STATUS=$(curl -sS -c "$JAR" -b "$JAR" "$URL" -o "$WORK/upgrade-get.html" -w '%{http_code}')
eq "$STATUS" 200 "the upgrade page answered 200"
# The fields a browser submits from the form, as upgrade-csrf.sh reads them.
tr '\n' ' ' < "$WORK/upgrade-get.html" | grep -oE '<form[^>]*id="upgrade-form"[^>]*>.*' | sed 's#</form>.*##' | head -1 > "$WORK/upgrade-form.html"
[ -s "$WORK/upgrade-form.html" ] || die "the upgrade page rendered no upgrade form (see $WORK/upgrade-get.html)"
SUBMIT=()
while IFS= read -r tag; do
    name=$(printf '%s' "$tag" | grep -oE 'name="[^"]*"' | head -1 | sed 's/^name="//; s/"$//')
    [ -n "$name" ] || continue
    type=$(printf '%s' "$tag" | grep -oiE 'type="[^"]*"' | head -1 | sed 's/^[^"]*"//; s/"$//' | tr '[:upper:]' '[:lower:]')
    value=$(printf '%s' "$tag" | grep -oE 'value="[^"]*"' | head -1 | sed 's/^value="//; s/"$//')
    case "$type" in
        radio|checkbox) printf '%s' "$tag" | grep -qiE '[[:space:]]checked([[:space:]]|=|/|>)' || continue ;;
        submit|button|image|reset|file) continue ;;
    esac
    SUBMIT+=(--data-urlencode "$name=$value")
done < <(grep -oE '<input[^>]*>' "$WORK/upgrade-form.html")
printf '%s\n' "${SUBMIT[@]}" | grep -q '^token=' || die "the upgrade form carries no token"
STATUS=$(curl -sS -c "$JAR" -b "$JAR" --max-time 900 "$URL" "${SUBMIT[@]}" -o "$WORK/upgrade-post.html" -w '%{http_code}')
eq "$STATUS" 200 "the upgrade POST answered 200"
grep -qF 'Success!' "$WORK/upgrade-post.html" || die "the upgrade did not say Success! (see $WORK/upgrade-post.html, $WORK/upgrade-server.log)"
ok "the upgrade said Success!"
eq "$(mysql_q -N "$DB_UP" -e 'SELECT version FROM 202_version')" "$CODE" "upgraded 202_version reads $CODE"
# The ladder logs what it could not do and carries on where it safely can;
# a line here is a step that did not converge, whatever the page said.
UPGRADE_ERRORS=$(grep -cE 'Prosper202 upgrade|PHP (Fatal|Warning|Parse)' "$WORK/upgrade-server.log")
if [ "$UPGRADE_ERRORS" -eq 0 ]; then
    ok "the upgrade logged no error or warning"
else
    bad "the upgrade logged $UPGRADE_ERRORS error/warning line(s):"
    grep -E 'Prosper202 upgrade|PHP (Fatal|Warning|Parse)' "$WORK/upgrade-server.log" | head -20 | sed 's/^/      /'
fi
stop_servers

# ---------------------------------------------------------------------------
say "4. compare every table: upgraded $ORIGIN_VERSION against fresh $CODE"
if P202_DB_HOST="$DB_HOST" P202_DB_PORT="$DB_PORT" P202_DB_USER="$DB_USER" P202_DB_PASS="$DB_PASS" \
    php "$HERE/schema-diff.php" "$DB_UP" "$DB_FRESH" > "$WORK/schema-diff.txt" 2>&1; then
    ok "$(head -1 "$WORK/schema-diff.txt"): identical up to index order, table comment, partition boundaries and AUTO_INCREMENT"
else
    bad "the upgraded schema is not the fresh install's:"
    sed 's/^/      /' "$WORK/schema-diff.txt"
fi

# ---------------------------------------------------------------------------
say "5. compare the rows both installs seed"
# The same schema with different seed rows is not the same install: a
# permission the ladder never granted, or a lookup row it never wrote, leaves
# an upgraded install behaving differently. Every table is compared with
# CHECKSUM TABLE except the ones whose rows are the install's own by nature:
#   202_users, 202_api_keys  the account and its key (1.9.55 minted no REST key)
#   202_deployment_secrets   random per install
#   202_users_pref           auto_cron records whether a remote registration
#                            call answered, which the network decides
#   202_attribution_models   compared below without its timestamps
ROW_EXEMPT=' 202_users 202_api_keys 202_deployment_secrets 202_users_pref 202_attribution_models '
ROWS_COMPARED=0
ROW_DIFFS=()
while IFS= read -r table; do
    [[ "$ROW_EXEMPT" == *" $table "* ]] && continue
    up_sum=$(mysql_q -N -e "CHECKSUM TABLE \`$DB_UP\`.\`$table\`" 2>/dev/null | awk '{print $2}')
    fresh_sum=$(mysql_q -N -e "CHECKSUM TABLE \`$DB_FRESH\`.\`$table\`" | awk '{print $2}')
    if [ -z "$fresh_sum" ] || [ "$fresh_sum" = "NULL" ]; then
        ROW_DIFFS+=("$table: could not checksum the fresh install's rows")
    elif [ -z "$up_sum" ] || [ "$up_sum" = "NULL" ]; then
        # A table missing after the upgrade is already a difference above.
        ROW_DIFFS+=("$table: no rows to compare after the upgrade (the table is missing)")
    elif [ "$up_sum" != "$fresh_sum" ]; then
        ROW_DIFFS+=("$table: $(mysql_q -N -e "SELECT COUNT(*) FROM \`$DB_UP\`.\`$table\`") row(s) after the upgrade, $(mysql_q -N -e "SELECT COUNT(*) FROM \`$DB_FRESH\`.\`$table\`") in a fresh install, contents differ")
    fi
    ROWS_COMPARED=$((ROWS_COMPARED + 1))
done < <(mysql_q -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_FRESH' ORDER BY table_name")
if [ "${#ROW_DIFFS[@]}" -eq 0 ] && [ "$ROWS_COMPARED" -gt 100 ]; then
    ok "the rows of $ROWS_COMPARED tables are identical"
else
    bad "seed rows differ in ${#ROW_DIFFS[@]} of $ROWS_COMPARED tables:"
    printf '      %s\n' "${ROW_DIFFS[@]}"
fi
MODELS="SELECT user_id, model_name, model_slug, model_type, weighting_config, lookback_days, status, is_default FROM 202_attribution_models ORDER BY model_id"
eq "$(mysql_q -N "$DB_UP" -e "$MODELS")" "$(mysql_q -N "$DB_FRESH" -e "$MODELS")" \
    "the account's default attribution model is the one a fresh install gives it"

finish
