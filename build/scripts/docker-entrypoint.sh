#!/bin/bash
set -e

# Install composer dependencies if vendor directory is missing or empty
if [ ! -d "/var/www/html/vendor" ] || [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    cd /var/www/html
    
    if [ "$APP_ENV" = "development" ] || [ "$APP_ENV" = "dev" ]; then
        composer install --no-interaction
    else
        composer install --no-dev --no-interaction --optimize-autoloader
    fi
    
    echo "Composer dependencies installed."
fi

# Execute the main command (Apache)
exec "$@"
