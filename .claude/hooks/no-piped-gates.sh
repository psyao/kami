#!/usr/bin/env bash
# PreToolUse hook on Bash: refuses a gate (tests, analysis, push) piped into a filter.
# A pipe's status is its last command's, so `pest | tail` passes even when the tests fail (M5 retro, #308).
command=$(jq -r '.tool_input.command // empty')

if grep -qE '(^|[;&|(])[[:space:]]*((vendor/bin/)?(pest|phpstan)|composer +(test|analyse)(:[a-z-]+)?|git +push)([[:space:]][^|;]*)?\|[^|]' <<< "$command"; then
    echo 'A gate piped into a filter reports the filter'"'"'s status, not the gate'"'"'s. Redirect it instead: `<gate> > "$file" 2>&1; status=$?`, then read the file and test $status.' >&2
    exit 2
fi
