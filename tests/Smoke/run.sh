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
# -T: no TTY, so the runs work in CI; stdin stays attached, which the Page's heredoc below relies on.
docker compose run -T --rm --env STARTER_VERSION="$starter_version" cli bash /kami/tests/Smoke/install.sh
docker compose run -T --rm node

# The starter kit's demo first: it does nothing once a Page exists. It publishes a Singleton and a Page with a relation.
docker compose run -T --rm cli php artisan db:seed --class=DemoSeeder --force --no-interaction

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
    sed '1,/^$/d' <<<"$response" | grep -qF "$2" || problems+=("no \"$2\"")
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
expect_page / Bienvenue
expect_page /en Welcome
expect_page /about '<li>Kami</li>'

# Drift fails the deploy with a non-zero exit code and takes no Path away, on the host's PHP and MariaDB.
# Creates or drops the table with no declared type, and prints the 200 rows' count.
drifted_table() {
    docker compose run -T --rm cli php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $schema = Illuminate\Support\Facades\Schema::class;
        if (($argv[1] ?? null) === "create") { $schema::create("kami_type_drifted", fn ($table) => $table->string("locale")); }
        if (($argv[1] ?? null) === "drop") { $schema::drop("kami_type_drifted"); }
        echo Illuminate\Support\Facades\DB::table("kami_paths")->where("status", 200)->count();
    ' -- "$@"
}
before=$(drifted_table create)
if output=$(docker compose run -T --rm cli php artisan kami:install --no-interaction 2>&1); then
    echo "kami:install passed on a table with no declared type"
    failed=1
elif ! grep -qF 'kami_type_drifted belongs to no declared type' <<<"$output"; then
    echo "kami:install failed without naming the drift: $output"
    failed=1
else
    echo "kami:install: refused the drift"
fi
after=$(drifted_table drop)
if [ "$before" != "$after" ]; then
    echo "The drift failure changed the 200 rows: $before, then $after"
    failed=1
fi
docker compose run -T --rm cli php artisan kami:install --no-interaction >/dev/null || { echo "kami:install failed once the drift was gone"; failed=1; }
expect_page /smoke Bonjour

logs=$(docker compose exec -T php sh -c 'cat /var/log/php/error.log /var/www/site/storage/logs/*.log 2>/dev/null' || true)
if [ -n "$logs" ]; then
    echo "The error logs are not empty:"
    echo "$logs"
    failed=1
fi

exit "$failed"
