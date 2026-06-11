---
description: Run the post-LAF-generation known-bug audit across modules
argument-hint: "[module-path]  (optional; defaults to all modules)"
allowed-tools: Bash(grep:*), Bash(ls:*), Bash(find:*), Read, Glob
---

# /laf-audit

Sweep LAF-generated code for the "Known LAF Generation Bugs" catalogued in `CLAUDE.md`.
These recur on every generation pass because the same generator produced them.

**Scope:** `$ARGUMENTS` if provided (a single module path, e.g. `league-module/league-service`),
otherwise every `*-service` under `core-module/`, `league-module/`, and `import-module/`.
Skip `vendor/` and `node_modules/` everywhere.

Run each check below, then report a per-bug table: ✅ clean or ❌ with the offending file:line.
Do **not** auto-fix — this command only reports. Summarize hits and point me at the CLAUDE.md
fix for each.

## Checks

### Bug 4 — Query contracts extend non-existent `BaseQueryContract`
```bash
grep -rn "BaseQueryContract" {module}/src/app/Contracts --include="*.php" | grep -v vendor
```
Any hit is a failure. Fix: `Search*` → `extends SearchQueryContract`, `Fetch*` →
`extends FetchQueryContract`.

### Bug 8 — Search/Fetch contracts carry a stray `rules()` override
For every contract extending `SearchQueryContract`/`FetchQueryContract`, confirm it has NO
`rules()` method:
```bash
for f in $(grep -rl "extends SearchQueryContract\|extends FetchQueryContract" {module}/src/app/Contracts --include="*.php" | grep -v vendor); do
  grep -l "function rules" "$f"
done
```
Any file listed is a failure.

### Bug 10 — Command contracts carry a broken `rules()` override
`Create*`/`Update*`/`Delete*` contracts should have NO `rules()` (base class generates it from
manifest). Flag any that do — but **preserve `afterValidation()`**:
```bash
for f in $(grep -rl "extends CreateCommandContract\|extends UpdateCommandContract\|extends DeleteCommandContract" {module}/src/app/Contracts --include="*.php" | grep -v vendor); do
  grep -l "function rules" "$f"
done
```
Also flag HTML-entity-encoded rules anywhere:
```bash
grep -rn "&#x27;\|&gt;\|&lt;" {module}/src/app/Contracts --include="*.php" | grep -v vendor
```

### Bug 1 — Services default to port 9000
```bash
grep -rn "9000" {module} --include="docker-compose*.yml" | grep -v vendor
```
Each service must listen on its unique `infrastructure/gateway/registrar.json` port, not 9000.

### Bug 9 — Missing `service_infra_models.sql` (inbox/outbox tables)
For every non-auth service, this file must exist:
```bash
ls {module}/docker/mysql-init/service_infra_models.sql
```
Missing file = failure (outbox worker will crash on `{service}.outbox_items` not found).

### Bug 3 — `db_users.sql` uses DROP/CREATE USER
```bash
grep -rn "CREATE USER\|DROP USER" {module}/docker/mysql-init/db_users.sql
```
Any hit is a failure — `db_users.sql` must be GRANT-only.

### Bug 2 — Outbox worker missing entrypoint mount
In each module's `docker-compose.development.yml`, every outbox worker's `volumes:` must mount
`entrypoint-outbox.sh`:
```bash
grep -n "entrypoint-outbox.sh" {module}/docker-compose.development.yml
```
If an outbox worker service is defined but this mount is absent, flag it.

## Output
A markdown table — one row per bug, ✅/❌, and for failures the file:line plus a one-line pointer
to the CLAUDE.md fix. End with a count of total hits. On a clean/empty repo, all rows are ✅.
