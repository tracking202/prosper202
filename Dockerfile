FROM php:8.3-fpm-bookworm AS base

# System dependencies for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libmemcached-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libxml2-dev \
        libssl-dev \
        zlib1g-dev \
        unzip \
        git \
        libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by Prosper202
RUN docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo \
        pdo_mysql \
        curl \
        mbstring \
        xml \
        opcache \
    && pecl install memcached-3.3.0 \
    && docker-php-ext-enable memcached

# ─────────────────────────────────────────────────────────
# Production PHP-FPM
# ─────────────────────────────────────────────────────────
FROM base AS production

COPY build/php/conf.d/production.ini $PHP_INI_DIR/conf.d/zz-production.ini
COPY build/php/conf.d/opcache.ini    $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY build/php/php-fpm.d/www.conf    /usr/local/etc/php-fpm.d/zz-www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize

# Writable dirs for runtime
RUN mkdir -p 202-config/logs 202-cronjobs \
    && chown -R www-data:www-data 202-config/logs 202-cronjobs

COPY build/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY build/scripts/healthcheck.sh       /usr/local/bin/healthcheck.sh
COPY build/scripts/docker-cron.sh       /usr/local/bin/docker-cron.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
             /usr/local/bin/healthcheck.sh \
             /usr/local/bin/docker-cron.sh

USER www-data
EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh"]

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

# ─────────────────────────────────────────────────────────
# Nginx — copies document root from production stage
# so both containers have identical code without shared volumes.
# Nginx serves static files directly; PHP goes to upstream.
# ─────────────────────────────────────────────────────────
FROM nginx:1.27-bookworm AS nginx

RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY build/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --from=production /var/www/html /var/www/html

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD ["curl", "-sf", "http://localhost/nginx-health", "-o", "/dev/null"]

# ─────────────────────────────────────────────────────────
# Development PHP-FPM (code bind-mounted at runtime)
# ─────────────────────────────────────────────────────────
FROM base AS development

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY build/php/conf.d/error-reporting.ini $PHP_INI_DIR/conf.d/zz-dev.ini

COPY build/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY build/scripts/docker-cron.sh       /usr/local/bin/docker-cron.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
             /usr/local/bin/docker-cron.sh

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
