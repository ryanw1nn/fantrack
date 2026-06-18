<?php

namespace SynergyERP\Shared\Cache;

use SynergyERP\Shared\Models\Operations\OperationKeyContext;

/**
 * Generates consistent cache keys from operation context and tenant information.
 * 
 * Key format: tenant:{tenantKey}|{modelName}:{modelId}    (single record)
 *              tenant:{tenantKey}|{modelName}              (list/collection)
 *              tenant:{tenantKey}|{modelName}:__version__  (version counter)
 * 
 * The pipe (|) delimiter separates the tenant scope from the resource identifier.
 * The colon (:) delimiter separates key-value pairs within each segment.
 * 
 * @author Ryan Winn
 * @package SynergyERP\Shared\Cache
 */
final class CacheKeyBuilder
{
    /**
     * Version counter sentinel value.
     * This is appended as the "id" for version tracking keys.
     */
    private const VERSION_SENTINEL = '__version__';

    /**
     * Separator between tenant scope and resource identifier.
     */
    private const SCOPE_SEPARATOR = "|";

    /**
     * Separator between key and value within a segment.
     */
    private const PAIR_SEPARATOR = ":";

    /**
     * Build a cache key for a single record.
     * 
     * @param string $tenantKey     The tenant identifier (from JWT)
     * @param string $modelName     The model name in kebab-case (e.g., "project", "project-segment")
     * @param int|string $modelId   The model's primary key
     * @param int|null $version     The current version counter value
     * @return string The cache key (e.g., "tenant:rrglass|project:42")
     */
    public static function forRecord(string $tenantKey, string $modelName, int|string $modelId, int $version): string
    {
        return self::buildTenantPrefix($tenantKey)
            . self::SCOPE_SEPARATOR
            . $modelName
            . self::PAIR_SEPARATOR
            . $modelId
            . self::PAIR_SEPARATOR
            . $version;
    }

    /**
     * Build a cache key for a model list/collection
     *
     * @param string $tenantKey The tenant identifier
     * @param string $modelName The model name in kebab-case
     * @return string The cache key (e.g., "tenant:rrglass|project")
     */
    public static function forList(string $tenantKey, string $modelName): string
    {
        return self::buildTenantPrefix($tenantKey)
            . self::SCOPE_SEPARATOR
            . $modelName;
    }

    /**
     * Build a cache key for a filtered search result.
     *
     * Appends a short hash of the query params (where/sort/group_by/limit) so
     * that two search calls with different WHERE clauses never share a cache entry.
     * Falls back to the plain list key when no filter params are present so
     * unfiltered list calls and "fetch all" calls share the same key as before.
     *
     * Invalidation still works via the version counter: any write to the model
     * increments the version and makes every cached search result (regardless of
     * its hash suffix) fail the version check on the next read.
     *
     * @param string $tenantKey   The tenant identifier
     * @param string $modelName   The model name in kebab-case
     * @param array  $requestData The raw request body (may contain where/sort/…)
     * @return string e.g. "tenant:rrglass|bid:a1b2c3d4e5f6" or "tenant:rrglass|bid"
     */
    public static function forSearch(string $tenantKey, string $modelName, array $requestData = []): string
    {
        $filterKeys = ['where', 'sort', 'group_by', 'limit'];
        $filters = [];
        foreach ($filterKeys as $key) {
            if (array_key_exists($key, $requestData)) {
                $filters[$key] = $requestData[$key];
            }
        }

        if (empty($filters)) {
            return self::forList($tenantKey, $modelName);
        }

        $hash = substr(md5(json_encode($filters, JSON_THROW_ON_ERROR)), 0, 12);
        return self::forList($tenantKey, $modelName) . self::PAIR_SEPARATOR . $hash;
    }

    /**
     * Build the version counter key for a model type within a tenant.
     * The version counter is incremented on every write operation to the model,
     * which invalidates list caches that depend on it.
     * 
     * @param string $tenantKey The tenant identifier
     * @param string $modelName The model name in kebab-case
     * @return string The cache key (e.g., "tenant:rrglass|project:__version__")
     */
    public static function forVersion(string $tenantKey, string $modelName): string
    {
        return self::buildTenantPrefix($tenantKey)
            . self::SCOPE_SEPARATOR
            . $modelName
            . self::PAIR_SEPARATOR
            . self::VERSION_SENTINEL;
    }

    /**
     * Build a collaboration room key. Uses the same tenant and resource
     * identifiers as cache keys, with a "room" segment inserted.
     * 
     * @param string $tenantKey     The tenant identifier
     * @param string $modelName     The model name
     * @param int|string $modelId   The model's primary key
     * @return string The room key (e.g., "tenant:rrglass|room|project:42")
     */
    public static function forRoom(string $tenantKey, string $modelName, int|string $modelId): string
    {
        return self::buildTenantPrefix($tenantKey)
            . self::SCOPE_SEPARATOR
            . 'room'
            . self::SCOPE_SEPARATOR
            . $modelName
            . self::PAIR_SEPARATOR
            . $modelId;
    }
    
    /**
     * Build cache key(s) from an OperationKeyContext and tenant key.
     * Returns an array because a command may affect both a record key and a list key.
     * 
     * @param OperationKeyContext $context  The parsed operation key
     * @param string $tenantKey             The tenant identifier
     * @param array $requestData            The validated request data (may contain 'id')
     * @return array{record: ?string, list: string, version: string} Cache keys
     */
    public static function fromContext(
        OperationKeyContext $context,
        string $tenantKey,
        array $requestData = []
    ): array {
        $modelName = $context->getOperationComponent('model');
        $modelId = $requestData['id'] ?? ($context->hasModelId() ? $context->getOperationComponent('id') : null);

        return [
            'model_id'      => $modelId,
            'model_name'    => $modelName,
            'tenant_key'    => $tenantKey,
            'list'          => self::forList($tenantKey, $modelName),
            'search'        => self::forSearch($tenantKey, $modelName, $requestData),
            'version'       => self::forVersion($tenantKey, $modelName),
        ];
    }

    /**
     * Build a record cache key once the version is known.
     * Call this after resolving the current version from the version counter.
     * 
     * @param array $cacheKeys  The result of fromContext()
     * @param int $vesrion      The current version counter value
     * @return string|null      The record key, or null if no model ID
     */
    public static function buildRecordKey(array $cacheKeys, int $currentVersion): ?string
    {
        if ($cacheKeys['model_id'] === null) {
            return null;
        }

        return self::forRecord(
            $cacheKeys['tenant_key'],
            $cacheKeys['model_name'],
            $cacheKeys['model_id'],
            $currentVersion
        );
    }

    /**
     * Parse a cache key back into its components.
     * 
     * @param string $cacheKey  A valid cache key
     * @return array{tenant_key: string, model_name: string, model_id: ?string}
     */
    public static function parse(string $cacheKey): array
    {
        // Split on scope separator: "tenant:rrglass|project:42" -> ["tenant:rrglass", "project:42"]
        $segments = explode(self::SCOPE_SEPARATOR, $cacheKey);

        // Extract tenant key from first segment
        $tenantParts = explode(self::PAIR_SEPARATOR, $segments[0], 2);
        $tenantKey = $tenantParts[1] ?? '';

        // Extract model info from last segment (skip "room" if present)
        $resourceSegment = end($segments);
        $resourceParts = explode(self::PAIR_SEPARATOR, $resourceSegment, 2);

        return [
            'tenant_key' => $tenantKey,
            'model_name' => $resourceParts[0],
            'model_id'   => $resourceParts[1] ?? null,
        ];
    }

    /**
     * Build the tenant prefix segment.
     * 
     * @param string $tenantKey
     * @return string "tenant:{tenantKey}"
     */
    private static function buildTenantPrefix(string $tenantKey): string
    {
        return 'tenant' . self::PAIR_SEPARATOR . $tenantKey;
    }
}


