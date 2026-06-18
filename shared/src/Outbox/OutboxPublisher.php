<?php

namespace SynergyERP\Shared\Outbox;

use Illuminate\Support\Facades\Log;
use Throwable;
use SynergyERP\Shared\Models\OutboxItem;
use SynergyERP\Shared\Services\EventBus;

final class OutboxPublisher
{
    private bool $publisherConfirmsEnabled = false;

    public function __construct(
        private readonly EventBus $eventBus
    ) {
    }

    /**
     * Publish a single OutboxItem to the EventBus and await confirmation.
     * 
     * Uses connect-per-publish pattern to avoid stale connection issues.
     * Each publish gets a fresh connection, avoiding heartbeat timeouts.
     */
    public function publish(OutboxItem $outboxItem): bool
    {
        try {
            // Ensure connection is alive, reconnect if needed
            $this->ensureConnected();
            
            $this->ensurePublisherConfirmsEnabled();

            $this->eventBus->setup_queue(
                $outboxItem->bus_route,
                $outboxItem->bus_exchange
            );

            $result = $this->eventBus->publish(
                $outboxItem->bus_exchange,
                $outboxItem->bus_route,
                $outboxItem->payload
            );

            if (! $this->wasConfirmed($result)) {
                $outboxItem->markAsFailed('No confirmation received from EventBus');

                Log::warning('Outbox publish not confirmed', [
                    'outbox_item_id' => $outboxItem->id,
                    'transaction_key' => $outboxItem->transaction_key,
                    'bus_exchange' => $outboxItem->bus_exchange,
                    'bus_route' => $outboxItem->bus_route,
                    'retry_count' => $outboxItem->retry_count,
                ]);

                return false;
            }

            $outboxItem->markAsPublished();

            Log::info('Outbox item published successfully', [
                'outbox_item_id' => $outboxItem->id,
                'transaction_key' => $outboxItem->transaction_key,
                'bus_exchange' => $outboxItem->bus_exchange,
                'bus_route' => $outboxItem->bus_route,
            ]);

            return true;
        } catch (Throwable $e) {
            $outboxItem->markAsFailed($e->getMessage());

            Log::error('Outbox publish failed', [
                'outbox_item_id' => $outboxItem->id,
                'transaction_key' => $outboxItem->transaction_key,
                'bus_exchange' => $outboxItem->bus_exchange,
                'bus_route' => $outboxItem->bus_route,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function ensureConnected(): void
    {
        if (!$this->eventBus->isConnected()) {
            Log::warning('EventBus connection lost, reconnecting...');
            $this->eventBus->reconnect();
            // Reset publisher confirms flag since we have a new connection
            $this->publisherConfirmsEnabled = false;
        }
    }

    private function ensurePublisherConfirmsEnabled(): void
    {
        if ($this->publisherConfirmsEnabled) {
            return;
        }

        $this->eventBus->enablePublisherConfirms();
        $this->publisherConfirmsEnabled = true;
    }

    /**
     * @param mixed $result
     */
    private function wasConfirmed(mixed $result): bool
    {
        return is_array($result)
            && array_key_exists('confirmed', $result)
            && $result['confirmed'] === true;
    }
}