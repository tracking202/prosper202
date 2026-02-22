#!/bin/bash
set -e

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
if [ -n "$DB_HOST" ]; then
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
    for i in $(seq 1 30); do
        if php -r "new mysqli('${DB_HOST}', '${DB_USER:-root}', '${DB_PASS:-}', '', ${DB_PORT:-3306});" 2>/dev/null; then
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
