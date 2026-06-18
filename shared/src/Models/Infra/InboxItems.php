<?php

namespace SynergyERP\Shared\Models\Infra;

use Illuminate\Database\Eloquent\Model;

/**
 * InboxItems Model
 * 
 * Manages inbound event messages in the inbox pattern
 */
class InboxItems extends Model
{
    protected $table = 'inbox_items';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    protected $keyType = 'int';
    
    public $timestamps = false;
    
    const CREATED_AT = 'created_at';
    
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
        'published_at',
    ];
    
    protected $casts = [
        'id' => 'integer',
        'payload' => 'array',
        'retry_count' => 'integer',
        'created_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
