#!/bin/bash
set -e

cd /var/www/html

# Install composer dependencies if vendor directory is missing or empty
if [ ! -d "/var/www/html/vendor" ] || [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."

    if [ "$APP_ENV" = "development" ] || [ "$APP_ENV" = "dev" ]; then
        composer install --no-interaction
    else
        composer install --no-dev --no-interaction --optimize-autoloader
    fi

    echo "Composer dependencies installed."
fi

# Copy Docker database config if 202-config.php doesn't exist
if [ ! -f "/var/www/html/202-config.php" ] && [ -f "/var/www/html/build/scripts/docker-config.php" ]; then
    echo "Copying Docker database configuration..."
    cp /var/www/html/build/scripts/docker-config.php /var/www/html/202-config.php
    echo "Configuration file created."
fi

# Execute the main command (Apache)
exec "$@"
