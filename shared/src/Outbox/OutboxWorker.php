<?php

namespace SynergyERP\Shared\Outbox;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use SynergyERP\Shared\Models\OutboxItem;

final class OutboxWorker
{
    public function __construct(private readonly OutboxPublisher $publisher) {}

    public function run(int $sleepSeconds = 2): void
    {
        while (true) {
            $processed = $this->processNext();

            if (! $processed) {
                sleep($sleepSeconds);
            }
        }
    }

    public function processNext(): bool
    {
        $outboxItem = $this->claimNext();

        if (! $outboxItem instanceof OutboxItem) {
            return false;
        }

        try {
            $freshItem = $outboxItem->fresh();

            if (! $freshItem instanceof OutboxItem) {
                return false;
            }

            return $this->publisher->publish($freshItem);
        } catch (Throwable $e) {
            $outboxItem->markAsFailed($e->getMessage());

            Log::error('Outbox worker failed while processing item', [
                'outbox_item_id' => $outboxItem->id,
                'transaction_key' => $outboxItem->transaction_key,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function claimNext(): ?OutboxItem
    {
        return DB::transaction(function (): ?OutboxItem {
            /** @var OutboxItem|null $outboxItem */
            $outboxItem = OutboxItem::query()
                ->dispatchable()
                ->lockForUpdate()
                ->first();

            if (! $outboxItem) {
                return null;
            }

            $outboxItem->markLocked();

            Log::info('Outbox item claimed for publishing', [
                'outbox_item_id' => $outboxItem->id,
                'transaction_key' => $outboxItem->transaction_key,
                'status' => $outboxItem->status,
            ]);

            return $outboxItem;
        }, 3);
    }
}