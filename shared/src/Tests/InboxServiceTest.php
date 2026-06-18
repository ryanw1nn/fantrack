<?php

namespace SynergyERP\Shared\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SynergyERP\Shared\Models\InboxItem;
use SynergyERP\Shared\Services\InboxService;
use SynergyERP\Shared\Services\EventBus;
use Mockery;

/**
 * No-op dispatch subclass for infrastructure tests that verify batching,
 * ordering, timestamps, etc. without requiring real CQRS handlers.
 */
class NoOpDispatchInboxService extends InboxService
{
    protected function dispatch(array $payload, string $exchange, string $routingKey): void
    {
        // No-op — allows processInboxStack() to succeed without CQRS resolution
    }
}

final class InboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private InboxService $service;
    private NoOpDispatchInboxService $noOpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InboxService();
        $this->noOpService = new NoOpDispatchInboxService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -- createInboxItem ----------------------------------------------------

    public function test_createInboxItem_creates_record_with_publishing_status(): void
    {
        $item = $this->service->createInboxItem(
            ['key' => 'value'],
            'command.exchange',
            'project-service.command.project.create'
        );

        $this->assertInstanceOf(InboxItem::class, $item);
        $this->assertTrue($item->exists, 'InboxItem should be persisted to DB');
        $this->assertEquals(InboxItem::STATUS_PUBLISHING, $item->status);
        $this->assertEquals('command.exchange', $item->exchange);
        $this->assertEquals('project-service.command.project.create', $item->route);
        $this->assertEquals(['key' => 'value'], $item->payload);
        $this->assertDatabaseHas('inbox_items', ['id' => $item->id, 'status' => 'publishing']);
    }

    public function test_createInboxItem_without_idempotency_key_sets_null(): void
    {
        $item = $this->service->createInboxItem(
            ['data' => 'no idempotency key'],
            'event.exchange',
            'project-service.event.project.updated'
        );

        $this->assertNull($item->idempotency_key);
    }

    public function test_createInboxItem_preserves_full_payload_as_json(): void
    {
        $payload = [
            'idempotency_key'     => 'abc-123',
            'transaction_key'     => 'project-service.command.project.create',
            'operation'           => ['service' => 'project-service', 'cqrs' => 'command', 'model' => 'project', 'action' => 'create'],
            'snapshot'            => ['before' => null],
            'projection'          => ['after' => ['id' => 1, 'name' => 'Test Project']],
            'principal_puid'      => '01JDZ0000000000000000PRINC',
            'requested_at'        => '2026-03-03T12:00:00Z',
        ];

        $item = $this->service->createInboxItem($payload, 'command.exchange', 'key');

        $item->refresh();
        $this->assertEquals($payload, $item->payload);
        $this->assertIsArray($item->payload); // cast works
    }

    // -- processInboxStack ----------------------------------------------------

    public function test_processInboxStack_marks_publishing_items_as_published(): void
    {
        for ($i = 0; $i < 3; $i++) {
            (new InboxItem([
                'exchange'       => 'command.exchange',
                'route'          => 'project-service.command.project.create',
                'operation_key'  => 'project-service.command.project.create',
                'payload'        => ['index' => $i],
                'status'         => InboxItem::STATUS_PUBLISHING,
                'retry_count'    => 0,
            ]))->save();
        }

        $this->noOpService->processInboxStack();

        $this->assertDatabaseCount('inbox_items', 3);
        $this->assertEquals(3, InboxItem::where('status', InboxItem::STATUS_PUBLISHED)->count());
    }

    public function test_processInboxStack_respects_batch_limit_of_50(): void
    {
        // Insert 60 pending items - only 50 should process per call
        for ($i = 0; $i < 60; $i++) {
            (new InboxItem([
                'exchange'       => 'command.exchange',
                'route'          => 'project-service.command.project.create',
                'operation_key'  => 'project-service.command.project.create',
                'payload'        => ['i' => $i],
                'status'         => InboxItem::STATUS_PUBLISHING,
                'retry_count'    => 0,
            ]))->save();
        }

        $this->noOpService->processInboxStack();

        $this->assertEquals(50, InboxItem::where('status', InboxItem::STATUS_PUBLISHED)->count());
        $this->assertEquals(10, InboxItem::where('status', InboxItem::STATUS_PUBLISHING)->count());
    }

    public function test_processInboxItem_sets_published_at_timestamp(): void
    {
        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'project-service.command.project.create',
            'operation_key'  => 'project-service.command.project.create',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_PUBLISHING,
            'retry_count'    => 0,
        ]);
        $item->save();

        $this->assertNull($item->published_at);

        $this->noOpService->processInboxStack();

        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_PUBLISHED, $item->status);
        $this->assertNotNull($item->published_at);
    }

    public function test_processInboxItem_marks_failed_on_dispatch_exception(): void
    {
        // Anonymous subclass that throws on dispatch
        $service = new class extends InboxService {
            protected function dispatch(array $payload, string $exchange, string $routingKey): void
            {
                throw new \RuntimeException('Handler not found');
            }
        };

        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'project-service.command.project.create',
            'operation_key'  => 'project-service.command.project.create',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_PUBLISHING,
            'retry_count'    => 0,
        ]);
        $item->save();

        $service->processInboxStack();

        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_FAILED, $item->status);
        $this->assertEquals('Handler not found', $item->error_message);
        $this->assertNull($item->published_at);
    }

    public function test_processInboxStack_failure_does_not_halt_batch(): void
    {
        // Subclass that fails on the first item only
        $service = new class extends InboxService {
            private int $callCount = 0;
            protected function dispatch(array $payload, string $exchange, string $routingKey): void
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    throw new \RuntimeException('First item fails');
                }
            }
        };

        for ($i = 0; $i < 3; $i++) {
            (new InboxItem([
                'exchange'       => 'command.exchange',
                'route'          => 'key',
                'operation_key'  => 'key',
                'payload'        => ['index' => $i],
                'status'         => InboxItem::STATUS_PUBLISHING,
                'retry_count'    => 0,
            ]))->save();
        }

        $service->processInboxStack();

        $this->assertEquals(1, InboxItem::where('status', InboxItem::STATUS_FAILED)->count());
        $this->assertEquals(2, InboxItem::where('status', InboxItem::STATUS_PUBLISHED)->count());
    }

    public function test_processInboxStack_does_not_reprocess_already_published_items(): void
    {
        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'project-service.command.project.create',
            'operation_key'  => 'project-service.command.project.create',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_PUBLISHED,
            'published_at'   => now(),
            'retry_count'    => 0,
        ]);
        $item->save();

        $this->noOpService->processInboxStack();

        $this->assertDatabaseCount('inbox_items', 1);
        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_PUBLISHED, $item->status);
    }

    public function test_processInboxStack_does_not_reprocess_exhausted_failed_items(): void
    {
        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'project-service.command.project.create',
            'operation_key'  => 'project-service.command.project.create',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_FAILED,
            'error_message'  => 'Previous failure',
            'retry_count'    => InboxItem::MAX_RETRIES, // exhausted
        ]);
        $item->save();

        $this->noOpService->processInboxStack();

        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_FAILED, $item->status);
        $this->assertEquals('Previous failure', $item->error_message);
    }

    public function test_processInboxStack_retries_failed_items_when_eligible(): void
    {
        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'project-service.command.project.create',
            'operation_key'  => 'project-service.command.project.create',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_FAILED,
            'error_message'  => 'Transient error',
            'retry_count'    => 1,
            'next_retry_at'  => now()->subMinute(), // eligible
        ]);
        $item->save();

        $this->noOpService->processInboxStack();

        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_PUBLISHED, $item->status);
        $this->assertNotNull($item->published_at);
    }

    public function test_processInboxStack_skips_failed_items_not_yet_eligible(): void
    {
        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'project-service.command.project.create',
            'operation_key'  => 'project-service.command.project.create',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_FAILED,
            'error_message'  => 'Transient error',
            'retry_count'    => 1,
            'next_retry_at'  => now()->addHour(), // not yet eligible
        ]);
        $item->save();

        $this->noOpService->processInboxStack();

        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_FAILED, $item->status);
    }

    public function test_processInboxStack_processes_in_created_at_order(): void
    {
        // Insert in reverse order with explicit created_at
        for ($i = 3; $i >= 1; $i--) {
            $item = new InboxItem([
                'exchange'       => 'command.exchange',
                'route'          => 'project-service.command.project.create',
                'operation_key'  => 'project-service.command.project.create',
                'payload'        => ['order' => $i],
                'status'         => InboxItem::STATUS_PUBLISHING,
                'retry_count'    => 0,
            ]);
            $item->created_at = now()->subSeconds($i);
            $item->save();
        }

        $this->noOpService->processInboxStack();

        // Items processed oldest first — verify via published_at ordering
        $processed = InboxItem::where('status', InboxItem::STATUS_PUBLISHED)
            ->orderBy('published_at')
            ->pluck('payload')
            ->map(fn($p) => $p['order'])
            ->toArray();

        // Oldest (created_at furthest in past) should be first: 3, 2, 1
        $this->assertEquals([3, 2, 1], $processed);
    }

    // -- dispatch validates routing key format --------------------------------

    public function test_dispatch_fails_on_invalid_routing_key_format(): void
    {
        $item = new InboxItem([
            'exchange'       => 'command.exchange',
            'route'          => 'invalid-key',
            'operation_key'  => 'invalid-key',
            'payload'        => ['data' => 'test'],
            'status'         => InboxItem::STATUS_PUBLISHING,
            'retry_count'    => 0,
        ]);
        $item->save();

        $this->service->processInboxStack();

        $item->refresh();
        $this->assertEquals(InboxItem::STATUS_FAILED, $item->status);
        $this->assertStringContains('not enough parts', $item->error_message);
    }

    // -- listenForEvents / registerSubscriptions ------------------------------

    public function test_registerSubscriptions_registers_consumers_for_all_subscriptions(): void
    {
        $eventBus = Mockery::mock(EventBus::class);

        $subscriptions = [
            ['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#'],
            ['exchange' => 'query.exchange',   'routing_key' => 'project-service.query.#'],
            ['exchange' => 'event.exchange',   'routing_key' => 'project-service.event.#'],
        ];

        $eventBus->shouldReceive('setup_queue')->times(3);
        $eventBus->shouldReceive('registerConsumer')->times(3);

        $this->service->registerSubscriptions($subscriptions, $eventBus);
    }

    public function test_listenForEvents_registers_then_waits(): void
    {
        $eventBus = Mockery::mock(EventBus::class);

        $subscriptions = [
            ['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#'],
        ];

        $eventBus->shouldReceive('setup_queue')->once();
        $eventBus->shouldReceive('registerConsumer')->once();
        $eventBus->shouldReceive('waitForMessages')->once();

        $this->service->listenForEvents($subscriptions, $eventBus);
    }

    public function test_registerSubscriptions_creates_correct_queue_names(): void
    {
        $eventBus = Mockery::mock(EventBus::class);

        $eventBus->shouldReceive('setup_queue')
            ->once()
            ->with(
                'command.exchange.project-service_command__',   // sanitized queue name
                'command.exchange',
                'project-service.command.#'
            );
        $eventBus->shouldReceive('registerConsumer')->once();

        $this->service->registerSubscriptions(
            [['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#']],
            $eventBus
        );
    }

    public function test_callback_creates_inbox_item_on_valid_message(): void
    {
        $eventBus = Mockery::mock(EventBus::class);
        $capturedCallback = null;

        $eventBus->shouldReceive('setup_queue')->once();
        $eventBus->shouldReceive('registerConsumer')
            ->once()
            ->withArgs(function ($queue, $callback) use (&$capturedCallback) {
                $capturedCallback = $callback;
                return true;
            });

        // Use noOpService so dispatch succeeds without real CQRS handlers
        $this->noOpService->registerSubscriptions(
            [['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#']],
            $eventBus
        );

        // Simulate a valid AMQP message arriving
        $channel = Mockery::mock();
        $channel->shouldReceive('basic_ack')->once();

        $msg = new \stdClass();
        $msg->body = json_encode(['key' => 'value', 'idempotency_key' => 'test-123']);
        $msg->delivery_info = [
            'channel'      => $channel,
            'delivery_tag' => 1,
            'routing_key'  => 'project-service.command.project.create',
        ];

        $capturedCallback($msg);

        $this->assertDatabaseCount('inbox_items', 1);
        $this->assertDatabaseHas('inbox_items', [
            'exchange'    => 'command.exchange',
            'route'       => 'project-service.command.project.create',
        ]);
    }

    public function test_callback_acks_and_skips_invalid_json(): void
    {
        $eventBus = Mockery::mock(EventBus::class);
        $capturedCallback = null;

        $eventBus->shouldReceive('setup_queue')->once();
        $eventBus->shouldReceive('registerConsumer')
            ->once()
            ->withArgs(function ($queue, $callback) use (&$capturedCallback) {
                $capturedCallback = $callback;
                return true;
            });

        $this->service->registerSubscriptions(
            [['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#']],
            $eventBus
        );

        // Simulate an invalid message (not JSON)
        $channel = Mockery::mock();
        $channel->shouldReceive('basic_ack')->once();

        $msg = new \stdClass();
        $msg->body = 'NOT VALID JSON {{{{';
        $msg->delivery_info = [
            'channel'      => $channel,
            'delivery_tag' => 2,
            'routing_key'  => 'some.key',
        ];

        $capturedCallback($msg);

        // No InboxItem created — invalid payload was skipped
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_callback_nacks_on_db_failure(): void
    {
        $eventBus = Mockery::mock(EventBus::class);
        $capturedCallback = null;

        $eventBus->shouldReceive('setup_queue')->once();
        $eventBus->shouldReceive('registerConsumer')
            ->once()
            ->withArgs(function ($queue, $callback) use (&$capturedCallback) {
                $capturedCallback = $callback;
                return true;
            });

        // Use a subclass that simulates a DB write failure
        $failingService = new class extends InboxService {
            public function createInboxItem(array $payload, string $exchange, string $routingKey): InboxItem
            {
                throw new \RuntimeException('DB connection lost');
            }
        };

        $failingService->registerSubscriptions(
            [['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#']],
            $eventBus
        );

        // Simulate a valid message that fails on DB write
        $channel = Mockery::mock();
        $channel->shouldReceive('basic_nack')
            ->once()
            ->with(3, false, true); // delivery_tag, multiple=false, requeue=true

        $msg = new \stdClass();
        $msg->body = json_encode(['key' => 'value']);
        $msg->delivery_info = [
            'channel'      => $channel,
            'delivery_tag' => 3,
            'routing_key'  => 'project-service.command.project.create',
        ];

        $capturedCallback($msg);

        // Message was NACKed and requeued, no DB record created
        $this->assertDatabaseCount('inbox_items', 0);
    }

    // -- scopePending ----------------------------------------------------

    public function test_scope_pending_only_returns_publishing_items(): void
    {
        (new InboxItem(['exchange' => 'e', 'route' => 'k', 'operation_key' => 'k', 'payload' => [], 'status' => InboxItem::STATUS_PUBLISHING, 'retry_count' => 0]))->save();
        (new InboxItem(['exchange' => 'e', 'route' => 'k', 'operation_key' => 'k', 'payload' => [], 'status' => InboxItem::STATUS_PUBLISHED, 'retry_count' => 0]))->save();
        (new InboxItem(['exchange' => 'e', 'route' => 'k', 'operation_key' => 'k', 'payload' => [], 'status' => InboxItem::STATUS_FAILED, 'retry_count' => 0]))->save();

        $pending = InboxItem::pending()->get();

        $this->assertCount(1, $pending);
        $this->assertEquals(InboxItem::STATUS_PUBLISHING, $pending->first()->status);
    }

    // -- buildQueueName ----------------------------------------------------

    public function test_buildQueueName_sanitizes_wildcards_and_dots(): void
    {
        $method = new \ReflectionMethod(InboxService::class, 'buildQueueName');
        $method->setAccessible(true);

        // '.' → '_', '#' → '_', '*' → '_'
        $this->assertEquals(
            'command.exchange.project-service_command__',
            $method->invoke($this->service, 'command.exchange', 'project-service.command.#')
        );

        $this->assertEquals(
            'query.exchange.project-service_query____',
            $method->invoke($this->service, 'query.exchange', 'project-service.query.*.*')
        );

        $this->assertEquals(
            'event.exchange.project-service____',
            $method->invoke($this->service, 'event.exchange', 'project-service.#.#')
        );
    }

    /**
     * Helper for partial string matching (PHPUnit 10 dropped assertStringContainsString alias).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
