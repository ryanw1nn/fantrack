<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transaction_events')) {
            Schema::create('transaction_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('idempotency_key')->unique();
                $table->string('transaction_key')->index();
                $table->string('operation_key');
                $table->integer('model_version')->default(1);
                $table->enum('status', ['started', 'completed', 'failed']);
                $table->json('request');
                $table->json('response')->nullable();
                $table->char('principal_puid', 26)->nullable();
                $table->char('delegated_puid', 26)->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('centralized_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'created_at'], 'idx_transaction_events_logged_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_events');
    }
};