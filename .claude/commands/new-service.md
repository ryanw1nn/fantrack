---
description: Scaffolding checklist when LAF adds a new service
argument-hint: "<service-name>  e.g. matchup-service"
allowed-tools: Bash(grep:*), Bash(ls:*), Bash(find:*), Read, Glob
---

# /new-service

Walk the integration checklist for a newly LAF-generated service: **`$ARGUMENTS`**.
If no name was given, ask which service before proceeding.

Work through each item, report ✅/❌ with specifics, and stop to flag anything that needs a manual
decision (like which registrar port to assign). Do not edit files unless I confirm.

## 1. Run the bug audit first
Invoke the same checks as `/laf-audit` scoped to this service. Don't proceed past a ❌ on Bug 1,
3, or 9 — those break boot.

## 2. Registrar port
- Open `infrastructure/gateway/registrar.json` and confirm `$ARGUMENTS` has a **unique** port
  (not 9000, not colliding with another service).
- Confirm `docker-compose.development.yml` runs `php artisan serve --host=0.0.0.0 --port=<that>`
  and maps `"<port>:<port>"` (Bug 1).
- If no port is assigned yet, list the ports already in use and propose the next free one.

## 3. Route prefix
- Open `{service}/src/routes/api.php`. Confirm routes use `->prefix('api')` (the gateway strips
  the `/{service}/` segment).
- Confirm the CQRS pattern holds: `POST /{model}/{action}` with action ∈
  `create|update|delete|restore|duplicate|batch-update|batch-delete|fetch|search`.

## 4. manifest.json ↔ frontend types (Bug 5 / Bug 11)
For every model in `{service}/src/manifest.json`:
- Cross-check the generated `src/types/generated/{module}/{service}/Model.ts` field names against
  the manifest. **Manifest is ground truth.**
- Verify FK type conventions: `*_id` = BIGINT (internal), `*_puid` = CHAR(26) (cross-service ULID).
- Verify the `{Model}Api`/`{Model}Meta` `baseUrl` path matches `Route::prefix(...)` exactly.
- Confirm only `fillable: true` fields appear in the Create/Update Zod schemas.

## 5. Infra tables (Bug 9)
- Confirm `{service}/docker/mysql-init/service_infra_models.sql` exists with
  `inbox_items` + `outbox_items` (CREATE TABLE IF NOT EXISTS, in the service's own schema).

## 6. Per-service CLAUDE.md
- Confirm the LAF-generated `{service}/CLAUDE.md` stub is present and kept.

## Output
A checklist with ✅/❌ per item. For each ❌, name the file and the exact CLAUDE.md fix. End with
the single most important next action.
