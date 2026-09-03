# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5

##########################################################
# Base: PHP 8.5 FPM + the extensions the app needs
##########################################################
FROM php:${PHP_VERSION}-fpm-bookworm AS php_base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Bundled with the PHP source, no network needed.
RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip

# phpredis: built from the pinned GitHub tag rather than PECL, whose CDN host is not
# resolvable from every build environment.
ARG PHPREDIS_VERSION=6.3.0
RUN set -eux; \
    curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" -o /tmp/phpredis.tar.gz; \
    mkdir -p /usr/src/phpredis; \
    tar -xzf /tmp/phpredis.tar.gz -C /usr/src/phpredis --strip-components=1; \
    cd /usr/src/phpredis; \
    phpize; \
    ./configure --enable-redis-igbinary=no --enable-redis-lzf=no --enable-redis-zstd=no; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable redis; \
    php -m | grep -qx redis; \
    rm -rf /usr/src/phpredis /tmp/phpredis.tar.gz

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

##########################################################
# Dev: bind-mounted source, Xdebug available on demand
##########################################################
FROM php_base AS dev

ARG UID=1000
ARG GID=1000

# Align www-data with the host user so bind-mounted files stay writable.
RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g "${GID}" www-data \
    && chown -R www-data:www-data /var/www

# Xdebug, from the pinned GitHub tag for the same reason as phpredis above.
ARG XDEBUG_VERSION=3.5.3
RUN set -eux; \
    curl -fsSL "https://github.com/xdebug/xdebug/archive/refs/tags/${XDEBUG_VERSION}.tar.gz" -o /tmp/xdebug.tar.gz; \
    mkdir -p /usr/src/xdebug; \
    tar -xzf /tmp/xdebug.tar.gz -C /usr/src/xdebug --strip-components=1; \
    cd /usr/src/xdebug; \
    phpize; \
    ./configure --enable-xdebug; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable xdebug; \
    rm -rf /usr/src/xdebug /tmp/xdebug.tar.gz

COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

# Xdebug stays off until XDEBUG_MODE is set to debug/develop/coverage.
ENV XDEBUG_MODE=off

##########################################################
# Prod: self-contained image, no bind mounts
##########################################################
FROM php_base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
