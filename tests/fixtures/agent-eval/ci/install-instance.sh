#!/usr/bin/env bash
#
# Stand up a throwaway Prosper202 instance for agent evals: write
# 202-config.php, serve the repo with PHP's built-in server, and drive the
# real web installer headlessly (cookie + CSRF token + form POST). Prints
# exactly one line to stdout on success: the REST API key of the account it
# created. Everything else goes to stderr.
#
# Requirements: a reachable MySQL, PHP with the app's extensions, curl, and
# a full `composer install` (the click path needs ua-parser/uap-php's
# shipped regexes). Run from the repository root.
#
# Environment (defaults in parentheses):
#   P202_DB_HOST (127.0.0.1)  P202_DB_PORT (3306)  P202_DB_NAME (prosper202)
#   P202_DB_USER (root)       P202_DB_PASS (root)
#   P202_HTTP_HOST (127.0.0.1)  P202_HTTP_PORT (8000)
#   P202_ADMIN_EMAIL (eval-ci@example.com)  P202_ADMIN_NAME (evalci)
#   P202_ADMIN_PASS (generated when unset)
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
    sed -e "s/putyourdbnamehere/$DB_NAME/" \
        -e "s/usernamehere/$DB_USER/" \
        -e "s/yourpasswordhere/$DB_PASS/" \
        -e "s/localhosthere/$DB_HOST:$DB_PORT/" \
        -e "s/localhostreplica/$DB_HOST:$DB_PORT/" \
        -e "s/localhostmemcache/127.0.0.1/" \
        202-config-sample.php > 202-config.php
    php -l 202-config.php >/dev/null
fi

log "Serving on $BASE_URL (log: $RUNTIME_DIR/php-server.log)"
php -S "$HTTP_HOST:$HTTP_PORT" >"$RUNTIME_DIR/php-server.log" 2>&1 &
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
FORM="$RUNTIME_DIR/install-form.html"
RESULT="$RUNTIME_DIR/install-result.html"

# The installer requires the customer-key cookie (any value skips the
# fetch-a-key page; the POST path stores it without remote validation) and
# the session CSRF token embedded in the form.
log "Fetching the install form"
curl -sS -c "$JAR" -b "user_api=agent-eval-ci" \
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

log "Running the installer as $ADMIN_NAME <$ADMIN_EMAIL>"
curl -sS -b "$JAR" -b "user_api=agent-eval-ci" -c "$JAR" \
    -d "token=$TOKEN" \
    -d "user_email=$ADMIN_EMAIL" \
    -d "user_name=$ADMIN_NAME" \
    -d "user_pass=$ADMIN_PASS" \
    -d "verify_user_pass=$ADMIN_PASS" \
    -d "user_timezone=America/New_York" \
    -d "user_api=agent-eval-ci" \
    "$BASE_URL/202-config/install.php" -o "$RESULT"

REST_KEY=$(grep -oE '[0-9a-f]{64}' "$RESULT" | head -1 || true)
if [ -z "$REST_KEY" ]; then
    log "Install did not yield a REST API key; response excerpt:"
    grep -oE '<div class="error">[^<]*' "$RESULT" | head -5 >&2 || head -5 "$RESULT" >&2
    exit 1
fi

log "Install complete; instance at $BASE_URL"
printf '%s\n' "$REST_KEY"
