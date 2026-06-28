FROM php:8.3-apache

# Install required packages and PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       zlib1g-dev libssl-dev git unzip curl \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Optional: install memcached extension if available for this platform/PHP version
# Commented out to avoid build failures on unsupported platforms.
# RUN apt-get update \
#     && apt-get install -y --no-install-recommends libmemcached-dev \
#     && pecl install memcached \
#     && docker-php-ext-enable memcached \
#     && rm -rf /var/lib/apt/lists/* /tmp/pear

# Error reporting in development
COPY build/php/conf.d/error-reporting.ini /usr/local/etc/php/conf.d/error-reporting.ini

# Copy entrypoint script
COPY build/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT=/var/www/html

WORKDIR /var/www/html

# Copy application files (for standalone container without volume mount)
COPY composer.json composer.lock* ./
COPY . .

# Install dependencies at build time (for production without volume mount)
RUN composer install --no-dev --no-interaction --optimize-autoloader || true

# Use entrypoint to auto-install deps when volume is mounted (development)
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
