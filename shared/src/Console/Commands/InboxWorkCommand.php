<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Inbox\InboxConsumer;
use SynergyERP\Shared\Inbox\InboxWorker;
use SynergyERP\Shared\Services\EventBus;
use Throwable;

/**
 * Long-running inbox worker command.
 * 
 * Subscribes to RabbitMQ queues defined in config/inbox.php and
 * dispatches incoming messages to CQRS handlers. Also retries
 * failed InboxItems on each loop iteraiton.
 *
 *
 * Usage:
 *     php artisan synergy:inbox:work
 *     php artisan synergy:inbox:work --prefetch=20 --sleep=5
 *     php artisan synergy:inbox-work --once
 */
class InboxWorkCommand extends Command
{
    protected $signature = 'synergy:inbox-work
                            {--sleep=2 : Seconds to sleep when no messages arrive}
                            {--prefetch=10 : QoS prefetch count}
                            {--once : Process a single inbox item and exit}';

    protected $description = 'Run the Inbox worker: consume from the EventBus and dispatch to CQRS handlers';

    private bool $shouldStop = false;

    public function handle(InboxConsumer $consumer, InboxWorker $worker, EventBus $eventBus): int
    {
        // 1. Wait for database to be available
        if (!$this->waitForDatabase()) {
            return self::FAILURE;
        }

        DB::disableQueryLog();

        // 2. Connect to RabbitMQ with retry
        try {
            $eventBus->connectWithRetry(10, 5);
        } catch (\Exception $e) {
            $this->error('[InboxWorkCommand] Failed to connec to EventBus: ' . $e->getMessage());
            return self::FAILURE;
        }
        
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $prefetch = (int) $this->option('prefetch');
        $runOnce = (bool) $this->option('once');

        Log::info('[InboxWorkCommand] Starting', [
            'sleep_seconds' => $sleepSeconds,
            'prefetch'      => $prefetch,
            'once'          => $runOnce,
        ]);

        // 3. --once mode: process one dispatchable item and exit
        //  Useful for debugging and one-off retry processing
        if ($runOnce) {
            $processed = $worker->processNext();
            $this->info($processed ? 'Processed 1 inbox item' : 'No dispatchable inbox items found');
            return self::SUCCESS;
        }

        // 4. Load subscriptions from config/inbox.php
        $subscriptions = config('inbox.subscriptions', []);

        if (empty($subscriptions)) {
            $this->error('[InboxWorkCommand] No subscriptions defined in config/inbox.php');
            return self::FAILURE;
        }

        $this->info('[InboxWorkCommand] Starting with ' . count($subscriptions) . ' subscription(s)');
        foreach ($subscriptions as $sub) {
            $this->info("   -> {$sub['exchange']} : {$sub['routing_key']}");
        }

        // 5. Register signal handlers
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            Log::info('[InboxWorkCommand] SIGTERM received, shutting down...');
            $this->shouldStop = true;
        });
        pcntl_signal(SIGINT, function () {
            Log::info('[InboxWorkCommand] SIGINT received, shutting down...');
            $this->shouldStop = true;
        });

        // 6. Main loop — listen for new messages and retry failed ones
        $registered = false;

        while (!$this->shouldStop) {
            try {
                if (!$eventBus->isConnected()) {
                    Log::warning('[InboxWorkCommand] Connection lost, reconnecting...');
                    $eventBus->connectWithRetry(5, 3);
                    $eventBus->setQos((int) $this->option('prefetch'));
                    $registered = false;
                }

                if (!$registered) {
                    $eventBus->setQos($prefetch);
                    $consumer->registerSubscriptions($subscriptions, $eventBus);
                    $registered = true;
                }

                // Block until a message arrives or the 1s timeout elapses
                $eventBus->waitForMessages(1.0);

                // After each wait cycle, process any retry-eligible failed items
                $worker->processNext();

                // Memory ceiling — let Docker restart a fresh process
                if (memory_get_usage(true) > 128 * 1024 * 1024) {
                    Log::info('[InboxWorkCommand] Memory ceiling reached (128 MB), exiting for restart');
                    break;
                }

            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Normal — no messages within timeout, process retries and loop
                $worker->processNext();

            } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException
                  | \PhpAmqpLib\Exception\AMQPHeartbeatMissedException
                  | \PhpAmqpLib\Exception\AMQPIOException
                  | \PhpAmqpLib\Exception\AMQPRuntimeException $e) {
                    // AMQP connection/channel error - tear down and reconnect on next iteration
                Log::error('[InboxWorkCommand] Connection error: ' . $e->getMessage());
                try { $eventBus->disconnect(); } catch (Throwable) {}
                $registered = false;
                sleep($sleepSeconds);

            } catch (\Exception $e) {
                // Unexpected error - log and back off before retrying
                Log::error('[InboxWorkCommand] Unexpected error: ', [
                    'error_type'    => get_class($e),
                    'error_message' => $e->getMessage(),
                    'error_file'    => $e->getFile(),
                    'error_line'    => $e->getLine(),  
                ]);
                sleep($sleepSeconds);
            }
        }

        $this->info('[InboxWorkCommand] Inbox worker stopped gracefully');
        return self::SUCCESS;
    }

    /**
     * Wait for the database to become available before starting.
     * Retries up to $maxAttempts times with $sleepSeconds between each attempt.
     */
    private function waitForDatabase(int $maxAttempts = 5, int $sleepSeconds = 5): bool
    {
        for ($i = 1; $i <= $maxAttempts; $i++) {
            try {
                DB::connection()->getPdo();
                $this->info('Inbox worker database connected');
                return true;
            } catch (\Exception $e) {
                $this->warn("Inbox worker DB attempt {$i}/{$maxAttempts}: {$e->getMessage()}");
                if ($i < $maxAttempts) sleep($sleepSeconds);
            }
        }

        $this->error('Inbox worker could not connect to database after ' . $maxAttempts . ' attempts');
        return false;
    }
}
