<?php

namespace SynergyERP\Shared\Tests\Utils;

/**
 * Utility class for loading JSON data from files.
 */
class JsonFileLoader
{
    /**
     * Load and parse JSON from a file.
     *
     * @param string $filePath Path to the JSON file
     * @return array Decoded JSON data as associative array
     * @throws \JsonException If JSON cannot be parsed
     */
    public static function load(string $filePath): array
    {
        $json = file_get_contents($filePath);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}