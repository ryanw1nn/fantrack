<?php

namespace SynergyERP\Shared\Tests\Services;

use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\Models\Base\TenantModel;
use SynergyERP\Shared\Services\StoragePathBuilder;

class StoragePathBuilderTest extends TestCase
{
    private StoragePathBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new StoragePathBuilder();
    }

    public function test_builds_canonical_key(): void
    {
        $model = $this->model('Estimate', 'tenant_acme', '01HXYZABCDEFGHJKMNPQRSTVWX');

        $this->assertSame(
            'tenant_acme/Estimate/01HXYZABCDEFGHJKMNPQRSTVWX/proposal.pdf',
            $this->builder->keyFor($model, 'proposal.pdf'),
        );
    }

    public function test_prefix_excludes_filename(): void
    {
        $model = $this->model('Project', 'tenant_acme', '01HXYZ0000000000000000ABCD');

        $this->assertSame(
            'tenant_acme/Project/01HXYZ0000000000000000ABCD',
            $this->builder->prefixFor($model),
        );
    }

    public function test_strips_directory_traversal_from_filename(): void
    {
        $model = $this->model('File', 'tenant_acme', '01HXYZ0000000000000000ABCD');

        $this->assertSame(
            'tenant_acme/File/01HXYZ0000000000000000ABCD/etc_passwd',
            $this->builder->keyFor($model, '../../etc/passwd'),
        );
    }

    public function test_replaces_unsafe_filename_chars(): void
    {
        $model = $this->model('File', 'tenant_acme', '01HXYZ0000000000000000ABCD');

        $this->assertSame(
            'tenant_acme/File/01HXYZ0000000000000000ABCD/weird_name_v2.pdf',
            $this->builder->keyFor($model, 'weird name (v2).pdf'),
        );
    }

    public function test_rejects_empty_filename(): void
    {
        $model = $this->model('File', 'tenant_acme', '01HXYZ0000000000000000ABCD');

        $this->expectException(\RuntimeException::class);
        $this->builder->keyFor($model, '///');
    }

    public function test_rejects_missing_tenant_schema(): void
    {
        $model = $this->model('File', null, '01HXYZ0000000000000000ABCD');

        $this->expectException(\RuntimeException::class);
        $this->builder->keyFor($model, 'a.pdf');
    }

    public function test_rejects_missing_public_id(): void
    {
        $model = $this->model('File', 'tenant_acme', null);

        $this->expectException(\RuntimeException::class);
        $this->builder->keyFor($model, 'a.pdf');
    }

    private function model(string $class, ?string $schema, ?string $publicId): TenantModel
    {
        $fqcn = __NAMESPACE__ . '\\Fixtures\\' . $class;
        if (!class_exists($fqcn)) {
            eval(
                'namespace ' . __NAMESPACE__ . '\\Fixtures; '
                . 'class ' . $class . ' extends \\SynergyERP\\Shared\\Models\\Base\\TenantModel {}'
            );
        }

        $instance = new $fqcn();
        $fqcn::setTransactionSchema($schema);
        $instance->public_id = $publicId;
        return $instance;
    }
}
