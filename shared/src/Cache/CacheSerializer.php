<?php

namespace SynergyERP\Shared\Cache;

use Illuminate\Support\Facades\Log;

/**
 * Handles serialization and deserialization of cache values.
 * 
 * All values stored in Redis via the Cache API are strings.
 * This class converts between PHP arrays/objects and JSON strings,
 * with envelope metadata for cache validation.
 * 
 * @author Ryan Winn
 * @package SynergyERP\Shared\Cache
 */

final class CacheSerializer
{
    /**
     * Serialize data for cache storage
     * Wraps the data in an envelope with metadata for validation and debugging.
     * 
     * @param mixed $data       The data to serialize (typically an array from handler results)
     * @param int|null $version The model version at time of caching
     * @return string JSON-encoded string ready for Redis SET
     * @throws \JsonException If JSON encoding fails
     */
    public static function serialize(mixed $data, ?int $version = null): string
    {
        $envelope = [
            'cached_at' => date('Y-m-d\TH:i:s.u\Z'),
            'version'   => $version,
            'data'      => $data,
        ];

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Deserialize data from cache storage.
     * Extracts the original data from the cache envelope.
     * 
     * @param string $raw   The raw JSON string from Redis GET
     * @return array{data: mixed, version: ?int, cached_at: string} The deserialized envelope
     * @throws \JsonException If JSON decoding fails
     */
    public static function deserialize(string $raw): array
    {
        $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($envelope['data'])) {
            Log::warning('[CacheSerializer] deserialized value missing "data" key, treating raw value as data', [
                'raw_length' => strlen($raw),
            ]);
            return [
                'data'      => $envelope,
                'version'   => null,
                'cached_at' => null,
            ];
        }

        return [
            'data'      => $envelope['data'],
            'version'   => $envelope['version'] ?? null,
            'cached_at' => $envelope['cached_at'] ?? null,
        ];
    }

    /**
     * Check if a cached value is still valid against the current version.
     * 
     * @param array $deserialized   The output of deserialize()
     * @param int $currentVersion   The current version from the version counter
     * @return bool True if cached version matches the current version
     */
    public static function isVersionValid(array $deserialized, int $currentVersion): bool
    {
        return isset($deserialized['version']) && $deserialized['version'] === $currentVersion;
    }
}