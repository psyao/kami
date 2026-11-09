#!/usr/bin/env bash
# CI's Lowest job on this machine (psyao/kami#453): the Kami suite on the lowest versions composer.json allows, under
# Infomaniak's ini, serially. In a scratch copy, so the checkout's vendor/ stays as it is. The copy and its vendor/
# are kept for the next run: KAMI_LOWEST_DIR moves them. Another engine: tests/engine.sh mariadb composer test:lowest.
set -euo pipefail
cd "$(dirname "$0")/.."

copy=${KAMI_LOWEST_DIR:-${TMPDIR:-/tmp}/kami-lowest}
mkdir -p "$copy"
rsync --archive --delete --exclude=/.git/ --exclude=/vendor/ --exclude=/node_modules/ --exclude=/composer.lock ./ "$copy/"
cd "$copy"

composer update --prefer-lowest --prefer-dist --no-interaction --no-progress --no-scripts
php vendor/bin/testbench package:discover --ansi
# Loaded after Composer, which needs proc_open.
exec bash tests/infomaniak.sh "$@"
