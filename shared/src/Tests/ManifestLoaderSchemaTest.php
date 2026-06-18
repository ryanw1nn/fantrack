<?php

namespace SynergyERP\Shared\Tests;

use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\ManifestLoader;

/**
 * Unit tests for ManifestLoader::getModelSchema(). Pure-function coverage —
 * no Laravel boot, no DB. Fixture manifests are written to temp files and
 * loaded via the public `load($path)` signature.
 */
final class ManifestLoaderSchemaTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/synergy_manifest_' . uniqid();
        mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixtureDir . '/*'));
        @rmdir($this->fixtureDir);
    }

    public function test_returns_schema_when_declared_in_manifest(): void
    {
        $manifest = $this->loadManifest([
            'service' => ['name' => 'auth-service'],
            'models' => [
                'UlidStore' => [
                    'eloquent' => ['schema' => 'accounts', 'table' => 'ulid_store'],
                ],
            ],
        ]);

        $this->assertSame('accounts', $manifest->getModelSchema('UlidStore'));
    }

    public function test_returns_null_when_model_missing(): void
    {
        $manifest = $this->loadManifest([
            'service' => 'project-service',
            'models' => ['Project' => ['eloquent' => []]],
        ]);

        $this->assertNull($manifest->getModelSchema('NotAModel'));
    }

    public function test_returns_null_when_eloquent_block_missing(): void
    {
        $manifest = $this->loadManifest([
            'service' => 'project-service',
            'models' => ['Project' => ['members' => []]],
        ]);

        $this->assertNull($manifest->getModelSchema('Project'));
    }

    public function test_returns_null_when_schema_is_empty_string(): void
    {
        $manifest = $this->loadManifest([
            'service' => 'project-service',
            'models' => ['Project' => ['eloquent' => ['schema' => '']]],
        ]);

        $this->assertNull($manifest->getModelSchema('Project'));
    }

    public function test_returns_null_when_schema_is_not_a_string(): void
    {
        $manifest = $this->loadManifest([
            'service' => 'project-service',
            'models' => ['Project' => ['eloquent' => ['schema' => 42]]],
        ]);

        $this->assertNull($manifest->getModelSchema('Project'));
    }

    public function test_returns_null_for_tenant_placeholder_label(): void
    {
        // The Python LAF generator emits "tenant" as a placeholder for
        // tenant-routable models. PHP must coerce it to null so callers
        // fall back to the per-request tenant schema (JWT) instead of
        // treating "tenant" as a real database name.
        $manifest = $this->loadManifest([
            'service' => 'directory-service',
            'models' => ['Channel' => ['eloquent' => ['schema' => 'tenant']]],
        ]);

        $this->assertNull($manifest->getModelSchema('Channel'));
    }

    public function test_returns_null_for_default_placeholder_label(): void
    {
        $manifest = $this->loadManifest([
            'service' => 'directory-service',
            'models' => ['Channel' => ['eloquent' => ['schema' => 'default']]],
        ]);

        $this->assertNull($manifest->getModelSchema('Channel'));
    }

    private function loadManifest(array $data): ManifestLoader
    {
        $path = $this->fixtureDir . '/manifest.json';
        file_put_contents($path, json_encode($data));
        return ManifestLoader::load($path);
    }
}
