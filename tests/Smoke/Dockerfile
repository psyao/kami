# PHP 8.5 with the host's extensions that matter to Kami (docs/velox-retrospective.md § 5.1).
# Bookworm's ImageMagick is 6.9.11-60, the host's version; the host runs it on Debian 11, so its delegates may differ.
FROM php:8.5-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
        libfreetype6-dev libicu-dev libjpeg62-turbo-dev libmagickwand-dev libpng-dev libwebp-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    # PHP's bundled GD with WebP and without AVIF, as on the host.
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl pcntl pdo_mysql zip \
    && pecl install imagick-3.8.1 \
    && docker-php-ext-enable imagick \
    && mkdir -p /var/www/site /var/log/php \
    && chown www-data:www-data /var/www/site /var/log/php

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
