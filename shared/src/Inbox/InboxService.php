<?php

namespace SynergyERP\Shared\Inbox;

use Throwable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use SynergyERP\Shared\Models\InboxItem;

/**
 * Persists incoming EventBus messages as InboxItem records.
 * 
 * Mirrors Outbox\OutboxService - that class creates OutboxItems from
 * TransactionEvents; this class creates InboxItems from raw RabbitMQ
 * payloads. The idempotency_key unique index prevents duplicate
 * processing when the same message is delivered more than once.
 */

final class InboxService
{
    /**
     * Persist an InboxItem for a message received from the EventBus.
     * 
     * When an idempotency_key is present, the insert is wrapped in a
     * DB transaction. If a duplicate key violation occurs (MySQL 1062),
     * the existing record is returned instead of throwing.
     * 
     * @throws Throwable    Rethrown if the error is not a duplicate key violation.
     */
    public function createInboxItem(array $payload, string $exchange, string $routingKey): InboxItem
    {
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        $transactionKey = $payload['transaction_key'] ?? null;

        $attributes = [
            'transaction_key'  => $transactionKey,
            'operation_key'    => $routingKey,
            'idempotency_key'  => $idempotencyKey,
            'exchange'         => $exchange,
            'route'            => $routingKey,
            'payload'          => $payload,
            'status'           => InboxItem::STATUS_PUBLISHING,
            'retry_count'      => 0,
        ];

        // When an idempotency key is present, wrap in a transaction and
        // let the unique index catch any race-condition duplicates.
        if ($idempotencyKey) {
            try {
                return DB::connection('service')->transaction(function () use ($attributes): InboxItem {
                    $item = new InboxItem($attributes);
                    $item->save();

                    Log::info('[InboxService] Inbox item created', [
                        'inbox_item_id'     => $item->id,
                        'transaction_key'   => $item->transaction_key,    
                        'exchange'          => $item->exchange,
                        'route'             => $item->route,
                        'status'            => $item->status,
                    ]);

                    return $item;
                }, 3);
            } catch (QueryException $e) {
                // MySQL error 1062 = Duplicate entry (unique constraint violation)
                // This is expected under concurent delivery - return the existing record.
                if ($e->errorInfo[1] === 1062) {
                    $existing = InboxItem::where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        Log::info('[InboxService] Duplicate message skipped (caught by unique constraint)', [
                            'idempotency_key' => $idempotencyKey,
                            'inbox_item_id'   => $existing->id,
                        ]);
                        return $existing;
                    }
                }

                // Not a duplicate - log and rethrow
                Log::error('[InboxService] Failed to create inbox item', [
                    'idempotency_key'   => $idempotencyKey,
                    'transaction_key'   => $transactionKey,
                    'error_type'        => get_class($e),
                    'error_message'     => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // No idempotency key - single insert (no duplicate protection needed)
        try {
            return DB::connection('service')->transaction(function () use ($attributes): InboxItem {
                $item = new InboxItem($attributes);
                $item->save();

                Log::info('[InboxService] Inbox item created', [
                    'inbox_item_id'     => $item->id,
                    'transaction_key'   => $item->transaction_key,
                    'exchange'          => $item->exchange,
                    'route'             => $item->route,
                    'status'            => $item->status,
                ]);

                return $item;
            }, 3);
        } catch (Throwable $e) {
            Log::error('[InboxService] Failed to create inbox item', [
                'transaction_key'   => $transactionKey,
                'error_type'        => get_class($e),
                'error_message'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}