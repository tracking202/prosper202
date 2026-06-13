#!/bin/bash
set -e

cd /var/www/html

# Install composer dependencies if vendor directory is missing or empty
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."

    if [ "$APP_ENV" = "development" ] || [ "$APP_ENV" = "dev" ]; then
        composer install --no-interaction
    else
        composer install --no-dev --no-interaction --optimize-autoloader
    fi

    echo "Composer dependencies installed."
fi

# Self-write 202-config.php from the sample using the DB credentials passed in
# by docker-compose, so the setup wizard opens with the database step already
# done. write-config.php is a no-op when 202-config.php already exists, so this
# is safe to run on every container start.
if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
    php build/scripts/write-config.php || echo "Skipping config generation."
fi

# Execute the main command (Apache)
exec "$@"
