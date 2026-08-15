# Stage layout:
#   base — runtime (PHP extensions, Apache config, entrypoint) with no app
#          code; the dev compose stack builds this target and bind-mounts the
#          checkout over /var/www/html.
#   app  — (default) base plus the application and its composer dependencies
#          baked in, for deployments that build straight from git with no bind
#          mount (Coolify, any production docker compose host).
FROM php:8.3-apache AS base

# git/unzip for Composer; libmemcached for the optional memcached extension
# used by 202-config/connect.php. Dev libs stay installed because the built
# extensions link against their runtime counterparts.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libmemcached-dev \
        zlib1g-dev \
        libssl-dev \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql opcache \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite plus the minimal override set the shipped .htaccess files need
# (see the Apache notes in README.md). The dotfile deny matters here: the
# compose bind mount puts the whole checkout — including .env and .git — in
# the document root, and Apache only blocks .ht* by default.
RUN a2enmod rewrite \
    && { \
        echo '<Directory /var/www/html>'; \
        echo '    Options -Indexes +FollowSymLinks'; \
        echo '    AllowOverride FileInfo Options=FollowSymLinks'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
        echo '# Deny dotfiles (.env, .git, ...) but keep /.well-known/ for ACME'; \
        echo '<LocationMatch "/\.(?!well-known/)">'; \
        echo '    Require all denied'; \
        echo '</LocationMatch>'; \
        echo '# Behind a TLS-terminating proxy (Coolify/Traefik, a load'; \
        echo '# balancer), PHP only sees plain HTTP. Surface the forwarded'; \
        echo '# scheme as HTTPS=on so secure cookies and generated URLs work.'; \
        echo 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on'; \
    } > /etc/apache2/conf-available/prosper202.conf \
    && a2enconf prosper202

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The entrypoint runs composer as root inside the container
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY build/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

# Default stage: self-contained image with the app baked in. www-data owns the
# tree because the app writes inside the docroot at runtime (202-config.php,
# 202-cronjobs/cron.lock, attribution exports, auto-upgrade). vendor/ is
# installed afterwards as root on purpose — the web user only needs to read it.
# The entrypoint sees vendor/autoload.php and skips its runtime composer
# install, so container start stays fast.
FROM base AS app
COPY --chown=www-data:www-data . /var/www/html
RUN composer install --no-dev --no-interaction --optimize-autoloader
