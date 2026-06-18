<?php

namespace SynergyERP\Shared\Providers;

use Illuminate\Support\ServiceProvider;

use SynergyERP\Shared\Outbox\OutboxService;
use SynergyERP\Shared\Outbox\OutboxPublisher;
use SynergyERP\Shared\Outbox\OutboxWorker;
use SynergyERP\Shared\Inbox\InboxService;
use SynergyERP\Shared\Inbox\InboxDispatcher;
use SynergyERP\Shared\Inbox\InboxConsumer;
use SynergyERP\Shared\Inbox\InboxWorker;
use SynergyERP\Shared\Console\Commands\OutboxWorkCommand;
use SynergyERP\Shared\Console\Commands\InboxWorkCommand;
use SynergyERP\Shared\Services\StoragePathBuilder;
use SynergyERP\Shared\Services\TenantStorage;

class SharedServiceProvider extends ServiceProvider
{
    /**
     * Register services into the container.
     */
    public function register(): void
    {
        // Register a dedicated 'service' DB connection that stays pinned to the
        // service-level database. This connection is immune to tenant schema switching
        // and is used by infrastructure models (OutboxItem, InboxItem) that must
        // always write to the service DB, not tenant schemas.
        $this->registerServiceConnection();

        // Outbox - creates OutboxItems and publishes to RabbitMQ
        $this->app->singleton(OutboxService::class);
        $this->app->singleton(OutboxPublisher::class);
        $this->app->singleton(OutboxWorker::class);
        
        // Inbox - consumes from RabbitMQ and dispatches to CQRS handlers
        $this->app->singleton(InboxService::class);
        $this->app->singleton(InboxDispatcher::class);
        $this->app->singleton(InboxConsumer::class);
        $this->app->singleton(InboxWorker::class);

        // Register CacheService as a singleton so HTTP connection pooling
        // is reused across the request lifecycle.
        $this->app->singleton(\SynergyERP\Shared\Cache\CacheService::class, function ($app) {
            return new \SynergyERP\Shared\Cache\CacheService(
                cacheName: env('CACHE_NAME', 'global'),
                timeout: (int) env('CACHE_TIMEOUT', 2)
            );
        });

        $this->registerTenantStorage();
    }

    /**
     * Register the "s3-tenant" filesystem disk and the TenantStorage service.
     *
     * The s3-tenant disk is an alias of the s3 disk. The tenant/model/public_id
     * prefix is applied at the call site via StoragePathBuilder, invoked by
     * TenantStorage. Direct Storage::disk('s3-tenant')->put() with raw paths
     * bypasses tenant isolation — always go through TenantStorage.
     */
    protected function registerTenantStorage(): void
    {
        $this->app->booted(function () {
            if (!config('filesystems.disks.s3-tenant')) {
                // Public endpoint is the browser-reachable URL used for
                // signing presigned URLs (e.g. http://localhost:7500 in dev).
                // The regular `endpoint` is what the PHP runtime uses for
                // its own S3 ops (e.g. http://object-storage:9000 on the
                // Docker network). Falls back to `endpoint` if unset.
                config(['filesystems.disks.s3-tenant' => array_merge(
                    config('filesystems.disks.s3', []),
                    [
                        'public_endpoint' => env('AWS_PUBLIC_ENDPOINT') ?: null,
                        'throw'           => true,
                    ]
                )]);
            }
        });

        $this->app->singleton(StoragePathBuilder::class);
        $this->app->singleton(TenantStorage::class);
    }

    /**
     * Register a dedicated 'service' database connection.
     *
     * This connection mirrors the default 'mysql' connection but remains pinned
     * to the service-level database (e.g., project-service). It is not affected
     * by SchemaAwareTrait::switchSchema() which modifies the default connection.
     *
     * Infrastructure models like OutboxItem and InboxItem use this connection
     * to ensure they always write to the service DB, not tenant schemas.
     */
    protected function registerServiceConnection(): void
    {
        $this->app->booted(function () {
            $mysqlConfig = config('database.connections.mysql');

            if ($mysqlConfig && !config('database.connections.service')) {
                config(['database.connections.service' => $mysqlConfig]);
            }
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {

        if ($this->app->runningInConsole()) {
            $this->commands([
                OutboxWorkCommand::class,
                InboxWorkCommand::class,
            ]);
        }

        // Load shared migrations (inbox_items, outbox_items, transaction_events)
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
