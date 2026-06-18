<?php

namespace SynergyERP\Shared\Inbox;

use Illuminate\Support\Facades\Log;

use SynergyERP\Shared\Models\TenantModel;
use SynergyERP\Shared\Services\EventBus;

/**
 * Subscribes to RabbitMQ queues and processes incoming messages.
 * 
 * For each message received:
 *  1. Reset tenant schema (prevent static state pollution between messages)
 *  2. Extract payload and set tenant schema for buisness models
 *  3. Persist InboxItem via InboxService (before ACK - ensures at-least-once delivery)
 *  4. ACK the message
 *  5. Dispatch to CQRS handler via InboxDispatcher
 * 
 * If persisting fails, the message is NACKed and requeued by RabbitMQ.
 * If dispatch fails after ACK, the InboxItem is marked as failed and
 * will be retried by the InboxWorker.
 */
final class InboxConsumer
{

    public function __construct(
        private readonly InboxService $inboxService,
        private readonly InboxDispatcher $dispatcher
    ) {
    }

    /**
     * Declare queues and register consumers for all subscriptions.
     * Does not block - call $eventBus->waitForMessages() after this to start consuming.
     * 
     * @param array $subscriptions  Array of ['exchange' => string, 'routing_key' => string]
     * @param EventBus $eventBus    Connected EventBus instance.
     */
    public function registerSubscriptions(array $subscriptions, EventBus $eventBus): void
    {
        foreach ($subscriptions as $sub) {
            $exchange = $sub['exchange'];
            $routingKey = $sub['routing_key'];
            $queueName = $this->buildQueueName($exchange, $routingKey);

            $eventBus->setup_queue($queueName, $exchange, $routingKey);

            $callback = function ($msg) use ($exchange) {
                try {
                    // Reset tenant schema to prevent static state pollution
                    // between messages in this long-running worker process
                    TenantModel::setTenantSchema(null);

                    $payload = json_decode($msg->body, true);
                    if (!$payload) {
                        Log::warning('[InboxConsumer] Invalid JSON payload, ACKing and skipping', [
                            'exchange'      => $exchange,
                            'body_length'   => strlen($msg->body ?? ''),
                        ]);
                        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
                        return;
                    }

                    $routingKey = $msg->delivery_info['routing_key'] ?? 'unknown';

                    // Set tenant schema for business models (Project, etc.)
                    // InboxItem itself lives in the service DB — no schema prefix needed
                    $schema = $payload['schema'] ?? null;
                    if ($schema) {
                        TenantModel::setTenantSchema($schema);
                    }

                    // Persist InboxItem BEFORE ACK - if DB insert fails, the message
                    // is NACKed and RabbitMQ requeues it (at-least-once delivery)
                    $inboxItem = $this->inboxService->createInboxItem($payload, $exchange, $routingKey);

                    // ACK - message is now safely persisted in the database
                    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);

                    // Dispatch to CQRS handler - if this fails, the InboxItem
                    // is marked as failed and will be retried by InboxWorker
                    $this->dispatcher->dispatch($inboxItem);

                } catch (\Exception $e) {
                    Log::error('[InboxConsumer] Failed to process message, NACKing for requeue', [
                        'exchange'      => $exchange,
                        'routing_key'   => $msg->delivery_info['routing_key'] ?? 'unknown',
                        'error_type'    => get_class($e),
                        'error_message' => $e->getMessage(),
                    ]);
                    $msg->delivery_info['channel']->basic_nack(
                        $msg->delivery_info['delivery_tag'], false, true
                    );
                }
            };

            $eventBus->registerConsumer($queueName, $callback);
            Log::info("[InboxConsumer] subscribed to {$exchange} -> {$routingKey}");
        }
    }

    /**
     * Build a deterministic, unique queue name from exchange and routing key.
     */
    protected function buildQueueName(string $exchange, string $routingKey): string
    {
        return $exchange . '.' . str_replace(['*', '#', '.'], ['_', '_', '_'], $routingKey);
    }
}