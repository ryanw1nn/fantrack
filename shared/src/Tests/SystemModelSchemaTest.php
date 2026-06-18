<?php

namespace SynergyERP\Shared\Tests;

use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\Models\Base\SystemModel;

/**
 * Covers SystemModel::getTable() short-circuits:
 *   - a subclass that pins `$schema` directly,
 *   - a table string that already includes a schema prefix.
 *
 * The manifest-resolution path is covered by ManifestSchemaResolverTest so
 * this test avoids needing a Laravel app boot.
 */
final class SystemModelSchemaTest extends TestCase
{
    public function test_subclass_pinned_schema_short_circuits_resolver(): void
    {
        $model = new class extends SystemModel {
            protected $schema = 'custom_schema';
            protected $table  = 'widgets';
        };

        $this->assertSame('custom_schema.widgets', $model->getTable());
    }

    public function test_table_with_existing_prefix_passes_through_unchanged(): void
    {
        $model = new class extends SystemModel {
            protected $schema = 'ignored_schema';
            protected $table  = 'already_prefixed.things';
        };

        $this->assertSame('already_prefixed.things', $model->getTable());
    }
}
