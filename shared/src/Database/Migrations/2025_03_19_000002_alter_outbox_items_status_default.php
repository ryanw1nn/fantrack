<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix outbox_items across the service DB and all tenant schemas.
 *
 * Changes:
 *  - Change status default from 'pending' → 'publishing'
 *  - Make transaction_key nullable
 */
return new class extends Migration
{
    public function up(): void
    {
        // Service DB
        if (Schema::hasTable('outbox_items')) {
            DB::statement("ALTER TABLE outbox_items ALTER COLUMN status SET DEFAULT 'publishing'");
            DB::statement("ALTER TABLE outbox_items MODIFY transaction_key VARCHAR(255) NULL");
            DB::table('outbox_items')->where('status', 'pending')->update(['status' => 'publishing']);
        }

        // Tenant schemas
        foreach ($this->tenantSchemas() as $schema) {
            DB::statement("ALTER TABLE `{$schema}`.`outbox_items` ALTER COLUMN `status` SET DEFAULT 'publishing'");
            DB::statement("ALTER TABLE `{$schema}`.`outbox_items` MODIFY `transaction_key` VARCHAR(255) NULL");
            DB::statement("UPDATE `{$schema}`.`outbox_items` SET `status` = 'publishing' WHERE `status` = 'pending'");
        }
    }

    public function down(): void
    {
        // Intentionally not reversible — the old default is deprecated.
    }

    private function tenantSchemas(): array
    {
        $schemas = [];
        $serviceDb = config('database.connections.mysql.database');

        try {
            $rows = DB::select(
                "SELECT DISTINCT table_schema FROM information_schema.tables WHERE table_name = 'outbox_items' AND table_schema NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', ?)",
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
