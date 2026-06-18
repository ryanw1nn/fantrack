#!/usr/bin/env bash
# PreToolUse(Bash) hook — block `git commit` when staged changes contain secrets.
set -euo pipefail

input=$(cat)
command=$(printf '%s' "$input" | /usr/bin/python3 -c 'import sys,json;print(json.load(sys.stdin).get("tool_input",{}).get("command",""))')

# Only gate git commit; let everything else through.
case "$command" in *"git commit"*) ;; *) exit 0 ;; esac

reasons=()

# 1) No .env files staged (allow .env.example)
while IFS= read -r f; do
  [ -z "$f" ] && continue
  case "$f" in
    *.env.example) ;;
    *.env|*.env.*) reasons+=("Staged env file: $f") ;;
  esac
done < <(git diff --cached --name-only)

# 2) Secret-shaped strings in the staged diff
hits=$(git diff --cached -U0 | grep -nEi "(api[_-]?key|secret|token|password|passwd|jwt[_-]?secret|aws_(access|secret)|private[_-]?key|-----BEGIN)" || true)
[ -n "$hits" ] && reasons+=("Possible secrets in staged diff:"$'\n'"$hits")

if [ ${#reasons[@]} -gt 0 ]; then
  echo "BLOCKED — secret scan failed:" >&2
  printf '%s\n' "${reasons[@]}" >&2
  echo "Unstage/fix, or run /security-check to review." >&2
  exit 2   # exit 2 = block the tool call, feed stderr back to Claude
fi
exit 0
