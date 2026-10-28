#!/usr/bin/env bash
# Runs in the cli container, in the empty site directory: creates a site from the starter kit with /kami,
# the checkout under test, as its Kami, then installs it as a deploy over SSH would.
set -euo pipefail

composer create-project psyao/kami-starter . "$STARTER_VERSION" --no-install --no-scripts --no-interaction

# The checkout replaces the Kami release the starter kit requires.
composer config repositories.kami '{"type":"path","url":"/kami","options":{"versions":{"psyao/kami":"dev-main"}}}'
composer require psyao/kami:dev-main --no-update

cp .env.example .env
sed -i \
    -e 's|^APP_ENV=.*|APP_ENV=production|' \
    -e 's|^APP_DEBUG=.*|APP_DEBUG=false|' \
    -e 's|^DB_CONNECTION=.*|DB_CONNECTION=mariadb|' \
    -e 's|^# DB_HOST=.*|DB_HOST=mariadb|' \
    -e 's|^# DB_PORT=|DB_PORT=|' \
    -e 's|^# DB_DATABASE=.*|DB_DATABASE=kami|' \
    -e 's|^# DB_USERNAME=.*|DB_USERNAME=kami|' \
    -e 's|^# DB_PASSWORD=.*|DB_PASSWORD=kami|' \
    .env

composer install --no-dev --no-interaction --no-progress
php artisan key:generate --no-interaction
php artisan kami:install --no-interaction
php artisan optimize
