#!/bin/bash

# Update package list
sudo apt-get update

# Install PHP and common extensions
sudo apt-get install -y php php-cli php-xml php-mbstring php-curl php-zip php-mysql

# Install Composer (securely)
EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
    >&2 echo 'ERROR: Invalid Composer installer signature'
    rm composer-setup.php
    exit 1
fi
php composer-setup.php --quiet
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php

# Install dependencies if composer.json exists
if [ -f "composer.json" ]; then
    composer install
fi

# Install PHP_CodeSniffer globally via Composer (as a linter)
sudo composer global require "squizlabs/php_codesniffer=*"
export PATH="$PATH:$HOME/.composer/vendor/bin" # For older Composer
export PATH="$PATH:$HOME/.config/composer/vendor/bin" # For Composer 2+

# Output PHP and PHPCS version for confirmation
php -v
phpcs --version || echo "phpcs not found in PATH"

# Example: Run linter on all PHP files in the current directory
phpcs --standard=PSR12 . || true
