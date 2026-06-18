<?php

namespace SynergyERP\Shared\Outbox;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use SynergyERP\Shared\Models\Events\TransactionEvent;
use SynergyERP\Shared\Models\OutboxItem;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;

final class OutboxService
{
    public function __construct(
        private readonly OutboxDestinationResolver $destinationResolver
    ) {

    }
    /**
     * Persist an OutboxItem for asynchronous dispatch by the outbox worker.
     *
     * @param TransactionEvent $transactionEvent
     * @param TransactionRequest|null $transactionRequest Optional request for payload data
     * @throws Throwable
     */
    public function createOutboxItem(TransactionEvent $transactionEvent, ?TransactionRequest $transactionRequest = null): OutboxItem {

        $destination = $this->destinationResolver->resolve($transactionEvent);
        $payload = TransactionEventPayloadFactory::make($transactionEvent, $transactionRequest);

        try {
            return DB::transaction(
                function () use (
                    $transactionEvent, 
                    $destination, 
                    $payload
                ): OutboxItem {

                $outboxItem = new OutboxItem();
                $outboxItem->transaction_key = $transactionEvent->getTransactionKey();
                $outboxItem->operation_key = strtolower($destination['routing_key']);
                $outboxItem->idempotency_key = $transactionEvent->getIdempotencyKey();
                $outboxItem->bus_exchange = $destination['exchange'];
                $outboxItem->bus_route = strtolower($destination['routing_key']);
                $outboxItem->payload = $payload;
                $outboxItem->status = OutboxItem::STATUS_PUBLISHING;
                $outboxItem->retry_count = 0;
                //$outboxItem->locked_at = null;
                $outboxItem->published_at = null;
                //$outboxItem->last_error = null;
                $outboxItem->save();

                    Log::info('Outbox item created', [
                        'outbox_item_id' => $outboxItem->id,
                        'transaction_key' => $outboxItem->transaction_key,
                        'bus_exchange' => $outboxItem->bus_exchange,
                        'bus_route' => $outboxItem->bus_route,
                        'status' => $outboxItem->status,
                    ]);

                    return $outboxItem;
            }, 3);


        } catch (Throwable $e) {
            Log::error('Failed to create outbox item', [
                'transaction_key' => $transactionEvent->getTransactionKey(),
                'idempotency_key' => $transactionEvent->getIdempotencyKey(),
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}