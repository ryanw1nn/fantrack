<?php

namespace SynergyERP\Shared\Tests;

use SynergyERP\Shared\Services\OutboxService;
use SynergyERP\Shared\Services\EventBus;
use Mockery;

final class OutboxWorkerCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: bind a mock EventBus that connects and passes isConnected().
     */
    private function bindConnectableEventBus(): Mockery\MockInterface
    {
        $mock = Mockery::mock(EventBus::class);
        $mock->shouldReceive('connectWithRetry')->once();
        $mock->shouldReceive('isConnected')->andReturn(true);
        $this->app->instance(EventBus::class, $mock);
        return $mock;
    }

    // ── failure paths ────────────────────────────────────────────────────────

    public function test_returns_error_when_rabbitmq_connection_fails(): void
    {
        $mock = Mockery::mock(EventBus::class);
        $mock->shouldReceive('connectWithRetry')
            ->once()
            ->andThrow(new \RuntimeException('[EventBus] Failed to connect after 10 attempts'));
        $this->app->instance(EventBus::class, $mock);

        $this->artisan('outbox:worker')
            ->assertExitCode(1);
    }

    // ── successful startup ───────────────────────────────────────────────────

    public function test_starts_successfully_and_processes_outbox_stack(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        $callCount = 0;
        $outboxMock = Mockery::mock(OutboxService::class);
        $outboxMock->shouldReceive('processOutboxStack')
            ->atLeast()->once()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount >= 2) {
                    posix_kill(getmypid(), SIGTERM);
                }
            });
        $this->app->instance(OutboxService::class, $outboxMock);

        $this->artisan('outbox:worker', ['--sleep' => '0'])
            ->assertExitCode(0);

        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    public function test_continues_processing_after_transient_error(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        $callCount = 0;
        $outboxMock = Mockery::mock(OutboxService::class);
        $outboxMock->shouldReceive('processOutboxStack')
            ->atLeast()->once()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('Transient DB error');
                }
                // Stop after the error recovery iteration
                posix_kill(getmypid(), SIGTERM);
            });
        $this->app->instance(OutboxService::class, $outboxMock);

        // --sleep=0 so we don't wait (backoff is sleep * 2^n = 0)
        $this->artisan('outbox:worker', ['--sleep' => '0'])
            ->assertExitCode(0);

        // Worker continued after the error — at least 2 calls
        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    public function test_uses_custom_sleep_option(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        $outboxMock = Mockery::mock(OutboxService::class);
        $outboxMock->shouldReceive('processOutboxStack')
            ->once()
            ->andReturnUsing(function () {
                posix_kill(getmypid(), SIGTERM);
            });
        $this->app->instance(OutboxService::class, $outboxMock);

        // Verify the command accepts --sleep without error
        $this->artisan('outbox:worker', ['--sleep' => '0'])
            ->assertExitCode(0);
    }
}
