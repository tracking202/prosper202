#!/bin/bash
set -e

# ── Config file guard ─────────────────────────────────────
# Docker bind-mounts create a directory when the source file
# doesn't exist on the host. Detect and fix this.
CONFIG_PATH="/var/www/html/202-config.php"
if [ -d "$CONFIG_PATH" ]; then
    echo "ERROR: 202-config.php was mounted as a directory (source file missing on host)."
    echo "       Create it first:  cp 202-config-sample.php 202-config.php"
    echo "       Or run:           ./install.sh --docker"
    rmdir "$CONFIG_PATH" 2>/dev/null || true
    exit 1
fi

# ── Composer dependencies ─────────────────────────────────
# In development, vendor/ is bind-mounted and may be empty.
# In production, vendor/ is baked into the image at build time.
if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    cd /var/www/html

    if [ "$APP_ENV" = "development" ] || [ "$APP_ENV" = "dev" ]; then
        composer install --no-interaction
    else
        composer install --no-dev --no-interaction --optimize-autoloader
    fi

    echo "Composer dependencies installed."
fi

# ── Wait for MySQL ────────────────────────────────────────
# When running under compose, depends_on with service_healthy covers this.
# This is a safety net for standalone container usage.
# Uses a TCP check to avoid interpolating env vars into PHP code.
if [ -n "$DB_HOST" ]; then
    DB_PORT="${DB_PORT:-3306}"
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
    for i in $(seq 1 30); do
        if php -r "
            \$sock = @fsockopen(getenv('DB_HOST'), (int)getenv('DB_PORT') ?: 3306, \$errno, \$errstr, 2);
            if (\$sock) { fclose(\$sock); exit(0); }
            exit(1);
        " 2>/dev/null; then
            echo "MySQL is ready."
            break
        fi
        if [ "$i" -eq 30 ]; then
            echo "WARNING: MySQL not reachable after 30 attempts, continuing anyway."
        fi
        sleep 2
    done
fi

# ── Execute main process ─────────────────────────────────
exec "$@"
