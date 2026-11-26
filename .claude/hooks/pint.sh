#!/usr/bin/env bash
# Formats the PHP file an agent just edited, so style never fails `composer test`.
file=$(jq -r '.tool_input.file_path // empty')
[[ "$file" == *.php && -f "$file" ]] || exit 0
cd "$CLAUDE_PROJECT_DIR" && vendor/bin/pint --quiet "$file"
