#!/usr/bin/env bash
# PreToolUse hook on Bash: refuses a gate (tests, analysis, push) piped into a filter.
# A pipe's status is its last command's, so `pest | tail` passes even when the tests fail (M5 retro, #308).
command=$(jq -r '.tool_input.command // empty')
# Quoted text is an argument, never a pipe: `--filter="A|B"` passes (M7 retro, #452).
unquoted=$(sed -E "s/'[^']*'//g; s/\"([^\"\\\\]|\\\\.)*\"//g" <<< "$command")
# A gate may follow `time`, `timeout 600`, `env` or variable assignments.
prefix='((time|env|timeout[[:space:]]+[0-9]+[smh]?|[A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*)[[:space:]]+)*'

if grep -qE "(^|[;&|(])[[:space:]]*${prefix}((vendor/bin/)?(pest|phpstan)|composer +(test|analyse)(:[a-z-]+)?|git +push)([[:space:]][^|;]*)?\|[^|]" <<< "$unquoted"; then
    echo 'Refused: nothing in this command ran. A gate piped into a filter reports the filter'"'"'s status, not the gate'"'"'s. Redirect it instead: `<gate> > "$file" 2>&1; rc=$?`, then read the file and test $rc.' >&2
    exit 2
fi
