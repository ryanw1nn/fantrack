---
description: Regenerate and verify frontend types against backend manifests
argument-hint: "[service-name]  (optional; defaults to all services)"
allowed-tools: Bash(grep:*), Bash(ls:*), Bash(find:*), Read, Glob
---

# /sync-types

Verify that the frontend's generated TypeScript types match each backend service's
`manifest.json` — the manifest is **ground truth** (CLAUDE.md Bug 5 & Bug 11). Scope to
`$ARGUMENTS` if a service name is given, otherwise all services.

This command **reports drift**; it does not silently rewrite types. After reporting, offer to fix
the frontend `.ts` (never the manifest).

## Steps

### 1. Locate the pairs
For each service, pair `{service}/src/manifest.json` with its generated types under
`src/types/generated/{module}/{service}/*.ts`. List any model that has a manifest entry but no
generated type (or vice-versa).

### 2. Field-name drift (Bug 11)
For each model, diff the manifest field names against the TS interface, the Zod schemas, and the
`{Model}Fields` metadata. LAF's generator sometimes emits conceptual names instead of real DB
columns (e.g. `bid_round_id` vs `bid_phase_id`, `estimate_id` vs `estimate_puid`). Report each
mismatch as `manifest: <name>` vs `type: <name>`.

### 3. Type / nullability conventions
- `*_id` → BIGINT (internal FK); `*_puid` → CHAR(26) (cross-service ULID). Flag any TS field
  whose type contradicts the manifest.
- Required vs nullable must match the manifest.
- Only `fillable: true` members may appear in Create/Update schemas.

### 4. Route prefix (Bug 5)
Confirm each `{Model}Meta.baseUrl` / `{Model}Api` path matches the backend
`Route::prefix(...)` in `{service}/src/routes/api.php`.

## Output
Per service: a table of models, each ✅ or ❌ with the specific field/type/route mismatch and the
fix (always edit the frontend type, never the manifest). End with a count of drift items and an
offer to apply the fixes.
