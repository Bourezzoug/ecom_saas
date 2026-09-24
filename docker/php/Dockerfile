# Dev image for the Laravel platform (web, horizon, reverb, scheduler, vite).
# Node is included because the Vite Wayfinder plugin shells out to `php artisan`.
FROM php:8.4-fpm-bookworm

ARG NODE_MAJOR=22

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates gnupg \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath pcntl opcache exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY zz-www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/aisg/apps/platform
