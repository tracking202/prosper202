#!/usr/bin/env bash
#
# Stand up a throwaway Prosper202 instance for agent evals: write
# 202-config.php, serve the repo with PHP's built-in server (through
# router.php, so the dotted SKAN receiver URL resolves on PHP < 8.4), drive
# the real web installer headlessly the way a browser takes it (the license
# key step, then the CSRF token + form POST), and probe the postback receiver
# URLs. Prints exactly one line to stdout on success: the REST API key of the
# account it created. Everything else goes to stderr.
#
# Requirements: a reachable MySQL, PHP with the app's extensions, curl, and
# a full `composer install` (the click path needs ua-parser/uap-php's
# shipped regexes). Run from the repository root. The license check must
# answer "Key valid" for the customer key: in CI that is the my.tracking202.com
# stub (licence-stub.sh); anywhere else the key has to be a real one.
#
# Environment (defaults in parentheses):
#   P202_DB_HOST (127.0.0.1)  P202_DB_PORT (3306)  P202_DB_NAME (prosper202)
#   P202_DB_USER (root)       P202_DB_PASS (root)
#   P202_HTTP_HOST (127.0.0.1)  P202_HTTP_PORT (8000)
#   P202_ADMIN_EMAIL (eval-ci@example.com)  P202_ADMIN_NAME (evalci)
#   P202_ADMIN_PASS (generated when unset)
#   P202_CUSTOMER_KEY (agent-eval-ci) — the my.tracking202.com key
#   P202_EVAL_RUNTIME_DIR (mktemp -d)  — server log, cookie jar, pid file

set -euo pipefail

DB_HOST="${P202_DB_HOST:-127.0.0.1}"
DB_PORT="${P202_DB_PORT:-3306}"
DB_NAME="${P202_DB_NAME:-prosper202}"
DB_USER="${P202_DB_USER:-root}"
DB_PASS="${P202_DB_PASS:-root}"
HTTP_HOST="${P202_HTTP_HOST:-127.0.0.1}"
HTTP_PORT="${P202_HTTP_PORT:-8000}"
ADMIN_EMAIL="${P202_ADMIN_EMAIL:-eval-ci@example.com}"
ADMIN_NAME="${P202_ADMIN_NAME:-evalci}"
# The installer caps passwords at 35 characters; 5 + 24 hex = 29.
ADMIN_PASS="${P202_ADMIN_PASS:-eval-$(head -c12 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
CUSTOMER_KEY="${P202_CUSTOMER_KEY:-agent-eval-ci}"
RUNTIME_DIR="${P202_EVAL_RUNTIME_DIR:-$(mktemp -d)}"
BASE_URL="http://$HTTP_HOST:$HTTP_PORT"

log() { printf '%s\n' "$*" >&2; }

if [ ! -f 202-config-sample.php ]; then
    log "install-instance.sh: run from the repository root (202-config-sample.php not found)"
    exit 1
fi

if [ -f 202-config.php ]; then
    log "202-config.php already exists; leaving it in place"
else
    log "Writing 202-config.php for $DB_USER@$DB_HOST/$DB_NAME"
    # Credentials are data, not sed syntax: \, / and & in a replacement
    # would corrupt the config silently (php -l still passes) or abort sed.
    esc() { printf '%s' "$1" | sed -e 's/[\\/&]/\\&/g'; }
    sed -e "s/putyourdbnamehere/$(esc "$DB_NAME")/" \
        -e "s/usernamehere/$(esc "$DB_USER")/" \
        -e "s/yourpasswordhere/$(esc "$DB_PASS")/" \
        -e "s/localhosthere/$(esc "$DB_HOST:$DB_PORT")/" \
        -e "s/localhostreplica/$(esc "$DB_HOST:$DB_PORT")/" \
        -e "s/localhostmemcache/127.0.0.1/" \
        202-config-sample.php > 202-config.php
    php -l 202-config.php >/dev/null
fi

log "Serving on $BASE_URL (log: $RUNTIME_DIR/php-server.log)"
# Before PHP 8.4 the built-in server never resolves the directory index of a
# path containing a "." — /.well-known/skadnetwork/report-attribution/, the
# SKAN receiver, is a 404 without help. router.php (next to this script)
# fills that gap and passes every other request through untouched.
ROUTER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/router.php"
# More than one worker: the cron's report rebuild calls this server back
# (202-cronjobs/process_dataengine_job.php fetches dej.php), and a single
# worker busy with the cron request can never answer it.
PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-4} \
    php -S "$HTTP_HOST:$HTTP_PORT" "$ROUTER" >"$RUNTIME_DIR/php-server.log" 2>&1 &
echo $! > "$RUNTIME_DIR/php-server.pid"

for i in $(seq 1 30); do
    if curl -sf -o /dev/null "$BASE_URL/api/v3/system/health"; then
        break
    fi
    if [ "$i" = 30 ]; then
        log "Server did not become healthy; last log lines:"
        tail -5 "$RUNTIME_DIR/php-server.log" >&2
        exit 1
    fi
    sleep 1
done

JAR="$RUNTIME_DIR/cookies.txt"
KEYPAGE="$RUNTIME_DIR/get-apikey.html"
VERDICT="$RUNTIME_DIR/validate-apikey.txt"
FORM="$RUNTIME_DIR/install-form.html"
RESULT="$RUNTIME_DIR/install-result.html"

# Step 2, the license key, as a browser takes it, rather than setting the
# user_api cookie by hand (which is how a release whose Save could never
# succeed on a fresh install passed CI). The portal's "Get my API key"
# returns to get_apikey.php with the key in the query string. If that page's
# Save checks the key through validate-apikey.php before opening the
# installer, make that same request and require "Key valid"; a fix may
# instead check the key in the installer's own POST, in which case the page
# just carries the key on to install.php. Either way the installer must
# open with the key, which is the step that was broken.
log "Taking the license-key step: get_apikey.php, then its Save"
curl -sS -c "$JAR" -b "$JAR" -G --data-urlencode "customers_api_key=$CUSTOMER_KEY" \
    "$BASE_URL/202-config/get_apikey.php" -o "$KEYPAGE"
if grep -q "validate-apikey\.php" "$KEYPAGE"; then
    # jQuery's $.post follows redirects, so -L, and the body is what JSON.parse sees.
    curl -sS -L -b "$JAR" -c "$JAR" --data-urlencode "apikey=$CUSTOMER_KEY" \
        "$BASE_URL/202-account/ajax/validate-apikey.php" -o "$VERDICT"
    if ! php -r '$j = json_decode((string) file_get_contents($argv[1]), true); exit(is_array($j) && ($j["msg"] ?? null) === "Key valid" ? 0 : 1);' "$VERDICT"; then
        log "get_apikey.php's Save would refuse the key: validate-apikey.php did not answer \"Key valid\". It answered:"
        head -c 300 "$VERDICT" >&2
        log ""
        exit 1
    fi
fi
# What the Save handler does to open the installer: the cookie, then install.php.
USER_API_COOKIE="user_api=$(php -r 'echo rawurlencode($argv[1]);' "$CUSTOMER_KEY")"

# The installer reads the key from that cookie into the form, and requires
# the session CSRF token embedded in the form.
log "Fetching the install form"
curl -sS -c "$JAR" -b "$JAR" -b "$USER_API_COOKIE" \
    "$BASE_URL/202-config/install.php" -o "$FORM"
# `|| true`: under set -e/pipefail a miss would exit before the diagnostics below.
TOKEN=$(grep -o 'name="token" value="[^"]*"' "$FORM" | head -1 | sed 's/.*value="//;s/"$//' || true)
if [ -z "$TOKEN" ]; then
    if grep -qi "already installed" "$FORM"; then
        log "Instance is already installed; cannot mint the install-time key. Point the CLI at an existing key instead."
    else
        log "Could not find the CSRF token on the install form:"
        head -5 "$FORM" >&2
    fi
    exit 1
fi
FORM_USER_API=$(grep -o 'name="user_api" value="[^"]*"' "$FORM" | head -1 | sed 's/.*value="//;s/"$//' || true)
FORM_USER_API=$(php -r 'echo html_entity_decode($argv[1], ENT_QUOTES, "UTF-8");' "$FORM_USER_API")

log "Running the installer as $ADMIN_NAME <$ADMIN_EMAIL>"
curl -sS -b "$JAR" -b "$USER_API_COOKIE" -c "$JAR" \
    -d "token=$TOKEN" \
    -d "user_email=$ADMIN_EMAIL" \
    -d "user_name=$ADMIN_NAME" \
    -d "user_pass=$ADMIN_PASS" \
    -d "verify_user_pass=$ADMIN_PASS" \
    -d "user_timezone=America/New_York" \
    --data-urlencode "user_api=$FORM_USER_API" \
    "$BASE_URL/202-config/install.php" -o "$RESULT"

REST_KEY=$(grep -oE '[0-9a-f]{64}' "$RESULT" | head -1 || true)
if [ -z "$REST_KEY" ]; then
    log "Install did not yield a REST API key; response excerpt:"
    grep -oE '<div class="error">[^<]*' "$RESULT" | head -5 >&2 || head -5 "$RESULT" >&2
    exit 1
fi

# The postback receivers live at dotted directory URLs; prove the server
# resolves each (see router.php) here, where the cause is named, rather than
# letting an eval case fail later with a row count that does not add up.
for RECEIVER_PATH in /.well-known/skadnetwork/report-attribution/ /.well-known/appattribution/report-attribution/; do
    RECEIVER_URL="$BASE_URL$RECEIVER_PATH"
    RECEIVER_STATUS=$(curl -sS -o /dev/null -w '%{http_code}' "$RECEIVER_URL" || true)
    if [ "$RECEIVER_STATUS" != "200" ]; then
        log "Receiver probe GET $RECEIVER_URL returned HTTP ${RECEIVER_STATUS:-000} (expected 200); last server log lines:"
        tail -5 "$RUNTIME_DIR/php-server.log" >&2
        exit 1
    fi
done

log "Install complete; instance at $BASE_URL"
printf '%s\n' "$REST_KEY"
