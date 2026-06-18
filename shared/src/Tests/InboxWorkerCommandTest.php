<?php

namespace SynergyERP\Shared\Tests;

use SynergyERP\Shared\Services\InboxService;
use SynergyERP\Shared\Services\EventBus;
use Mockery;

final class InboxWorkerCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: bind a mock EventBus that connects successfully and passes
     * the isConnected() check in the reconnect loop.
     */
    private function bindConnectableEventBus(): Mockery\MockInterface
    {
        $mock = Mockery::mock(EventBus::class);
        $mock->shouldReceive('connectWithRetry')->once();
        $mock->shouldReceive('setQos')->atLeast()->once();
        $mock->shouldReceive('isConnected')->andReturn(true);
        $this->app->instance(EventBus::class, $mock);
        return $mock;
    }

    // -- failure paths ----------------------------------------------------

    public function test_returns_error_when_rabbitmq_connection_fails(): void
    {
        $mock = Mockery::mock(EventBus::class);
        $mock->shouldReceive('connectWithRetry')
            ->once()
            ->andThrow(new \RuntimeException('[EventBus] Failed to connect after 10 attempts'));
        $this->app->instance(EventBus::class, $mock);

        config(['inbox.subscriptions' => [
            ['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#'],
        ]]);

        $this->artisan('inbox:worker')
            ->assertExitCode(1);
    }

    public function test_returns_error_when_subscriptions_config_is_empty(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        config(['inbox.subscriptions' => []]);

        $this->artisan('inbox:worker')
            ->assertExitCode(1);
    }

    public function test_returns_error_when_subscriptions_config_is_missing(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        // Ensure the key is absent
        config(['inbox.subscriptions' => null]);

        $this->artisan('inbox:worker')
            ->assertExitCode(1);
    }

    // -- successful startup ----------------------------------------------------

    public function test_reads_subscriptions_from_config(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        $subscriptions = [
            ['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#'],
            ['exchange' => 'query.exchange',   'routing_key' => 'project-service.query.#'],
            ['exchange' => 'event.exchange',   'routing_key' => 'project-service.event.#'],
        ];

        config(['inbox.subscriptions' => $subscriptions]);

        $capturedSubscriptions = null;
        $inboxMock = Mockery::mock(InboxService::class);
        $inboxMock->shouldReceive('registerSubscriptions')
            ->atLeast()->once()
            ->withArgs(function ($subs) use (&$capturedSubscriptions) {
                $capturedSubscriptions = $subs;
                return true;
            });
        $inboxMock->shouldReceive('processInboxStack')->zeroOrMoreTimes();

        $eventBusMock->shouldReceive('waitForMessages')
            ->atLeast()->once()
            ->andReturnUsing(function () {
                posix_kill(getmypid(), SIGTERM);
            });

        $this->app->instance(InboxService::class, $inboxMock);

        $this->artisan('inbox:worker')->assertExitCode(0);

        $this->assertCount(3, $capturedSubscriptions);
        $this->assertEquals($subscriptions, $capturedSubscriptions);
    }

    public function test_passes_exact_config_pairs_without_cross_product(): void
    {
        $eventBusMock = $this->bindConnectableEventBus();

        // Only 2 explicit pairs — should produce exactly 2 subscriptions, not 2×3=6
        config(['inbox.subscriptions' => [
            ['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#'],
            ['exchange' => 'event.exchange',   'routing_key' => 'project-service.event.#'],
        ]]);

        $capturedSubscriptions = null;
        $inboxMock = Mockery::mock(InboxService::class);
        $inboxMock->shouldReceive('registerSubscriptions')
            ->atLeast()->once()
            ->withArgs(function ($subs) use (&$capturedSubscriptions) {
                $capturedSubscriptions = $subs;
                return true;
            });
        $inboxMock->shouldReceive('processInboxStack')->zeroOrMoreTimes();

        $eventBusMock->shouldReceive('waitForMessages')
            ->atLeast()->once()
            ->andReturnUsing(function () {
                posix_kill(getmypid(), SIGTERM);
            });

        $this->app->instance(InboxService::class, $inboxMock);

        $this->artisan('inbox:worker')->assertExitCode(0);

        $this->assertCount(2, $capturedSubscriptions);
        $this->assertEquals('command.exchange', $capturedSubscriptions[0]['exchange']);
        $this->assertEquals('event.exchange',   $capturedSubscriptions[1]['exchange']);
    }

    public function test_uses_custom_prefetch_option(): void
    {
        $mock = Mockery::mock(EventBus::class);
        $mock->shouldReceive('connectWithRetry')->once();
        $mock->shouldReceive('setQos')
            ->atLeast()->once()
            ->with(25);
        $mock->shouldReceive('isConnected')->andReturn(true);
        $this->app->instance(EventBus::class, $mock);

        config(['inbox.subscriptions' => [
            ['exchange' => 'command.exchange', 'routing_key' => 'project-service.command.#'],
        ]]);

        $inboxMock = Mockery::mock(InboxService::class);
        $inboxMock->shouldReceive('registerSubscriptions')->atLeast()->once();
        $inboxMock->shouldReceive('processInboxStack')->zeroOrMoreTimes();

        $mock->shouldReceive('waitForMessages')
            ->atLeast()->once()
            ->andReturnUsing(function () {
                posix_kill(getmypid(), SIGTERM);
            });

        $this->app->instance(InboxService::class, $inboxMock);

        $this->artisan('inbox:worker', ['--prefetch' => '25'])
            ->assertExitCode(0);
    }
}
