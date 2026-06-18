<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

class CacheCommand
{
    // TTL constants in seconds
    const TTL_SHORT = 300;       // 5 minutes
    const TTL_MEDIUM = 1800;     // 30 minutes
    const TTL_LONG = 3600;       // 1 hour
    const TTL_EXTENDED = 86400;  // 24 hours
    
    /**
     * Get item from cache or compute and store it
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        return Cache::remember($key, $ttl, $callback);
    }
    
    /**
     * Store item in cache with namespace
     */
    public function put(string $namespace, string $key, $value, int $ttl = self::TTL_MEDIUM): void
    {
        $fullKey = $this->buildKey($namespace, $key);
        Cache::put($fullKey, $value, $ttl);
        
        // Track keys by namespace for group invalidation
        Redis::sadd("cache:namespaces:{$namespace}", $fullKey);
    }
    
    /**
     * Get item from cache
     */
    public function get(string $namespace, string $key)
    {
        return Cache::get($this->buildKey($namespace, $key));
    }
    
    /**
     * Check if item exists in cache
     */
    public function has(string $namespace, string $key): bool
    {
        return Cache::has($this->buildKey($namespace, $key));
    }
    
    /**
     * Remove item from cache
     */
    public function forget(string $namespace, string $key): void
    {
        $fullKey = $this->buildKey($namespace, $key);
        Cache::forget($fullKey);
        
        // Remove from namespace tracking
        Redis::srem("cache:namespaces:{$namespace}", $fullKey);
    }
    
    /**
     * Invalidate all cache entries for a namespace
     */
    public function flushNamespace(string $namespace): void
    {
        $keys = Redis::smembers("cache:namespaces:{$namespace}");
        
        if (!empty($keys)) {
            // Delete all keys in the namespace
            foreach ($keys as $key) {
                Cache::forget($key);
            }
            
            // Clear the namespace tracking
            Redis::del("cache:namespaces:{$namespace}");
        }
    }
    
    /**
     * Build a standardized cache key
     */
    protected function buildKey(string $namespace, string $key): string
    {
        return "synergy:{$namespace}:{$key}";
    }
}