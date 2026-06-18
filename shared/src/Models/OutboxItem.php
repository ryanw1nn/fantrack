<?php

namespace SynergyERP\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use SynergyERP\Shared\Models\Base\SystemModel;
use SynergyERP\Shared\Models\Events\TransactionEvent;

final class OutboxItem extends SystemModel
{
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    const UPDATED_AT = null;

    protected $table = 'outbox_items';
    
    protected $fillable = [
        'transaction_key',
        'idempotency_key',
        'operation_key',
        'bus_exchange',
        'bus_route',
        'payload',
        'status',
        'retry_count',
        'next_retry_at',
        'error_message',
        //'locked_at',
        'published_at',
        'failed_at',
        //'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        //'locked_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (OutboxItem $item): void {
            if (! $item->status) {
                $item->status = self::STATUS_PUBLISHING;
            }

            if ($item->retry_count === null) {
                $item->retry_count = 0;
            }
        });
    }

    public function transactionEvent()
    {
        return $this->belongsTo(TransactionEvent::class, 'transaction_key', 'transaction_key');
    }

    public function scopeDispatchable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                self::STATUS_PUBLISHING,
                self::STATUS_FAILED,
            ])
            //->where('retry_count', '<', 5)
            //->whereNull('locked_at')
            ->orderBy('id');
    }

    public function markLocked(): void
    {
        //$this->locked_at = Carbon::now();
        $this->save();
    }

    public function releaseLock(): void
    {
        //$this->locked_at = null;
        $this->save();
    }

    public function markAsPublished(): void
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->published_at = Carbon::now();
        //$this->locked_at = null;
        //$this->last_error = null;
        $this->save();
    }

    public function markAsFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->retry_count = (int) $this->retry_count + 1;
        $this->error_message = $error;
        $this->failed_at = Carbon::now();
        $this->next_retry_at = Carbon::now()->addMinutes(pow(2, $this->retry_count));
        $this->save();
    }
}