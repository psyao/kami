#!/usr/bin/env bash
# The shared-hosting smoke (psyao/kami#29, decision 4): installs the starter kit with this checkout of Kami
# on the simulated host, checks the site over HTTP, then requires empty error logs.
# It starts from scratch and leaves the stack up: `docker compose -f tests/Smoke/compose.yaml down -v` stops it.
set -euo pipefail
cd "$(dirname "$0")"

# The starter kit's `next` branch requires Kami's dev-main and carries the milestone's starter changes (ADR 0039).
# STARTER_VERSION=dev-<branch> tries another starter kit branch: dev-main is what `laravel new` installs.
starter_version=${STARTER_VERSION:-dev-next}
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

# A Preview link to a Draft, created as the admin does: sessionless, read through the cached route table.
share_path=$(docker compose run -T --rm cli php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $draft = $app->make(Psyao\Kami\Content\Actions\SaveEntry::class)->handle(App\Content\Page::class, ["title" => "Brouillon partagé", "slug" => "shared-draft", "body" => "Pas encore public"]);
    echo parse_url(Psyao\Kami\Content\Internal\PreviewLink::issue($draft, "fr", 7, null), PHP_URL_PATH);
' 2>&1 || true)
expect 404 /shared-draft
if [[ "$share_path" == /kami/share/* ]]; then
    expect_page "$share_path" 'Pas encore public'
else
    echo "Preview link: not created: $share_path"
    failed=1
fi

# The Audit log (psyao/kami#342): the editor's Page published above from the command line wrote one row per Locale,
# inside the save's transaction on the host's MariaDB, from the CLI and so with no IP.
audit=$(docker compose run -T --rm cli php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $page = Illuminate\Support\Facades\DB::table("kami_translations")->where("slug", "smoke")->value("entry_id");
    echo Illuminate\Support\Facades\DB::table("kami_audit_events")
        ->where(["event" => "kami.entries.published", "subject_type" => "entry", "subject_id" => $page, "actor" => "cli"])->whereNull("ip")
        ->orderBy("id")->pluck("context")->implode(" ");
' 2>&1 || true)
if [ "$audit" = '{"locale":"fr"} {"locale":"en"}' ]; then
    echo "Audit log: publication recorded"
else
    echo "Audit log: $audit"
    failed=1
fi

# The reset page (psyao/kami#350): the same notification for a known and an unknown address, and the known one's link
# sent after the response, once FPM has finished the request. The link lands in the mail log, cut back before the empty-log check below.
mail_log=/var/www/site/storage/logs/laravel.log
log_size=$(docker compose exec -T php sh -c "if [ -f $mail_log ]; then wc -c < $mail_log; else echo 0; fi" | tr -d ' \r')
known=$(docker compose run -T --rm cli php /kami/tests/Smoke/password-reset.php http://apache admin@example.test) || known=
unknown=$(docker compose run -T --rm cli php /kami/tests/Smoke/password-reset.php http://apache nobody@example.test) || unknown=
mail=$(docker compose exec -T php sh -c "tail -c +$((log_size + 1)) $mail_log 2>/dev/null" || true)
if [ -n "$known" ] && [ "$known" = "$unknown" ] && [ "$(grep -c '^To: ' <<<"$mail")" = 1 ] && grep -q '^To: admin@example.test' <<<"$mail"; then
    echo "Password reset: \"$known\" for both addresses, one link sent"
else
    echo "Password reset: known \"$known\", unknown \"$unknown\", mail log: $mail"
    failed=1
fi
docker compose exec -T php truncate --no-create --size="$log_size" "$mail_log" || true

# Sign-in throttling (psyao/kami#349) on the starter kit's `file` cache: five wrong passwords for one email from the CLI container, then the right one
# from the PHP container, another address, refused by the email's limit alone. Each failure and the one lockout land in the Audit log, never the address.
failures=$(for attempt in 1 2 3 4 5; do docker compose run -T --rm cli php /kami/tests/Smoke/sign-in.php http://apache admin@example.test wrong-password || true; done | sort -u)
refused=$(docker compose exec -T --workdir /var/www/site php php /kami/tests/Smoke/sign-in.php http://apache admin@example.test smoke-password || true)
sign_ins=$(docker compose run -T --rm cli php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo Illuminate\Support\Facades\DB::table("kami_audit_events")->where("event", "like", "kami.auth.%")->orderBy("id")->get()
        ->map(fn ($row) => $row->event . ($row->subject_type ? " " . $row->subject_type : "") . " " . json_encode(json_decode($row->context, true)))->implode(", ");
' 2>&1 || true)
expected_sign_ins="$(printf 'kami.auth.sign-in-failed user [], %.0s' 1 2 3 4 5)kami.auth.locked-out {\"key\":\"email\"}"
if [ "$(wc -l <<<"$failures")" -eq 1 ] && [ -n "$refused" ] && [ "$refused" != "$failures" ] && [ "$sign_ins" = "$expected_sign_ins" ]; then
    echo "Sign-in: \"$failures\" five times, then \"$refused\" from another address"
else
    echo "Sign-in: failures \"$failures\", then \"$refused\", Audit log: $sign_ins"
    failed=1
fi

# Rich text renders in pure PHP, on the host's PHP and its extensions: the starter kit declares no RichText yet.
rich_text=$(docker compose run -T --rm cli php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $field = Psyao\Kami\Content\Fields\RichText::make("body");
    $document = $field->normalise(["type" => "doc", "content" => [["type" => "paragraph", "content" => [["type" => "text", "text" => "Bonjour", "marks" => [["type" => "bold"], ["type" => "underline"]]]]]]]);
    echo $field->read($document)->toHtml();
' || true)
if [ "$rich_text" = '<p><strong>Bonjour</strong></p>' ]; then
    echo "RichText: rendered"
else
    echo "RichText: $rich_text"
    failed=1
fi

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

# Conversions (psyao/kami#297): kami:install linked public/media. A photo's `original` Conversion, and an SVG where the
# file route of psyao/kami#300 will write one. Prints the Conversion's path, then the original's path on its disk.
read -r conversion original < <(docker compose run -T --rm cli php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $media = $app->make(Psyao\Kami\Media\Actions\SaveMedia::class)->handle("/kami/tests/Fixtures/media/rotated-gps.jpg", ["alt" => "Smoke"]);
    Illuminate\Support\Facades\Storage::disk("public")->put("media/smoke.svg", "<svg xmlns=\"http://www.w3.org/2000/svg\"/>");
    echo parse_url($media->url(), PHP_URL_PATH), " ", $media->file, "\n";
' || true)

# Prints the status line and headers, lowercased.
headers() {
    curl --silent --show-error --output /dev/null --dump-header - --header 'X-Forwarded-Proto: https' "$base_url$1" | tr -d '\r' | tr '[:upper:]' '[:lower:]' || true
}
# `expect_file <path> <label> <header line>...`: a 200 carrying every header line; leaves the headers in $last_response.
expect_file() {
    local path=$1 label=$2 response problems=()
    shift 2
    response=$(headers "$path")
    last_response=$response
    head -n 1 <<<"$response" | grep -q ' 200' || problems+=("$(head -n 1 <<<"$response")")
    for line in "$@"; do
        grep -qixF "$line" <<<"$response" || problems+=("no \"$line\"")
    done
    if [ ${#problems[@]} -eq 0 ]; then
        echo "GET $path: 200, $label"
    else
        echo "GET $path ($label): $(IFS=,; echo "${problems[*]}")"
        failed=1
    fi
}
nosniff='x-content-type-options: nosniff'
immutable='cache-control: public, max-age=31536000, immutable'
svg_csp="content-security-policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:"

if [[ "$conversion" == /media/* ]]; then
    # The first request reaches Laravel: its front CSP is a header Apache never sends on a Conversion. Symfony sorts Cache-Control's directives.
    expect_file "$conversion" 'generated by Laravel' "$nosniff" 'cache-control: immutable, max-age=31536000, public'
    grep -q "^content-security-policy: default-src 'self'" <<<"$last_response" || { echo "GET $conversion: the first request did not reach Laravel"; failed=1; }
    # Without PHP-FPM, only Apache can answer.
    docker compose stop php >/dev/null 2>&1
    expect_file "$conversion" 'served by Apache without PHP' "$nosniff" "$immutable"
    expect_file /media/smoke.svg 'SVG served by Apache without PHP' "$nosniff" "$immutable" "$svg_csp"
    docker compose up --detach --wait php >/dev/null 2>&1
    # The original lies on the private disk: neither public/media nor public/storage reaches it.
    expect 404 "/$original"
    expect 404 "/storage/$original"
else
    echo "Conversion: not created: $conversion $original"
    failed=1
fi

# The page cache (psyao/kami#408): a page stored by one request is served again with the database stopped. `php artisan down`
# answers 503 before any cached page, as public/index.php checks maintenance before it requires Kami's reader.
expect_page /smoke Bonjour
docker compose stop mariadb >/dev/null 2>&1
expect_page /smoke Bonjour
docker compose up --detach --wait mariadb >/dev/null 2>&1
docker compose run -T --rm cli php artisan down >/dev/null
expect 503 /smoke
docker compose run -T --rm cli php artisan up >/dev/null

# The sitemap (psyao/kami#410): missing, both files are built through the `!-f` fallback by the first request, then
# Apache serves them without PHP.
docker compose run -T --rm cli rm -rf public/sitemap.xml public/sitemaps
expect 200 /sitemap.xml
sitemap=$(curl --silent --show-error --header 'X-Forwarded-Proto: https' "$base_url/sitemaps/fr-1.xml" || true)
if grep -qF '/smoke</loc>' <<<"$sitemap"; then
    echo "GET /sitemaps/fr-1.xml: lists /smoke"
else
    echo "GET /sitemaps/fr-1.xml: does not list /smoke"
    failed=1
fi
docker compose stop php >/dev/null 2>&1
expect 200 /sitemap.xml
expect 200 /sitemaps/fr-1.xml
docker compose up --detach --wait php >/dev/null 2>&1

logs=$(docker compose exec -T php sh -c 'cat /var/log/php/error.log /var/www/site/storage/logs/*.log 2>/dev/null' || true)
if [ -n "$logs" ]; then
    echo "The error logs are not empty:"
    echo "$logs"
    failed=1
fi

exit "$failed"
