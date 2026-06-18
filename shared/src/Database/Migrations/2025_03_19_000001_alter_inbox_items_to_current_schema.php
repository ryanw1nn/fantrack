<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Align inbox_items with the current InboxItem model.
 *
 * Changes:
 *  - Drop transaction_event_id, add transaction_key (varchar, nullable, indexed)
 *  - Add operation_key (varchar, nullable)
 *  - Rename routing_key → route
 *  - Add retry_count (unsigned int, default 0)
 *  - Add next_retry_at (timestamp, nullable)
 *  - Rename processed_at → published_at
 *  - Change status default from 'pending' → 'publishing'
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migrate the service DB table (uses Laravel Schema builder)
        if (Schema::hasTable('inbox_items')) {
            $this->migrateServiceTable();
        }

        // Migrate tenant schema tables (uses raw SQL)
        foreach ($this->tenantSchemas() as $schema) {
            $this->migrateTenantTable($schema);
        }
    }

    public function down(): void
    {
        // Intentionally not reversible — the old schema is deprecated.
    }

    private function migrateServiceTable(): void
    {
        Schema::table('inbox_items', function (Blueprint $t) {
            if (Schema::hasColumn('inbox_items', 'transaction_event_id')) {
                $t->dropColumn('transaction_event_id');
            }
            if (!Schema::hasColumn('inbox_items', 'transaction_key')) {
                $t->string('transaction_key')->nullable()->index()->after('id');
            }
            if (!Schema::hasColumn('inbox_items', 'operation_key')) {
                $t->string('operation_key')->nullable()->after('transaction_key');
            }
            if (Schema::hasColumn('inbox_items', 'routing_key') && !Schema::hasColumn('inbox_items', 'route')) {
                $t->renameColumn('routing_key', 'route');
            }
            if (!Schema::hasColumn('inbox_items', 'retry_count')) {
                $t->unsignedInteger('retry_count')->default(0)->after('error_message');
            }
            if (!Schema::hasColumn('inbox_items', 'next_retry_at')) {
                $t->timestamp('next_retry_at')->nullable()->after('retry_count');
            }
            if (Schema::hasColumn('inbox_items', 'processed_at') && !Schema::hasColumn('inbox_items', 'published_at')) {
                $t->renameColumn('processed_at', 'published_at');
            }
        });

        DB::statement("ALTER TABLE inbox_items ALTER COLUMN status SET DEFAULT 'publishing'");
        DB::table('inbox_items')->where('status', 'pending')->update(['status' => 'publishing']);
    }

    private function migrateTenantTable(string $schema): void
    {
        $columns = $this->getColumns($schema);

        if (empty($columns)) {
            return;
        }

        // Drop transaction_event_id, add transaction_key
        if (in_array('transaction_event_id', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` DROP COLUMN `transaction_event_id`");
        }
        if (!in_array('transaction_key', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` ADD COLUMN `transaction_key` VARCHAR(255) NULL AFTER `id`");
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` ADD INDEX `inbox_items_transaction_key_index` (`transaction_key`)");
        }

        // Add operation_key
        if (!in_array('operation_key', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` ADD COLUMN `operation_key` VARCHAR(255) NULL AFTER `transaction_key`");
        }

        // Rename routing_key → route
        if (in_array('routing_key', $columns) && !in_array('route', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` CHANGE COLUMN `routing_key` `route` VARCHAR(255) NOT NULL");
        }

        // Add retry_count
        if (!in_array('retry_count', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` ADD COLUMN `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `error_message`");
        }

        // Add next_retry_at
        if (!in_array('next_retry_at', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` ADD COLUMN `next_retry_at` TIMESTAMP NULL AFTER `retry_count`");
        }

        // Rename processed_at → published_at
        if (in_array('processed_at', $columns) && !in_array('published_at', $columns)) {
            DB::statement("ALTER TABLE `{$schema}`.`inbox_items` CHANGE COLUMN `processed_at` `published_at` TIMESTAMP NULL");
        }

        // Change status default
        DB::statement("ALTER TABLE `{$schema}`.`inbox_items` ALTER COLUMN `status` SET DEFAULT 'publishing'");

        // Update existing pending rows
        DB::statement("UPDATE `{$schema}`.`inbox_items` SET `status` = 'publishing' WHERE `status` = 'pending'");
    }

    private function getColumns(string $schema): array
    {
        $rows = DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'inbox_items'",
            [$schema]
        );

        return array_map(fn($r) => $r->COLUMN_NAME ?? $r->column_name, $rows);
    }

    private function tenantSchemas(): array
    {
        $schemas = [];
        $serviceDb = config('database.connections.mysql.database');

        try {
            $rows = DB::select(
                "SELECT DISTINCT table_schema FROM information_schema.tables WHERE table_name = 'inbox_items' AND table_schema NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', ?)",
                [$serviceDb]
            );

            foreach ($rows as $row) {
                $schemas[] = $row->table_schema ?? $row->TABLE_SCHEMA;
            }
        } catch (\Exception $e) {
            // Skip tenant migration if info schema query fails
        }

        return $schemas;
    }
};
