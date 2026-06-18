<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Support\Str;

/**
 * Factory for generating unique identifiers with prefixes and hash components
 * 
 * @package SynergyERP\Shared\Services
 */
class UlidFactory
{
    /**
     * Generate a unique identifier with the given prefix
     * Format: {prefix}-{uuid}
     *
     * @param string $prefix The prefix to use (e.g., 'tenant', 'principal')
     * @return string The generated identifier
     */
    public static function generate(string $prefix): string
    {
        return $prefix . '-' . Str::uuid();
    }
    
    /**
     * Generate a unique identifier with the given prefix and data hash
     * Format: {prefix}-{uuid}_{hash}
     *
     * @param string $prefix The prefix to use (e.g., 'transaction', 'principal')
     * @param array|string $data Data to hash (array will be JSON encoded)
     * @return string The generated identifier with hash
     */
    public static function generateWithHash(string $prefix, $data): string
    {
        $uuid = Str::uuid();
        $hash = is_array($data) ? md5(json_encode($data)) : md5($data);
        
        return "{$prefix}-{$uuid}_{$hash}";
    }
    
    /**
     * Generate a unique identifier that's guaranteed to be unique among existing IDs
     *
     * @param string $prefix The prefix to use
     * @param array $existingIds Array of existing IDs to check against
     * @return string The generated unique identifier
     */
    public static function generateUnique(string $prefix, array $existingIds = []): string
    {
        $key = self::generate($prefix);
        
        // Ensure the key is unique
        while (in_array($key, $existingIds)) {
            $key = self::generate($prefix);
        }
        
        return $key;
    }
    
    /**
     * Extract the UUID portion from a generated ID
     *
     * @param string $id The ID to extract from
     * @return string|null The UUID portion or null if not found
     */
    public static function extractUuid(string $id): ?string
    {
        if (preg_match('/[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/', $id, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Extract the hash portion from a generated ID
     *
     * @param string $id The ID to extract from
     * @return string|null The hash portion or null if not found
     */
    public static function extractHash(string $id): ?string
    {
        if (preg_match('/[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_([0-9a-f]{32})/', $id, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
}
