<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('outbox_items')) {
            Schema::create('outbox_items', function (Blueprint $table) {
                $table->id();
                $table->string('transaction_key')->nullable()->index();
                $table->string('operation_key')->nullable();
                $table->string('idempotency_key')->nullable();
                $table->string('bus_exchange');
                $table->string('bus_route');
                $table->json('payload');
                $table->string('status', 50)->default('publishing')->index();
                $table->text('error_message')->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_items');
    }
};
