#!/usr/bin/env bash
# PostToolUse(Write|Edit) hook — format-check PHP files after Claude edits them.
set -euo pipefail

input=$(cat)
file=$(printf '%s' "$input" | /usr/bin/python3 -c 'import sys,json;print(json.load(sys.stdin).get("tool_input",{}).get("file_path",""))')

case "$file" in *.php) ;; *) exit 0 ;; esac

# Toolchain not installed yet → skip silently.
[ -x ./vendor/bin/pint ] || exit 0

./vendor/bin/pint --test "$file" || { echo "pint failed on $file" >&2; exit 2; }
