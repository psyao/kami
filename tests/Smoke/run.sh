#!/usr/bin/env bash
# The shared-hosting smoke (psyao/kami#29, decision 4): installs the starter kit with this checkout of Kami
# on the simulated host, checks the site over HTTP, then requires empty error logs.
# It starts from scratch and leaves the stack up: `docker compose -f tests/Smoke/compose.yaml down -v` stops it.
set -euo pipefail
cd "$(dirname "$0")"

# The starter kit's `next` branch requires Kami's dev-main and carries the milestone's starter changes (ADR 0039).
# STARTER_VERSION=dev-<branch> tries another starter kit branch: dev-main is what `laravel new` installs.
# STARTER_REPOSITORY, a Composer repository as JSON, reads the starter kit there instead of on Packagist: the starter kit's
# own CI points it at its GitHub repository, for the exact head of a pull request's branch (psyao/kami#545).
starter_version=${STARTER_VERSION:-dev-next}
base_url="http://localhost:${SMOKE_PORT:-8080}"

# A Docker Hub lookup hung a local run for 30 minutes (psyao/kami#455). Every image the stack and its Dockerfile name is
# read from the local store when it is there; a missing one leaves the smoke to CI, whose builder caches its layers apart
# from the store. The bound catches a stalled apt mirror.
if [ "${GITHUB_ACTIONS:-}" != true ]; then
    images=$( (docker compose --profile '*' config --images | grep -vx kami-smoke-php; sed -nE 's/^FROM ([^ ]+).*/\1/p; s/.*--from=([^ ]+).*/\1/p' Dockerfile) | sort -u)
    for image in $images; do
        docker image inspect "$image" >/dev/null 2>&1 || { echo "CI only: $image is not cached locally. Pull it with \`docker pull $image\` first, or let CI run the smoke." >&2; exit 1; }
    done
fi

# Every profile: a Mailpit a previous run left up would take this run's mail before its SMTP server is meant to be up.
docker compose --profile '*' down --volumes --remove-orphans
timeout 600 docker compose up --build --detach --wait php mariadb
# Past the build, Compose's progress is noise: Compose 5 prints a `run`'s container lifecycle on stderr, which the checks
# below capture with their command's output.
export COMPOSE_PROGRESS=quiet
# -T: no TTY, so the runs work in CI; stdin stays attached, which the Page's heredoc below relies on.
docker compose run -T --rm --env STARTER_VERSION="$starter_version" --env STARTER_REPOSITORY="${STARTER_REPOSITORY:-}" cli bash /kami/tests/Smoke/install.sh
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

# Runs PHP code in the booted site from the CLI container; the arguments after the code reach it as `$argv`.
site_php() {
    local code=$1
    shift
    docker compose run -T --rm cli php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        '"$code" -- "$@"
}

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
share_path=$(site_php '
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
audit=$(site_php '
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
sign_ins=$(site_php '
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
rich_text=$(site_php '
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
    site_php '
        $schema = Illuminate\Support\Facades\Schema::class;
        if (($argv[1] ?? null) === "create") { $schema::create("kami_type_drifted", fn ($table) => $table->string("locale")); }
        if (($argv[1] ?? null) === "drop") { $schema::drop("kami_type_drifted"); }
        echo Illuminate\Support\Facades\DB::table("kami_paths")->where("status", 200)->count();
    ' "$@"
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
read -r conversion original < <(site_php '
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

# The Tick (psyao/kami#476), in-process under FPM with `proc_open` off. The planner's URL: a wrong token is a 404; the first visits above
# recorded each task's baseline, so `kami:sitemap`, a `command()` task made overdue, runs inside the call, then a queued job (psyao/kami#477).
tick_body() {
    curl --silent --show-error --header 'X-Forwarded-Proto: https' "$base_url/kami/tick/$1" || true
}
token=$(docker compose run -T --rm cli sed -n 's/^KAMI_TICK_TOKEN=//p' .env | tr -d '\r')
expect 404 /kami/tick/not-the-token
site_php 'Illuminate\Support\Facades\DB::table("kami_task_runs")->where("description", "kami:sitemap")->update(["last_run_at" => now()->subHours(2), "last_status" => null]);' >/dev/null 2>&1 || true
# A queued closure is serialised from its source file, which `php -r` lacks.
site_php 'file_put_contents("/tmp/job.php", "<?php return function () { file_put_contents(storage_path(\"app/smoke-job\"), PHP_SAPI); };"); dispatch(require "/tmp/job.php");' >/dev/null 2>&1 || true
tick=$(tick_body "$token")
sitemap_run=$(site_php 'echo Illuminate\Support\Facades\DB::table("kami_task_runs")->where("description", "kami:sitemap")->where("last_run_at", ">", now()->subMinute())->value("last_status");' 2>&1 || true)
job_sapi=$(docker compose exec -T php sh -c 'cat /var/www/site/storage/app/smoke-job 2>/dev/null' || true)
if [ "$tick" = OK ] && [ "$sitemap_run" = succeeded ] && [ "$job_sapi" = fpm-fcgi ]; then
    echo "GET /kami/tick/{token}: OK, kami:sitemap and a queued job ran in-process"
else
    echo "GET /kami/tick/{token}: \"$tick\", kami:sitemap: \"$sitemap_run\", queued job run by: \"$job_sapi\""
    failed=1
fi

# A visit after five minutes without a Tick passes the pre-boot reader and starts one once FPM has sent the page.
ticks=/var/www/site/storage/framework/kami
docker compose exec -T php sh -c "rm -f $ticks/tick-visit; touch -d '10 minutes ago' $ticks/tick-*"
expect_page /smoke Bonjour
visit=
for _ in $(seq 1 20); do
    visit=$(docker compose exec -T php sh -c "cat $ticks/tick-visit 2>/dev/null" || true)
    [ -n "$visit" ] && break
    sleep 0.5
done
if grep -q '"result":"OK"' <<<"$visit"; then
    echo "Visit after a stale Tick: started one"
else
    echo "Visit after a stale Tick: tick-visit holds \"$visit\""
    failed=1
fi

# A Submission (psyao/kami#483) through the starter kit's contact form, with its SMTP server down: stored, its mail failed after the
# response, then delivered to Mailpit by the planner's URL alone. The failure's report is cut from the log before the empty-log check.
# The starter kit's `next` ships MAIL_HOST and MAIL_PORT commented out: dropped, set or not, then written.
docker compose run -T --rm cli sh -c "sed -i -E '/^(# )?MAIL_(MAILER|HOST|PORT)=/d' .env && printf 'MAIL_MAILER=smtp\nMAIL_HOST=mailpit\nMAIL_PORT=1025\n' >> .env"
docker compose run -T --rm cli php artisan optimize >/dev/null
site_log=/var/www/site/storage/logs/laravel.log
log_size=$(docker compose exec -T php sh -c "if [ -f $site_log ]; then wc -c < $site_log; else echo 0; fi" | tr -d ' \r')
home=$(site_php 'echo Psyao\Kami\Content\Entry::query()->ofType(App\Content\Home::class)->value("id");' 2>&1 || true)
posted=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --header 'X-Forwarded-Proto: https' --header 'Sec-Fetch-Site: same-origin' \
    --data-urlencode "_kami_origin=$home:fr" --data-urlencode 'name=Ada Lovelace' --data-urlencode 'email=ada@example.com' \
    --data-urlencode 'message=Hello from the smoke' "$base_url/kami/forms/contact" || true)
delivery() {
    site_php 'echo Illuminate\Support\Facades\DB::table("kami_form_deliveries")->get(["channel", "attempts", "delivered_at"])->map(fn ($row) => "$row->channel $row->attempts " . ($row->delivered_at === null ? "pending" : "delivered"))->implode(", ");' 2>&1 || true
}
failed_delivery=
for _ in $(seq 1 20); do
    failed_delivery=$(delivery)
    [ "$failed_delivery" = 'mail 1 pending' ] && break
    sleep 0.5
done
docker compose exec -T php truncate --no-create --size="$log_size" "$site_log" || true
docker compose --profile mail up --detach --wait mailpit >/dev/null 2>&1
# Due as if the last run were 20 minutes ago, past the minute the sweep leaves to a delivery `defer()` may still be sending.
site_php '
    Illuminate\Support\Facades\DB::table("kami_task_runs")->where("description", "kami:forms:deliver")->update(["last_run_at" => now()->subMinutes(20), "last_status" => null]);
    Illuminate\Support\Facades\DB::table("kami_form_deliveries")->update(["updated_at" => now()->subMinutes(2)]);
' >/dev/null 2>&1 || true
tick=$(tick_body "$token")
mailed=$(docker compose run -T --rm cli php -r 'echo json_decode(file_get_contents("http://mailpit:8025/api/v1/messages"))->messages[0]->Subject ?? "none";' 2>&1 || true)
if [ "$posted" = 303 ] && [ "$failed_delivery" = 'mail 1 pending' ] && [ "$tick" = OK ] && [ "$(delivery)" = 'mail 2 delivered' ] && [ "$mailed" = '[Contact] Ada Lovelace' ]; then
    echo "Submission: stored with SMTP down, delivered to Mailpit by the tick URL"
else
    echo "Submission: POST $posted, after the response \"$failed_delivery\", tick \"$tick\", then \"$(delivery)\", Mailpit's last subject \"$mailed\""
    failed=1
fi

# The page cache (psyao/kami#408): a page stored by one request is served again with the database stopped, after a recent Tick:
# with none for five minutes, the pre-boot reader lets Laravel answer, to start one.
# An Archive's first page and a Feed (psyao/kami#623) too, the Feed with its content type, of the Post type install.sh declares.
site_php '$app->make(Psyao\Kami\Content\Actions\SaveEntry::class)->handle(App\Content\Post::class, ["title" => "Premier article", "status" => "published"]);' >/dev/null
docker compose run -T --rm cli php artisan kami:tick >/dev/null
# `php artisan down` answers 503 before any cached page, as public/index.php checks maintenance before it requires Kami's reader.
expect_page /smoke Bonjour
expect_page /articles 'Premier article'
expect_page /articles/feed 'Premier article'
docker compose stop mariadb >/dev/null 2>&1
expect_page /smoke Bonjour
expect_page /articles 'Premier article'
expect_page /articles/feed 'Premier article'
feed_type=$(curl --silent --show-error --output /dev/null --write-out '%{content_type}' --header 'X-Forwarded-Proto: https' "$base_url/articles/feed" || true)
if [ "$feed_type" = 'application/rss+xml; charset=UTF-8' ]; then
    echo "GET /articles/feed from the cache: $feed_type"
else
    echo "GET /articles/feed from the cache: content type \"$feed_type\""
    failed=1
fi
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
