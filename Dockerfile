FROM php:8.3-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
        libxml2-dev \
        libcurl4-openssl-dev \
        libmaxminddb-dev \
        unzip \
        git \
    && docker-php-ext-install mysqli pdo_mysql xml curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application
COPY . /var/www/html/

# Install Composer dependencies
RUN composer install --no-dev --no-interaction --optimize-autoloader 2>/dev/null || \
    composer install --no-interaction 2>/dev/null || true

# Apache config: allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf
RUN echo '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>' > /etc/apache2/conf-available/prosper202.conf \
    && a2enconf prosper202

# Copy custom PHP config
COPY build/php/conf.d/error-reporting.ini /usr/local/etc/php/conf.d/error-reporting.ini

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Copy entrypoint
COPY build/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
