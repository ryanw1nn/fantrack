<?php

namespace SynergyERP\Shared\Cache;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Global Cache API (FastAPI).
 * 
 * Communicates with the cache-api service over the Docker network.
 * All cache operations are fire-and-forget safe - failures are logged.
 * but never block the request pipeline. The database is always canonical.
 * 
 * @author Ryan Winn
 * @package SynergyERP\Shared\Cache
 */
class CacheService
{
    /**
     * Base URL for the cache API.
     * Resolved from environment or defaults to Docker service name.
     */
    private string $baseUrl;

    /**
     * The cache instance name to use (maps to CACHE_CONFIG in cache-api).
     */
    private string $cacheName;

    /**
     * HTTP timeout in seconds. Cache operations should be fast.
     * If they're slow, we'd rather skip and hit the DB.
     */
    private int $timeout;

    public function __construct(
        ?string $baseUrl = null,
        string $cacheName = 'global',
        int $timeout = 2
    ) {
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->cacheName = $cacheName;
        $this->timeout = $timeout;
    }

    /** Get a value from cache.
     * 
     * @param string $key   The cache key
     * @return array|null   The deserialized cache envelope, or null on miss/error
     */
    public function get(string $key): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/get", [
                    'key' => $key,
                ]);

            if (!$response->successful()) {
                Log::warning('[CacheService] GET request failed', [
                    'key'       => $key,
                    'status'    => $response->status(),
                ]);
                return null;
            }

            $body = $response->json();

            if(!($body['found'] ?? false)) {
                return null;
            }

            return CacheSerializer::deserialize($body['value']);
        } catch (\Throwable $e) {
            Log::warning('[CacheService] GET exception (non-blocking)', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Set a value in cache.
     * 
     * @param string $key       The cache key
     * @param mixed $data       The data to cache
     * @param int|null $version The version number to embed in the cache envelope
     * @param int|null $ttl     Time-to-live in seconds (null = no expiry)
     * @return bool True if SET succeeded
     */
    public function set(string $key, mixed $data, ?int $version = null, ?int $ttl = null): bool
    {
        try {
            $serialized = CacheSerializer::serialize($data, $version);

            $payload = [
                'key'   => $key,
                'value' => $serialized,
            ];

            if ($ttl !== null) {
                $payload['ttl'] = $ttl;
            }

            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/set", $payload);

            if (!$response->successful()) {
                Log::warning('[CacheService] SET request failed', [
                    'key'       => $key,
                    'status'    => $response->status(),
                ]);
                return false;
            }

            return $response->json('success') ?? false;
        } catch (\Throwable $e) {
            Log::warning('[CacheService] SET exception (non-blocking)', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete a key from cache.
     * 
     * @param string $key   The cache key to delete
     * @return bool True if DELETE succeeded
     */
    public function delete(string $key): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/delete", [
                    'key' => $key,
                ]);

            return $response->successful() && ($response->json('success') ?? false);
        } catch (\Throwable $e) {
            Log::warning('[CacheService] DELETE exception (non-blocking)', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get multiple values from cache in a single request.
     * 
     * @param array $keys   List of cache keys
     * @return array Map of key => deserialized envelope (null for misses)
     */
    public function multiGet(array $keys): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/multi-get", [
                    'keys' => $keys,
                ]);

            if (!$response->successful()) {
                return array_fill_keys($keys, null);
            }

            $results = $response->json('results') ?? [];
            $deserialized = [];

            foreach ($results as $key => $value) {
                if ($value === null) {
                    $deserialized[$key] = null;
                    continue;
                }
                try {
                    $deserialized[$key] = CacheSerializer::deserialize($value);
                } catch (\Throwable $e) {
                    Log::warning('[CacheService] Failed to deserialize value in multi-get', [
                        'key'   => $key,
                        'error' => $e->getMessage(),
                    ]);
                    $deserialized[$key] = null;
                }
            }

            return $deserialized;
        } catch (\Throwable $e) {
            Log::warning('[CacheService] MULTI-GET exception (non-blocking)', [
                'keys'   => $keys,
                'error' => $e->getMessage(),
            ]);
            return array_fill_keys($keys, null);
        }
    }

    /**
     * Check if a key exists in cache.
     * 
     * @param string $key   The cache key
     * @return bool True if the key exists
     */
    public function exists(string $key): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/exists", [
                    'key' => $key,
                ]);

            return $response->successful() && ($response->json('success') ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Atomically increment a version counter.
     * Uses Redis INCR via the cache-api - atomic and safe for concurrent access.
     * 
     * @param string $key   The version counter key
     * @return int|null     The new version value, or null on failure
     */
    public function increment(string $key): ?int
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/increment", [
                    'key' => $key,
                ]);

            if (!$response->successful()) {
                return null;
            }

            return $response->json('value');
        } catch (\Throwable $e) {
            Log::warning('[CacheService] INCREMENT exception (non-blocking)', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Delete multiple keys in a single request
     * 
     * @param array $keys   List of cache keys to delete
     * @return int          Number of keys deleted
     */
    public function batchDelete(array $keys): int
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/batch-delete", [
                    'keys' => $keys,
                ]);

            if (!$response->successful()) {
                return 0;
            }

            return $response->json('deleted') ?? 0;
        } catch (\Throwable $e) {
            Log::warning('[CacheService] BATCH-DELETE (non-blocking)', [
                'keys'  => $keys,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get the current version counter value.
     * 
     * @param string $key   The version counter key
     * @return int          The current version (0 if not set)
     */
    public function getVersion(string $key): int
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/global-cache/{$this->cacheName}/version-get", [
                    'key' => $key,
                ]);
            if (!$response->successful()) {
                return 0;
            }

            return $response->json('version') ?? 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }



    /**
     * Resolve the cache API base URL from environment or default.
     * 
     * @return string The base URL (e.g., "http://global-cache-api:1001")
     */
    private function resolveBaseUrl(): string
    {
        $host = env('CACHE_API_HOST', 'global-cache-api');
        $port = env('CACHE_API_PORT', '1001');

        return "http://{$host}:{$port}";
    }
}
