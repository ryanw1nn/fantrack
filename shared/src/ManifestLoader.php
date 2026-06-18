<?php

namespace SynergyERP\Shared;

use SynergyERP\Shared\Utils\JsonFileLoader;

/**
 * Class ManifestLoader loads and validates manifest files
 * 
 * @author Alexander Torres
 * @package SynergyERP\Shared
 */
final class ManifestLoader
{
    private array $manifest;

    /**
     * Load manifest from the configured path
     * 
     * @param string|null $manifestPath Optional path override, defaults to env('MANIFEST_PATH')
     * @return self
     * @throws \RuntimeException If manifest is invalid or missing required structure
     */
    public static function load(?string $manifestPath = null): self
    {
        $path = $manifestPath ?? env('MANIFEST_PATH');
        
        if (empty($path)) {
            throw new \RuntimeException("Manifest path not configured. Set MANIFEST_PATH in environment.");
        }

        $data = JsonFileLoader::load($path);
        
        return new self($data);
    }

    /**
     * Constructor
     * 
     * @param array $manifest
     * @throws \RuntimeException If manifest structure is invalid
     */
    private function __construct(array $manifest)
    {
        $this->validateManifestStructure($manifest);
        $this->manifest = $manifest;
    }

    /**
     * Get the full manifest array
     * 
     * @return array
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * Get a specific model from the manifest
     * 
     * @param string $modelName
     * @return array|null
     */
    public function getModel(string $modelName): ?array
    {
        return $this->manifest['models'][$modelName] ?? null;
    }

    /**
     * Check if a model exists in the manifest
     * 
     * @param string $modelName
     * @return bool
     */
    public function hasModel(string $modelName): bool
    {
        return isset($this->manifest['models'][$modelName]);
    }

    /**
     * Get all models from the manifest
     * 
     * @return array
     */
    public function getModels(): array
    {
        return $this->manifest['models'];
    }

    /**
     * Get the declared schema for a specific model, if any.
     *
     * Reads `manifest.models.{name}.eloquent.schema`. Returns null when the
     * model, the `eloquent` block, or the `schema` key is missing, empty,
     * or set to the LAF placeholder labels "tenant" / "default" — those
     * mark the model as tenant-routable (per
     * laravel-service-factory/.../sql_generator.py:_MAIN_SCHEMA_LABELS),
     * meaning the caller should fall back to the per-request tenant
     * schema rather than treating the label as a real database name.
     *
     * @param string $modelName
     * @return string|null
     */
    public function getModelSchema(string $modelName): ?string
    {
        $schema = $this->manifest['models'][$modelName]['eloquent']['schema'] ?? null;

        if (!is_string($schema) || $schema === '' || $schema === 'tenant' || $schema === 'default' || $schema === 'service_default') {
            return null;
        }

        return $schema;
    }

    /**
     * Get the service name from the manifest
     *
     * @return string
     * @throws \RuntimeException If service name is not set
     */
    public function getServiceName(): string
    {
        if (!isset($this->manifest['service'])) {
            throw new \RuntimeException("Invalid manifest structure: missing 'service' key");
        }

        $service = $this->manifest['service'];

        if (is_array($service)) {
            if (!isset($service['name']) || !is_string($service['name'])) {
                throw new \RuntimeException("Invalid manifest structure: 'service.name' must be a string");
            }
            return $service['name'];
        }

        if (!is_string($service)) {
            throw new \RuntimeException("Invalid manifest structure: 'service' must be a string or object with a 'name' key");
        }

        return $service;
    }

    /**
     * Validate manifest structure
     * 
     * @param array $manifest
     * @throws \RuntimeException If manifest structure is invalid
     */
    private function validateManifestStructure(array $manifest): void
    {
        if (!isset($manifest['models'])) {
            throw new \RuntimeException("Invalid manifest structure: missing 'models' key");
        }

        if (!is_array($manifest['models'])) {
            throw new \RuntimeException("Invalid manifest structure: 'models' must be an array");
        }

        if (empty($manifest['models'])) {
            throw new \RuntimeException("Invalid manifest structure: 'models' array is empty");
        }
    }
}