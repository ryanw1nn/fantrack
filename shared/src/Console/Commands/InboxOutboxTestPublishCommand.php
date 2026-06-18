<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SynergyERP\Shared\Services\EventBus;

/**
 * Inbox/outbox system test — floods RabbitMQ with configurable volume and concurrency.
 *
 * Simulates N concurrent "users" (child processes), each publishing M messages
 * to a target exchange/routing-key. Measures throughput, latency, and error rate.
 *
 * Usage:
 *     php artisan inbox-outbox:test-publish --users=100 --messages=50 --exchange=command.exchange --routing-key=project-service.command.project.create
 *     php artisan inbox-outbox:test-publish --users=1000 --messages=10 --ramp-up=30
 *     php artisan inbox-outbox:test-publish --users=5 --messages=100 --mode=outbox
 */
class InboxOutboxTestPublishCommand extends Command
{
    protected $signature = 'inbox-outbox:test-publish
        {--users=10 : Number of concurrent publisher processes (simulated users)}
        {--messages=100 : Messages per user}
        {--exchange=command.exchange : Target exchange}
        {--routing-key=project-service.command.project.create : Routing key for published messages}
        {--ramp-up=0 : Seconds to ramp up all users (0 = all at once)}
        {--payload-size=256 : Approximate payload size in bytes}
        {--mode=rabbitmq : Publishing mode: "rabbitmq" (direct to broker) or "outbox" (via DB outbox table)}
        {--tag= : Optional run tag for grouping results (auto-generated if empty)}
        {--schema=loadtest : Tenant schema prefix for outbox mode}';

    protected $description = 'Inbox/outbox system test — publish messages via RabbitMQ or the outbox table';

    public function handle(): int
    {
        $users       = (int) $this->option('users');
        $messages    = (int) $this->option('messages');
        $exchange    = $this->option('exchange');
        $routingKey  = $this->option('routing-key');
        $rampUp      = (int) $this->option('ramp-up');
        $payloadSize = (int) $this->option('payload-size');
        $mode        = $this->option('mode');
        $tag         = $this->option('tag') ?: 'lt-' . date('Ymd-His');
        $schema      = $this->option('schema');

        $totalMessages = $users * $messages;

        $this->info("╔══════════════════════════════════════════════════╗");
        $this->info("║           INBOX/OUTBOX LOAD TEST                ║");
        $this->info("╠══════════════════════════════════════════════════╣");
        $this->info("║  Mode:          {$mode}");
        $this->info("║  Users:         {$users}");
        $this->info("║  Msgs/user:     {$messages}");
        $this->info("║  Total msgs:    {$totalMessages}");
        $this->info("║  Exchange:      {$exchange}");
        $this->info("║  Routing key:   {$routingKey}");
        $this->info("║  Ramp-up:       {$rampUp}s");
        $this->info("║  Payload size:  ~{$payloadSize} bytes");
        $this->info("║  Run tag:       {$tag}");
        $this->info("╚══════════════════════════════════════════════════╝");
        $this->newLine();

        // Ensure loadtest results table exists
        $this->ensureResultsTable();

        // Record run start
        $runStart = microtime(true);
        DB::table('loadtest_runs')->insert([
            'tag'            => $tag,
            'mode'           => $mode,
            'users'          => $users,
            'messages_per_user' => $messages,
            'total_messages' => $totalMessages,
            'exchange'       => $exchange,
            'routing_key'    => $routingKey,
            'payload_size'   => $payloadSize,
            'started_at'     => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        if ($mode === 'outbox') {
            $this->runOutboxMode($users, $messages, $exchange, $routingKey, $payloadSize, $tag, $schema);
        } else {
            $this->runRabbitMQMode($users, $messages, $exchange, $routingKey, $rampUp, $payloadSize, $tag, $schema);
        }

        $elapsed = round(microtime(true) - $runStart, 2);
        $throughput = $elapsed > 0 ? round($totalMessages / $elapsed, 1) : 0;

        // Update run record
        DB::table('loadtest_runs')->where('tag', $tag)->update([
            'finished_at'        => now(),
            'elapsed_seconds'    => $elapsed,
            'throughput_msg_sec' => $throughput,
            'updated_at'         => now(),
        ]);

        $this->newLine();
        $this->info("══════════════════════════════════════════════════");
        $this->info("  PUBLISH PHASE COMPLETE");
        $this->info("  Elapsed:     {$elapsed}s");
        $this->info("  Throughput:  {$throughput} msg/s");
        $this->info("  Run tag:     {$tag}");
        $this->info("══════════════════════════════════════════════════");
        $this->newLine();
        $this->info("Run the report command to see consumption results:");
        $this->info("  php artisan inbox-outbox:test-report --tag={$tag}");

        return 0;
    }

    /**
     * Direct-to-RabbitMQ mode: fork N child processes, each publishes M messages.
     */
    private function runRabbitMQMode(
        int $users, int $messages, string $exchange, string $routingKey,
        int $rampUp, int $payloadSize, string $tag, string $schema
    ): void {
        $pids = [];
        $rampDelay = $users > 1 && $rampUp > 0
            ? ($rampUp / $users) * 1_000_000 // microseconds between forks
            : 0;

        $this->info("[Publisher] Spawning {$users} publisher processes...");

        for ($u = 0; $u < $users; $u++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error("[Publisher] Failed to fork process {$u}");
                continue;
            }

            if ($pid === 0) {
                // --- CHILD PROCESS ---
                $this->childPublishToRabbitMQ($u, $messages, $exchange, $routingKey, $payloadSize, $tag, $schema);
                exit(0);
            }

            // --- PARENT PROCESS ---
            $pids[] = $pid;

            if ($rampDelay > 0) {
                usleep((int) $rampDelay);
            }
        }

        // Wait for all children
        $this->info("[Publisher] Waiting for {$users} publishers to finish...");
        $successCount = 0;
        $failCount    = 0;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        $this->info("[Publisher] All processes finished. Success: {$successCount}, Failed: {$failCount}");
    }

    /**
     * Child process: connect to RabbitMQ and publish messages.
     */
    private function childPublishToRabbitMQ(
        int $userId, int $messages, string $exchange, string $routingKey,
        int $payloadSize, string $tag, string $schema
    ): void {
        try {
            $eventBus = app(EventBus::class);
            $eventBus->connect();
            $eventBus->enablePublisherConfirms();

            for ($m = 0; $m < $messages; $m++) {
                $payload = $this->buildPayload($userId, $m, $payloadSize, $tag, $schema);

                $eventBus->publishWithConfirm($exchange, $routingKey, $payload);
            }

            $eventBus->disconnect();
        } catch (\Exception $e) {
            Log::error("[InboxOutboxTest] Publisher child {$userId} failed: " . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Outbox mode: insert OutboxItem records directly into the DB.
     * The outbox worker will publish them — this tests the full pipeline.
     * Uses batch inserts for speed; no forking needed since DB handles concurrency.
     */
    private function runOutboxMode(
        int $users, int $messages, string $exchange, string $routingKey,
        int $payloadSize, string $tag, string $schema
    ): void {
        $total = $users * $messages;
        $this->info("[Outbox] Inserting {$total} OutboxItem records in batches...");

        $batchSize = 500;
        $inserted  = 0;
        $batch     = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        for ($u = 0; $u < $users; $u++) {
            for ($m = 0; $m < $messages; $m++) {
                $payload = $this->buildPayload($u, $m, $payloadSize, $tag, $schema);

                $batch[] = [
                    'transaction_key'      => null,
                    'operation_key'        => $routingKey,
                    'idempotency_key'      => $payload['idempotency_key'] ?? null,
                    'bus_exchange'         => $exchange,
                    'bus_route'            => $routingKey,
                    'payload'              => json_encode($payload),
                    'status'               => 'publishing',
                    'retry_count'          => 0,
                    'created_at'           => now(),
                ];

                if (count($batch) >= $batchSize) {
                    DB::table("{$schema}.outbox_items")->insert($batch);
                    $inserted += count($batch);
                    $bar->advance(count($batch));
                    $batch = [];
                }
            }
        }

        // Flush remainder
        if (!empty($batch)) {
            DB::table("{$schema}.outbox_items")->insert($batch);
            $inserted += count($batch);
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine();
        $this->info("[Outbox] Inserted {$inserted} OutboxItem records. Outbox worker will publish them.");
    }

    /**
     * Build a realistic payload with configurable size.
     */
    private function buildPayload(int $userId, int $messageIndex, int $payloadSize, string $tag, string $schema): array
    {
        $base = [
            'idempotency_key'     => "{$tag}-u{$userId}-m{$messageIndex}",
            'transaction_key'     => 'project-service.command.project.create',
            'operation' => [
                'service' => 'project-service',
                'cqrs'    => 'command',
                'model'   => 'project',
                'action'  => 'create',
                'id'      => null,
            ],
            'transaction_request' => [
                'label'             => "LT-{$userId}-{$messageIndex}",
                'description'       => "Load test project created by user {$userId}, message {$messageIndex}",
                'project_status_id' => 1,
                'percent_complete'  => 0,
                'start_date'        => now()->toDateString(),
                'end_date'          => now()->addMonths(6)->toDateString(),
            ],
            'snapshot'   => null,
            'projection' => null,
            'schema'     => $schema,
            'requested_by' => $userId,
            'requested_at' => now()->toIso8601String(),
            'loadtest_tag' => $tag,
            'loadtest_user_id'    => $userId,
            'loadtest_message_id' => $messageIndex,
            'loadtest_published_at' => microtime(true),
        ];

        // Pad payload to approximate target size
        $currentSize = strlen(json_encode($base));
        if ($currentSize < $payloadSize) {
            $base['_padding'] = str_repeat('x', $payloadSize - $currentSize);
        }

        return $base;
    }

    /**
     * Ensure the loadtest results tables exist.
     */
    private function ensureResultsTable(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('loadtest_runs')) {
            DB::getSchemaBuilder()->create('loadtest_runs', function ($table) {
                $table->id();
                $table->string('tag')->unique();
                $table->string('mode');
                $table->integer('users');
                $table->integer('messages_per_user');
                $table->integer('total_messages');
                $table->string('exchange');
                $table->string('routing_key');
                $table->integer('payload_size');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->decimal('elapsed_seconds', 10, 2)->nullable();
                $table->decimal('throughput_msg_sec', 10, 1)->nullable();
                $table->timestamps();
            });
        }
    }
}
