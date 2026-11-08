#!/usr/bin/env bash
# The cases no-piped-gates.sh must refuse (2) and let through (0). Run: bash .claude/hooks/no-piped-gates.test.sh
hook="$(dirname "$0")/no-piped-gates.sh"
fail=0

while IFS=$'\t' read -r expected cmd; do
    jq -n --arg c "$cmd" '{tool_input: {command: $c}}' | "$hook" 2>/dev/null
    got=$?
    if [ "$got" != "$expected" ]; then
        echo "expected $expected, got $got: $cmd"
        fail=1
    fi
done <<'CASES'
2	vendor/bin/pest | tail
2	time vendor/bin/pest --parallel | tail -5
2	timeout 600 composer test | tail
2	APP_ENV=testing vendor/bin/pest | grep x
2	cd x && git push origin b | tail
2	composer test:coverage | tail
2	env X=1 phpstan analyse | tail
0	vendor/bin/pest --filter="TrashAndRestoreTest|PreviewLinksTest" > f 2>&1; rc=$?
0	vendor/bin/pest --filter='A|B' > f 2>&1
0	vendor/bin/pest > f 2>&1 || true
0	gh pr checks | grep -v pass
0	echo "pest | tail is refused"
CASES

exit $fail
