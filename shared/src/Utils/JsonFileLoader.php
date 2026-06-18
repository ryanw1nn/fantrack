<?php

namespace SynergyERP\Shared\Utils;

/**
 * Class JsonFileLoader loads JSON files from the Docker filesystem
 * 
 * @author Alexander Torres
 * @package SynergyERP\Shared
 */
final class JsonFileLoader
{
    /**
     * Load a JSON file from the filesystem
     * 
     * @param string $path Absolute path to the JSON file
     * @return array Decoded JSON data as an associative array
     * @throws \RuntimeException If file doesn't exist, isn't readable, or contains invalid JSON
     */
    public static function load(string $path): array 
    {
        try {
            if (!file_exists($path)) {
                $directory = dirname($path);
                
                if (!is_dir($directory)) {
                    throw new \RuntimeException("Directory does not exist: {$directory}");
                }
                throw new \RuntimeException("File does not exist: {$path}");
            }

            if (!is_readable($path)) {
                throw new \RuntimeException("File is not readable: {$path}");
            }

            $contents = file_get_contents($path);
            
            if ($contents === false) {
                throw new \RuntimeException("Failed to read file contents: {$path}");
            }

            $jsonFile = json_decode($contents, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();
                throw new \RuntimeException("Failed to parse JSON: {$errorMsg}");
            }

            if (!is_array($jsonFile)) {
                throw new \RuntimeException("JSON file must contain an array");
            }

            return $jsonFile;
        
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException("Error loading manifest: {$e->getMessage()}", 0, $e);
        }
    }
}