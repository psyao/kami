#!/usr/bin/env bash
# Runs a suite, or any command, on one of CI's engines in Docker (psyao/kami#454): the images, health checks and
# credentials of tests.yml and volume.yml. The container stays up and is reused: `docker stop kami-<engine>` removes it.
#
#   tests/engine.sh pgsql                          the Kami suite, in parallel
#   tests/engine.sh mysql Volume                   a named suite, then pest's own arguments
#   tests/engine.sh mariadb composer test:lowest   any command, with the engine's DB_* in its environment
set -euo pipefail
cd "$(dirname "$0")/.."

engine=${1:-}
case "$engine" in
    mysql)
        image=mysql:8.4 port=53306 inner=3306 user=root
        run=(-e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=kami --health-cmd='mysqladmin ping -h 127.0.0.1 -ppassword')
        ;;
    mariadb)
        image=mariadb:10.11 port=53307 inner=3306 user=root
        run=(-e MARIADB_ROOT_PASSWORD=password -e MARIADB_DATABASE=kami --health-cmd='healthcheck.sh --connect --innodb_initialized')
        ;;
    pgsql)
        image=postgres:15 port=55432 inner=5432 user=postgres
        run=(-e POSTGRES_PASSWORD=password -e POSTGRES_DB=kami --health-cmd=pg_isready)
        ;;
    *)
        echo "Usage: tests/engine.sh mysql|mariadb|pgsql [suite [pest arguments] | command]" >&2
        exit 2
        ;;
esac
shift
container=kami-$engine

if [ -z "$(docker ps --quiet --filter "name=^$container$")" ]; then
    # Docker Hub can hang a pull for minutes (psyao/kami#455): an image missing locally is pulled under a bound.
    docker image inspect "$image" >/dev/null 2>&1 || timeout 300 docker pull --quiet "$image" >/dev/null
    docker run --detach --rm --name "$container" --publish "$port:$inner" --health-interval=2s --health-retries=60 "${run[@]}" "$image" >/dev/null
fi

for _ in $(seq 120); do
    status=$(docker inspect --format '{{.State.Health.Status}}' "$container")
    [ "$status" = healthy ] && break
    sleep 1
done
[ "$status" = healthy ] || { echo "$container is $status after 120 s: docker logs $container" >&2; exit 1; }

export DB_CONNECTION=$engine DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=kami DB_USERNAME=$user DB_PASSWORD=password

case "${1:-Kami}" in
    Kami) shift $(($# > 0)); exec vendor/bin/pest --testsuite=Kami --parallel "$@" ;;
    Install | Volume | Browser) suite=$1; shift; exec vendor/bin/pest --testsuite="$suite" "$@" ;;
    *) exec "$@" ;;
esac
