#!/usr/bin/env bash
# PostToolUse(Write|Edit) hook — lint + type-check frontend files after Claude edits them.
set -euo pipefail

input=$(cat)
file=$(printf '%s' "$input" | /usr/bin/python3 -c 'import sys,json;print(json.load(sys.stdin).get("tool_input",{}).get("file_path",""))')

# Only act on frontend source files.
case "$file" in *.ts|*.tsx|*.js|*.jsx) ;; *) exit 0 ;; esac

# Toolchain not installed yet (no frontend package) → skip silently.
[ -x ./node_modules/.bin/eslint ] || exit 0

# 1) Autofix what eslint can, then report what it can't.
./node_modules/.bin/eslint --fix "$file" || { echo "eslint failed on $file" >&2; exit 2; }

# 2) Whole-project type check (tsc has no meaningful single-file mode).
if [ -x ./node_modules/.bin/tsc ]; then
  ./node_modules/.bin/tsc --noEmit || { echo "tsc --noEmit failed" >&2; exit 2; }
fi
