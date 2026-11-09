#!/usr/bin/env bash
# The Kami suite under Infomaniak's ini (psyao/kami#453), serially: the ini disables proc_open, which Paratest needs.
# Each setting goes through -d, which wins over every ini file: Herd's PHP ignores PHP_INI_SCAN_DIR.
set -euo pipefail
cd "$(dirname "$0")/.."

settings=()
while read -r setting; do settings+=("$setting"); done < <(sed -n 's/^\([a-z_.]*\) = \(.*\)$/-d\1=\2/p' tests/infomaniak.ini)
exec php "${settings[@]}" vendor/bin/pest --testsuite=Kami "$@"
