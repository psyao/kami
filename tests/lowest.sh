#!/usr/bin/env bash
# CI's Lowest job on this machine (psyao/kami#453): the Kami suite on the lowest versions composer.json allows, under
# Infomaniak's ini, serially. In a scratch copy of the files git knows, so the checkout's vendor/ stays as it is; the
# copy's vendor/ is kept for the next run. CI's job exactly, on MariaDB: tests/engine.sh mariadb composer test:lowest.
set -euo pipefail
cd "$(dirname "$0")/.."

copy=${TMPDIR:-/tmp}/kami-lowest
mkdir -p "$copy"
# Emptied but for Composer's own, so a file the branch deleted or renamed does not run again (psyao/kami#518).
find "$copy" -mindepth 1 -maxdepth 1 ! -name vendor ! -name composer.lock -exec rm -rf {} +
git ls-files --cached --others --exclude-standard | rsync --archive --files-from=- ./ "$copy/"
cd "$copy"

# macOS purges old files under $TMPDIR; with installed.json left, Composer reinstalls nothing and a Pest that fails
# to include exits 0 without a test (psyao/kami#490). ponytail: Pest's binary stands for all of vendor/; a purge that
# spares it but hits another package makes the suite fail, never pass empty.
[ -f vendor/pestphp/pest/bin/pest ] || rm -rf vendor
composer update --prefer-lowest --prefer-dist --no-interaction --no-progress --no-scripts
php vendor/bin/testbench package:discover --ansi
# Loaded after Composer, which needs proc_open.
exec bash tests/infomaniak.sh "$@"
