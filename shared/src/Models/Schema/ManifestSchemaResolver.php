<?php

namespace SynergyERP\Shared\Models\Schema;

use SynergyERP\Shared\ManifestLoader;

/**
 * Central schema-resolution policy for non-tenant Eloquent models.
 *
 * Resolution order for a given model:
 *   1. `manifest.models.{modelName}.eloquent.schema` — honours non-conforming
 *      shapes like auth-service models that live in `accounts`.
 *   2. `manifest.service.name` — the service-level default (e.g. "auth-service",
 *      "project-service").
 *   3. Throw. A missing schema is a configuration bug, not a runtime fallback:
 *      `TransactionEventMiddleware` catches the throw and renders a
 *      `TransactionError` 500 rather than silently writing into the wrong
 *      database.
 *
 * `TenantModel` does not go through this resolver — its schema comes from
 * the per-request tenant JWT.
 */
final class ManifestSchemaResolver
{
    public static function resolve(string $modelName, ?ManifestLoader $manifest = null): string
    {
        $manifest ??= ManifestLoader::load();

        $modelSchema = $manifest->getModelSchema($modelName);
        if ($modelSchema !== null) {
            return $modelSchema;
        }

        try {
            $serviceName = $manifest->getServiceName();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot resolve database schema for %s: no model schema declared and service name unavailable (%s)',
                    $modelName,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        if ($serviceName === '') {
            throw new \RuntimeException(
                sprintf('Cannot resolve database schema for %s: service name is empty', $modelName)
            );
        }

        return $serviceName;
    }
}
