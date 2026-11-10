#!/usr/bin/env bash
# Runs in the cli container, in the empty site directory: creates a site from the starter kit with /kami,
# the checkout under test, as its Kami, then installs it as a deploy over SSH would.
set -euo pipefail

composer create-project psyao/kami-starter . "$STARTER_VERSION" --no-install --no-scripts --no-interaction

# The checkout replaces the Kami the starter kit requires: dev-main on `next` and its branches, a release on `main`.
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

# Dev requirements are resolved but never installed: the starter kit's Browser suite needs ext-sockets, which the host need not load.
composer install --no-dev --no-interaction --no-progress --ignore-platform-req=ext-sockets

# A composer.lock shipped by the starter kit wins over the path repository and installs a released Kami instead.
if [ "$(readlink -f vendor/psyao/kami)" != /kami ]; then
    echo "vendor/psyao/kami is not /kami: the smoke would test another Kami than the checkout (a composer.lock in the starter kit?)" >&2
    exit 1
fi
php artisan key:generate --no-interaction
# The planner's token, from the command a site owner runs, and Kami's tasks; the starter kit's `next` may already carry any of these lines.
token=$(php artisan kami:tick:url | sed -n 's/^KAMI_TICK_TOKEN=//p')
if grep -q '^KAMI_TICK_TOKEN=' .env; then sed -i "s|^KAMI_TICK_TOKEN=.*|KAMI_TICK_TOKEN=$token|" .env; else echo "KAMI_TICK_TOKEN=$token" >> .env; fi
# The queue the Tick works through, as the starter kit's `next` sets it.
if grep -q '^QUEUE_CONNECTION=' .env; then sed -i 's|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=database|' .env; else echo 'QUEUE_CONNECTION=database' >> .env; fi
grep -q 'Kami::schedule()' routes/console.php || echo '\Psyao\Kami\Facades\Kami::schedule();' >> routes/console.php
KAMI_ADMIN_PASSWORD=smoke-password php artisan kami:install --no-interaction --email=admin@example.test --name=Admin
php artisan optimize
