---
description: Pre-commit secret scan + auth-middleware coverage review
argument-hint: "(none)  — reviews the current staged/working changes"
allowed-tools: Bash(git status:*), Bash(git diff:*), Bash(grep:*), Bash(ls:*), Bash(find:*), Read, Glob
---

# /security-check

A manual security gate to run before committing. This complements — does not replace — the
commit-blocking secret-scan hook added in Phase 0.3. Report findings; do not commit anything.

> Note: this is `/security-check` (project command). The built-in `/security-review` is a
> separate, deeper review — use that for a full pass on a branch.

## 1. Secret scan (the "NEVER commit secrets / .env" rule)
- `git status` and `git diff --cached --name-only` — confirm **no `.env`** (only `.env.example`)
  is staged. `.env` and `.env.*` must be gitignored.
- Scan staged content for secret-shaped strings:
```bash
git diff --cached -U0 | grep -nEi "(api[_-]?key|secret|token|password|passwd|jwt[_-]?secret|aws_(access|secret)|private[_-]?key|-----BEGIN)" 
```
- Flag any hardcoded credential, even in docker-compose (dev RabbitMQ/MySQL creds are dev-only —
  confirm they are not production values and not new real secrets).

## 2. Auth-middleware coverage
- For each `{service}/src/routes/api.php`, list routes and confirm every non-public route sits
  behind auth middleware. Any write route (`create|update|delete|batch-*|restore|duplicate`)
  exposed without auth is a failure.
- Confirm the JWT `schema` claim is validated against league membership (not trusted alone) —
  CLAUDE.md tenancy rule. Flag any controller/service that reads the `schema` claim without a
  membership check.

## 3. Tenant isolation spot-check
- Confirm models extend `TenantModel` and queries are schema-scoped — flag any raw cross-schema
  query that could leak across leagues.

## 4. Input validation
- Confirm command contracts rely on the manifest-driven base `rules()` (Bug 8/10 stripped) rather
  than broken generated overrides that could weaken validation.

## Output
A pass/fail summary with three sections (Secrets, Auth coverage, Isolation/Validation). Any secret
hit is a hard **BLOCK** with the file:line. List every unprotected route. End with a clear
go / no-go for committing.
