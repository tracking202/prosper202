#!/bin/bash
set -e

cd /var/www/html

# Install composer dependencies if vendor directory is missing or empty: the
# base image with the checkout bind-mounted (the app image bakes vendor/ in).
# Only from composer.lock, as the app image and the release zip install: with
# no lock composer resolves whatever matches composer.json that day, and with
# a lock that has drifted from composer.json install only warns and installs
# the old set, so validate stops the container first (set -e).
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    if [ ! -f composer.lock ]; then
        echo "composer.lock is missing from $(pwd); restore it (git checkout composer.lock) so the install is the locked set." >&2
        exit 1
    fi
    composer validate --no-check-publish --no-check-all
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
# is safe to run on every container start. A real failure (missing sample,
# unwritable target) would otherwise boot the container with broken DB config,
# so let `set -e` surface it loudly instead of swallowing it.
if [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
    php build/scripts/write-config.php
fi

# Execute the main command (Apache)
exec "$@"
