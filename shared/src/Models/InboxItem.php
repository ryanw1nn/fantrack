<?php

namespace SynergyERP\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

use Illuminate\Support\Facades\Log;

/**
 * Service-level model for incoming EventBus messages.
 * 
 * Each message consumed from RabbitMQ is persisted here before being
 * dispatched to the appropriate CQRS handler. Failed items are retried
 * with exponential backoff up to 5 attemots.
 * 
 * Lives in the service's default database (not tenant schemas).
 */

class InboxItem extends Model
{
    const STATUS_PUBLISHING = 'publishing';
    const STATUS_PUBLISHED  = 'published';
    const STATUS_FAILED     = 'failed';

    const UPDATED_AT = null;

    protected $connection = 'service';
    protected $table = 'inbox_items';

    protected $fillable = [
        'transaction_key',
        'operation_key',
        'idempotency_key',
        'exchange',
        'route',
        'payload',
        'status',
        'retry_count',
        'next_retry_at',
        'error_message',
        'published_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'next_retry_at' => 'datetime',
        'published_at' => 'datetime'
    ];

    /**
     * Set sensible defaults when created a new InboxItem
     */

    protected static function booted(): void
    {
        static::creating(function (InboxItem $item): void {
            if (!$item->status) {
                $item->status = self::STATUS_PUBLISHING;
            }

            if ($item->retry_count === null) {
                $item->retry_count = 0;
            }
        });
    }

    /**
     * Items eligible for processing: new items awaiting first attempt,
     * or failed items whose retry backoff has elapsed (max 5 retries).
     */
    public function scopeDispatchable(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q): void {
                $q->where('status', self::STATUS_PUBLISHING)
                    ->orWhere(function (Builder $inner): void {
                        $inner->where('status', self::STATUS_FAILED)
                            ->where('retry_count', '<', 5)
                            ->where('next_retry_at', '<=', now());
                    });
            })
            ->orderBy('id');
    }

    /**
     * Mark the inbox item as published (successfully processed).
     */
    public function markAsPublished(): void
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->published_at = now();
        $this->save();
    }

    /**
     * Mark the inbox item as failed with exponential backoff.
     * Backoff schedule: 2min, 4min, 8min, 16min, 32min
     */
    public function markAsFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $error;
        $this->retry_count = (int) $this->retry_count + 1;
        $this->next_retry_at = Carbon::now()->addMinutes(pow(2, $this->retry_count));
        $this->save();
    }

}
