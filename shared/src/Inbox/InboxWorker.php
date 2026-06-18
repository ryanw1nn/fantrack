<?php

namespace SynergyERP\Shared\Inbox;

use Throwable;
use Illuminate\Support\Facades\Log;

use SynergyERP\Shared\Models\InboxItem;

/**
 * Processes dispatches InboxItem records one at a time.
 * 
 * This class polls failed/pending InboxItems and
 * re-dispatches them to CQRS handlers.
 * 
 * Called by InboxWorkCommand on each loop iteration to handle
 * retry-eliable items alongside the live consumer.
 */
final class InboxWorker
{
    public function __construct(
        private readonly InboxDispatcher $dispatcher
    ) {

    }

    /**
     * Claim and process the next dispatchable InboxItem.
     * 
     * Returns true if an item was found and dispatched successfully,
     * false if no items were available or dispatch failed.
     */
    public function processNext(): bool
    {
        $inboxItem = InboxItem::query()
            ->dispatchable()
            ->first();

        if (!$inboxItem instanceof InboxItem) {
            return false;
        }

        try {
            // Re-fetch to ensure we have the latest state
            // (another worker may have claimed it between query and now)
            $freshItem = $inboxItem->fresh();

            if (!$freshItem instanceof InboxItem) {
                return false;
            }

            return $this->dispatcher->dispatch($freshItem);
        } catch (Throwable $e) {
            $inboxItem->markAsFailed($e->getMessage());

            Log::error('[InboxWorker] Failed while processing item', [
                'inbox_item_id'   => $inboxItem->id,
                'transaction_key' => $inboxItem->transaction_key,
                'retry_count'     => $inboxItem->retry_count,
                'error_type'      => get_class($e),
                'error_message'   => $e->getMessage(),
            ]);

            return false;
        }
    }
}