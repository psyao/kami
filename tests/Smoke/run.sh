#!/usr/bin/env bash
# The shared-hosting smoke (psyao/kami#29, decision 4): installs the starter kit with this checkout of Kami
# on the simulated host, checks the site over HTTP, then requires empty error logs.
# It starts from scratch and leaves the stack up: `docker compose -f tests/Smoke/compose.yaml down -v` stops it.
set -euo pipefail
cd "$(dirname "$0")"

# The starter kit is untagged and `laravel new` installs its default branch (psyao/kami#30, decision 9): test that.
# STARTER_VERSION=dev-<branch> tries a starter kit branch before it merges.
starter_version=${STARTER_VERSION:-dev-main}
base_url="http://localhost:${SMOKE_PORT:-8080}"

docker compose down --volumes --remove-orphans
docker compose up --build --detach --wait php mariadb
# -T: the container never reads this script's stdin.
docker compose run -T --rm --env STARTER_VERSION="$starter_version" cli bash /kami/tests/Smoke/install.sh
docker compose run -T --rm node

# An editor's first Page, published in both Locales from the command line, as an import would.
docker compose run -T --rm cli php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$save = $app->make(Psyao\Kami\Content\Actions\SaveEntry::class);
$page = $save->handle(App\Content\Page::class, ['title' => 'Fumée', 'slug' => 'smoke', 'status' => 'published', 'body' => 'Bonjour']);
$save->handle(App\Content\Page::class, ['title' => 'Smoke', 'slug' => 'smoke', 'status' => 'published', 'body' => 'Hello'], $page, 'en');
PHP
# Apache needs the site's public/ to start.
docker compose up --detach --wait apache

failed=0

# The starter kit's .htaccess redirects HTTP to HTTPS unless the request says it reached the host over HTTPS.
expect() {
    local status
    status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --header 'X-Forwarded-Proto: https' "$base_url$2" || true)
    if [ "$status" = "$1" ]; then
        echo "GET $2: $status"
    else
        echo "GET $2: $status, expected $1"
        failed=1
    fi
}

# A Kami page after `php artisan optimize`: its Locale's text, Kami's CSP, and no session cookie, since the
# `kami` group runs without `web`. Only a cached route table proves the group survives `route:cache` (psyao/kami#102).
expect_page() {
    local response problems=()
    response=$(curl --silent --show-error --include --header 'X-Forwarded-Proto: https' "$base_url$1" | tr -d '\r' || true)
    head -n 1 <<<"$response" | grep -q ' 200' || problems+=("$(head -n 1 <<<"$response")")
    grep -qi '^content-security-policy:' <<<"$response" || problems+=('no Content-Security-Policy')
    ! grep -qi '^set-cookie:' <<<"$response" || problems+=('a Set-Cookie')
    grep -q "$2" <<<"$response" || problems+=("no \"$2\"")
    if [ ${#problems[@]} -eq 0 ]; then
        echo "GET $1: 200, CSP, no cookie, \"$2\""
    else
        echo "GET $1: $(IFS=,; echo "${problems[*]}")"
        failed=1
    fi
}

# One step per subsystem as the milestones land it.
expect 200 /admin/login
expect_page /smoke Bonjour
expect_page /en/smoke Hello

logs=$(docker compose exec -T php sh -c 'cat /var/log/php/error.log /var/www/site/storage/logs/*.log 2>/dev/null' || true)
if [ -n "$logs" ]; then
    echo "The error logs are not empty:"
    echo "$logs"
    failed=1
fi

exit "$failed"
