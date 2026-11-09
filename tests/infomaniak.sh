#!/usr/bin/env bash
# The Kami suite under Infomaniak's ini (psyao/kami#453), serially: the ini disables proc_open, which Paratest needs.
# Each setting goes through -d, which wins over every ini file: Herd's PHP ignores PHP_INI_SCAN_DIR.
set -euo pipefail
cd "$(dirname "$0")/.."

settings=()
while read -r setting; do settings+=("$setting"); done < <(sed -n 's/^\([a-z_.]*\) = \(.*\)$/-d\1=\2/p' tests/infomaniak.ini)
# A line the pattern misses would leave a setting out without a word.
if [ "${#settings[@]}" -ne "$(grep -cvE '^(;|$)' tests/infomaniak.ini)" ]; then
    echo "tests/infomaniak.ini holds a line other than \`key = value\` or \`; comment\`" >&2
    exit 1
fi
exec php "${settings[@]}" vendor/bin/pest --testsuite=Kami "$@"
