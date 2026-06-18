<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transaction_events')) {
            return;
        }

        if (Schema::hasColumn('transaction_events', 'principal_id')
            && !Schema::hasColumn('transaction_events', 'principal_puid')) {
            DB::statement('ALTER TABLE `transaction_events` CHANGE `principal_id` `principal_puid` CHAR(26) NULL');
        }

        if (Schema::hasColumn('transaction_events', 'delegated_by')
            && !Schema::hasColumn('transaction_events', 'delegated_puid')) {
            DB::statement('ALTER TABLE `transaction_events` CHANGE `delegated_by` `delegated_puid` CHAR(26) NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('transaction_events')) {
            return;
        }

        if (Schema::hasColumn('transaction_events', 'principal_puid')
            && !Schema::hasColumn('transaction_events', 'principal_id')) {
            DB::statement('ALTER TABLE `transaction_events` CHANGE `principal_puid` `principal_id` BIGINT NULL');
        }

        if (Schema::hasColumn('transaction_events', 'delegated_puid')
            && !Schema::hasColumn('transaction_events', 'delegated_by')) {
            DB::statement('ALTER TABLE `transaction_events` CHANGE `delegated_puid` `delegated_by` BIGINT NULL');
        }
    }
};
