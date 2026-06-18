<?php

namespace SynergyERP\Shared\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SynergyERP\Shared\Models\OutboxItem;
use SynergyERP\Shared\Services\OutboxService;
use SynergyERP\Shared\Services\EventBus;
use Mockery;

final class OutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private OutboxService $service;
    private $eventBusMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventBusMock = Mockery::mock(EventBus::class);
        $this->service = new OutboxService($this->eventBusMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: insert an OutboxItem directly into the DB.
     */
    private function makeOutboxItem(
        string $status = 'publishing',
        int $retryCount = 0,
        ?string $nextRetryAt = null
    ): OutboxItem {
        $item = new OutboxItem([
            'bus_exchange'    => 'command.exchange',
            'bus_route'       => 'project-service.command.project.create',
            'operation_key'   => 'project-service.command.project.create',
            'payload'         => ['idempotency_key' => uniqid(), 'data' => 'test'],
            'status'          => $status,
            'retry_count'     => $retryCount,
        ]);
        if ($nextRetryAt) {
            $item->next_retry_at = $nextRetryAt;
        }
        $item->save();
        return $item;
    }

    // ── processOutboxStack — publisher confirms ──────────────────────────────

    public function test_processOutboxStack_enables_publisher_confirms_once(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->times(2);
        $this->eventBusMock->shouldReceive('publish')->twice()->andReturn(['confirmed' => true]);

        $this->makeOutboxItem();
        $this->makeOutboxItem();

        // First call enables confirms + publishes 2 items
        $this->service->processOutboxStack();

        // Second call does NOT re-enable confirms (already enabled)
        $this->service->processOutboxStack();
    }

    // ── processOutboxItem — success path ─────────────────────────────────────

    public function test_processOutboxItem_marks_published_on_success(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->once();
        $this->eventBusMock->shouldReceive('publish')
            ->once()
            ->with('command.exchange', 'project-service.command.project.create', Mockery::type('array'))
            ->andReturn(['confirmed' => true]);

        $item = $this->makeOutboxItem();
        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_PUBLISHED, $item->status);
    }

    public function test_processOutboxItem_transitions_through_publishing_state(): void
    {
        // Capture the status during publish to verify PUBLISHING intermediate state
        $statusDuringPublish = null;

        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->once();
        $this->eventBusMock->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function () use (&$statusDuringPublish) {
                // Read the item's status mid-publish
                $statusDuringPublish = OutboxItem::first()->status;
                return ['confirmed' => true];
            });

        $item = $this->makeOutboxItem();
        $this->service->processOutboxStack();

        $this->assertEquals(OutboxItem::STATUS_PUBLISHING, $statusDuringPublish);

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_PUBLISHED, $item->status);
    }

    // ── processOutboxItem — failure path ─────────────────────────────────────

    public function test_processOutboxItem_marks_failed_and_increments_retry_on_exception(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->once();
        $this->eventBusMock->shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('Broker unreachable'));

        $item = $this->makeOutboxItem();
        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_FAILED, $item->status);
        $this->assertEquals(1, $item->retry_count);
        $this->assertEquals('Broker unreachable', $item->error_message);
        $this->assertNotNull($item->next_retry_at);
    }

    public function test_processOutboxItem_applies_exponential_backoff(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->once();
        $this->eventBusMock->shouldReceive('publish')
            ->andThrow(new \RuntimeException('Fail'));

        // Item already failed twice — retry_count=2, eligible for retry
        $item = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 2, now()->subMinute());

        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(3, $item->retry_count);
        // next_retry_at should be ~8 minutes from now (2^3 = 8)
        $this->assertGreaterThan(now()->addMinutes(7), $item->next_retry_at);
        $this->assertLessThan(now()->addMinutes(9), $item->next_retry_at);
    }

    public function test_processOutboxItem_failure_does_not_halt_batch(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->times(3);

        $callCount = 0;
        $this->eventBusMock->shouldReceive('publish')
            ->times(3)
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new \RuntimeException('Second item fails');
                }
                return ['confirmed' => true];
            });

        $item1 = $this->makeOutboxItem();
        $item2 = $this->makeOutboxItem();
        $item3 = $this->makeOutboxItem();

        $this->service->processOutboxStack();

        $item1->refresh();
        $item2->refresh();
        $item3->refresh();

        $this->assertEquals(OutboxItem::STATUS_PUBLISHED, $item1->status);
        $this->assertEquals(OutboxItem::STATUS_FAILED, $item2->status);
        $this->assertEquals(OutboxItem::STATUS_PUBLISHED, $item3->status);
    }

    // ── processOutboxStack — retry eligibility ───────────────────────────────

    public function test_processOutboxStack_picks_up_retry_eligible_failed_items(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->once();
        $this->eventBusMock->shouldReceive('publish')->once()->andReturn(['confirmed' => true]);

        // Failed, retry_count < 5, next_retry_at in the past → eligible
        $item = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 2, now()->subMinute());

        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_PUBLISHED, $item->status);
    }

    public function test_processOutboxStack_skips_exhausted_retries(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldNotReceive('publish');

        // retry_count = 5 → exhausted, should NOT be picked up
        $item = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 5, now()->subMinute());

        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_FAILED, $item->status);
    }

    public function test_processOutboxStack_skips_items_not_yet_eligible_for_retry(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldNotReceive('publish');

        // next_retry_at is in the future → not yet eligible
        $item = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 1, now()->addHour());

        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_FAILED, $item->status);
    }

    public function test_processOutboxStack_skips_already_published_items(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldNotReceive('publish');

        $item = $this->makeOutboxItem(OutboxItem::STATUS_PUBLISHED);

        $this->service->processOutboxStack();

        $item->refresh();
        $this->assertEquals(OutboxItem::STATUS_PUBLISHED, $item->status);
    }

    public function test_processOutboxStack_respects_batch_limit_of_50(): void
    {
        $this->eventBusMock->shouldReceive('enablePublisherConfirms')->once();
        $this->eventBusMock->shouldReceive('setup_queue')->times(50);
        $this->eventBusMock->shouldReceive('publish')->times(50)->andReturn(['confirmed' => true]);

        for ($i = 0; $i < 60; $i++) {
            $this->makeOutboxItem();
        }

        $this->service->processOutboxStack();

        $this->assertEquals(50, OutboxItem::where('status', OutboxItem::STATUS_PUBLISHED)->count());
        $this->assertEquals(10, OutboxItem::where('status', OutboxItem::STATUS_PUBLISHING)->count());
    }

    // ── scopePendingOrRetryable ──────────────────────────────────────────────

    public function test_scope_pendingOrRetryable_returns_correct_items(): void
    {
        $publishing = $this->makeOutboxItem(OutboxItem::STATUS_PUBLISHING);
        $retryable  = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 2, now()->subMinute());
        $exhausted  = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 5, now()->subMinute());
        $notYet     = $this->makeOutboxItem(OutboxItem::STATUS_FAILED, 1, now()->addHour());
        $published  = $this->makeOutboxItem(OutboxItem::STATUS_PUBLISHED);

        $results = OutboxItem::pendingOrRetryable()->get();

        $this->assertTrue($results->contains('id', $publishing->id));
        $this->assertTrue($results->contains('id', $retryable->id));
        $this->assertFalse($results->contains('id', $exhausted->id));
        $this->assertFalse($results->contains('id', $notYet->id));
        $this->assertFalse($results->contains('id', $published->id));
    }
}
