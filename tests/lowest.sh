#!/usr/bin/env bash
# CI's Lowest job on this machine (psyao/kami#453): the Kami suite on the lowest versions composer.json allows, under
# Infomaniak's ini, serially. In a scratch copy of the files git knows, so the checkout's vendor/ stays as it is; the
# copy and its vendor/ are kept for the next run. CI's job exactly, on MariaDB: tests/engine.sh mariadb composer test:lowest.
set -euo pipefail
cd "$(dirname "$0")/.."

copy=${TMPDIR:-/tmp}/kami-lowest
mkdir -p "$copy"
git ls-files --cached --others --exclude-standard | rsync --archive --files-from=- ./ "$copy/"
cd "$copy"

composer update --prefer-lowest --prefer-dist --no-interaction --no-progress --no-scripts
php vendor/bin/testbench package:discover --ansi
# Loaded after Composer, which needs proc_open.
exec bash tests/infomaniak.sh "$@"
