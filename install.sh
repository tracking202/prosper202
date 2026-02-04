#!/bin/bash
set -e

echo "Prosper202 Installation Script"
echo "==============================="
echo ""

# Check for PHP
if ! command -v php &> /dev/null; then
    echo "ERROR: PHP is not installed. Please install PHP 8.3+ first."
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "Found PHP version: $PHP_VERSION"

# Check for Composer
if ! command -v composer &> /dev/null; then
    echo ""
    echo "Composer not found. Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>/dev/null || {
        # Try local install if global fails (no sudo)
        curl -sS https://getcomposer.org/installer | php
        COMPOSER="php composer.phar"
        echo "Composer installed locally as composer.phar"
    }
else
    COMPOSER="composer"
    echo "Found Composer"
fi

# Default to global composer if variable not set
COMPOSER=${COMPOSER:-composer}

# Install dependencies
echo ""
echo "Installing PHP dependencies..."

if [ "$1" = "--dev" ]; then
    echo "(Development mode - including dev dependencies)"
    $COMPOSER install --no-interaction
else
    echo "(Production mode - excluding dev dependencies)"
    $COMPOSER install --no-dev --no-interaction --optimize-autoloader
fi

# Check for config file
echo ""
if [ ! -f "202-config.php" ]; then
    if [ -f "202-config-sample.php" ]; then
        echo "Creating 202-config.php from sample..."
        cp 202-config-sample.php 202-config.php
        echo "IMPORTANT: Edit 202-config.php with your database credentials!"
    else
        echo "WARNING: 202-config-sample.php not found"
    fi
else
    echo "Config file 202-config.php already exists"
fi

echo ""
echo "Installation complete!"
echo ""
echo "Next steps:"
echo "1. Edit 202-config.php with your database credentials"
echo "2. Configure your web server to point to this directory"
echo "3. Access the application in your browser"
echo ""
