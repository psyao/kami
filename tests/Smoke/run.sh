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

# One step per subsystem as the milestones land it.
expect 200 /admin/login

logs=$(docker compose exec -T php sh -c 'cat /var/log/php/error.log /var/www/site/storage/logs/*.log 2>/dev/null' || true)
if [ -n "$logs" ]; then
    echo "The error logs are not empty:"
    echo "$logs"
    failed=1
fi

exit "$failed"
