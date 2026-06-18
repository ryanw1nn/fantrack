<?php

namespace SynergyERP\Shared\Tests;

use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\ManifestLoader;
use SynergyERP\Shared\Models\Schema\ManifestSchemaResolver;

/**
 * Unit tests for ManifestSchemaResolver::resolve(). Covers the three-step
 * resolution order (per-model schema → service name → throw) by injecting
 * fixture manifests via the optional second argument.
 */
final class ManifestSchemaResolverTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/synergy_resolver_' . uniqid();
        mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixtureDir . '/*'));
        @rmdir($this->fixtureDir);
    }

    public function test_returns_per_model_schema_when_declared(): void
    {
        $manifest = $this->loadManifest([
            'service' => ['name' => 'auth-service'],
            'models' => [
                'UlidStore' => ['eloquent' => ['schema' => 'accounts']],
            ],
        ]);

        $this->assertSame('accounts', ManifestSchemaResolver::resolve('UlidStore', $manifest));
    }

    public function test_falls_back_to_service_name_when_model_has_no_schema(): void
    {
        $manifest = $this->loadManifest([
            'service' => ['name' => 'project-service'],
            'models' => [
                'Project' => ['eloquent' => []],
            ],
        ]);

        $this->assertSame('project-service', ManifestSchemaResolver::resolve('Project', $manifest));
    }

    public function test_accepts_plain_string_service_name(): void
    {
        $manifest = $this->loadManifest([
            'service' => 'project-service',
            'models' => ['Project' => ['eloquent' => []]],
        ]);

        $this->assertSame('project-service', ManifestSchemaResolver::resolve('Project', $manifest));
    }

    public function test_throws_when_service_name_is_invalid(): void
    {
        $manifest = $this->loadManifest([
            'service' => ['version' => '1'],
            'models' => ['Project' => ['eloquent' => []]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve database schema for Project');

        ManifestSchemaResolver::resolve('Project', $manifest);
    }

    public function test_model_schema_beats_service_name(): void
    {
        $manifest = $this->loadManifest([
            'service' => ['name' => 'auth-service'],
            'models' => [
                'UlidStore' => ['eloquent' => ['schema' => 'accounts']],
            ],
        ]);

        $this->assertSame(
            'accounts',
            ManifestSchemaResolver::resolve('UlidStore', $manifest),
            'per-model schema must take precedence over service name'
        );
    }

    private function loadManifest(array $data): ManifestLoader
    {
        $path = $this->fixtureDir . '/manifest.json';
        file_put_contents($path, json_encode($data));
        return ManifestLoader::load($path);
    }
}
