<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Events\TransactionEvent;
use SynergyERP\Shared\Utils\TransactionEventPayloadFactory;
use SynergyERP\Shared\Models\OutboxItem;
/**
 * 
 * 
 * @author Alexander Torres
 * @package SynergyERP\Shared
 */
class OutboxService
{
    protected EventBus $eventBus;
    private bool $confirmsEnabled = false;
    
    public function __construct(EventBus $eventBus)
    {
        $this->eventBus = $eventBus;
    }

    
    
    /**
     * Create an outbox item for a transaction event
     * Called by CommandHandlers - writes to DB first, publish happens asyncronously
     * via the outbox worker container.
     *
     * @param TransactionEvent $transactionEvent
     * @return OutboxItem
     */
    public function createOutboxItem(TransactionEvent $transactionEvent): OutboxItem
    {
        Log::debug('OutboxService:createOutboxItem start', [
            'transaction_key' => $transactionEvent->transaction_key
        ]);


        // NOTE: operation_key is deprecated. Routing is derived from component columns.
        $service = $transactionEvent->operation_service ?? null;
        $cqrs = $transactionEvent->operation_cqrs ?? null;
        $model = $transactionEvent->operation_model ?? null;
        $action = $transactionEvent->operation_action ?? null;

        if (!$service || !$cqrs || !$model || !$action) {
            throw new \Exception('OutboxService: cannot create outbox item because operation_* components are missing on TransactionEvent');
        }

        // Exchange follows `{cqrs}.exchange` (command.exchange, query.exchange)
        $exchange = "{$cqrs}.exchange";

        // Routing key is `{service}.{cqrs}.{model}.{action}` (no model id suffix)
        $routingKey = "{$service}.{$cqrs}.{$model}.{$action}";

        Log::warning('OutboxService: resolved event bus route', [
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);
        
        // Create payload for event bus
        $payload = TransactionEventPayloadFactory::make($transactionEvent);
        
        $outboxItem = new OutboxItem();

        $outboxItem->transaction_key = $transactionEvent->getTransactionKey();
        $outboxItem->operation_key = $routingKey;
        $outboxItem->idempotency_key = $transactionEvent->getIdempotencyKey();
        $outboxItem->bus_exchange = $exchange;
        $outboxItem->bus_route = $routingKey;
        $outboxItem->payload = $payload;
        $outboxItem->status = OutboxItem::STATUS_PUBLISHING;
        $outboxItem->retry_count = 0;

        $outboxItem->save();

        return $outboxItem;
    }
    
    /**
     * Process a batch of pending (and retry-eligible) OutboxItem records.
     * Called by OutboxWorkerCommand in a polling loop.
     */
    public function processOutboxStack(): void
    {
        // Enable publisher confirms once per worker iteration
        if (!$this->confirmsEnabled) {
            $this->eventBus->enablePublisherConfirms();
            $this->confirmsEnabled = true;
        }

        $items = OutboxItem::pendingOrRetryable()->limit(50)->get();

        foreach ($items as $item) {
            $this->processOutboxItem($item);
        }
    }
    
    /**
     * Process a single outbox item
     * 
     * @param OutboxItem $item
     * @return void
     */
    protected function processOutboxItem(OutboxItem $item): void
    {
        try {
            // Update status to publishing
            $item->markAsPublishing();

            // Configure event bus
            $this->eventBus->setup_queue($item->bus_route, $item->bus_exchange);

            // Publish to event bus and get confirmation
            $publishResult = $this->eventBus->publish($item->bus_exchange, $item->bus_route, $item->payload);

            // Check if publishing was successful
            if ($publishResult && isset($publishResult['confirmed']) && $publishResult['confirmed'] === true) {
                // Update status to published only if confirmed
                $item->markAsPublished();

                Log::info('Message confirmed by event bus', [
                    'outbox_item_id' => $item->id,
                    'transaction_key' => $item->transaction_key,
                    'bus_exchange' => $item->bus_exchange,
                    'bus_route' => $item->bus_route
                ]);

                // Update transaction event published_at timestamp and status
                $transactionEvent = $item->transaction_key ? $item->transactionEvent : null;
                if ($transactionEvent) {
                    $transactionEvent->setPublished();

                    Log::info('Transaction event marked as published', [
                        'transaction_key' => $transactionEvent->getTransactionKey(),
                    ]);
                }

                Log::info('Outbox item processed successfully', [
                    'outbox_item_id' => $item->id,
                    'transaction_key' => $item->transaction_key,
                ]);
            } else {
                // If not confirmed, mark as failed for retry
                $item->markAsFailed('No confirmation received from event bus');

                Log::warning('No confirmation received from event bus', [
                    'outbox_item_id' => $item->id,
                    'transaction_key' => $item->transaction_key,
                    'bus_route' => $item->bus_route,
                    'retry_count' => $item->retry_count
                ]);
            }
        } catch (\Exception $e) {
            // Mark as failed with error message
            $item->markAsFailed($e->getMessage());

            Log::error('Failed to process outbox item', [
                'outbox_item_id' => $item->id,
                'transaction_key' => $item->transaction_key,
                'error' => $e->getMessage()
            ]);
        }
    }
}