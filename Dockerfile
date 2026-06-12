FROM php:8.3-apache

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
