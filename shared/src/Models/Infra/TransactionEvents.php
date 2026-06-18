<?php

namespace SynergyERP\Shared\Models\Infra;

use Illuminate\Database\Eloquent\Model;

/**
 * TransactionEvents Model
 * 
 * Tracks transaction events across the system
 */
class TransactionEvents extends Model
{
    protected $table = 'transaction_events';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    protected $keyType = 'int';
    
    public $timestamps = false;
    
    const CREATED_AT = null;
    
    protected $fillable = [
        'kind',
        'transaction_key',
        'idempotency_key',
        'operation_service',
        'operation_cqrs',
        'operation_model',
        'operation_action',
        'operation_model_id',
        'model_version',
        'status',
        'response_source',
        'request_hash',
        'response_hash',
        'principal_puid',
        'delegated_puid',
        'received_at',
        'executed_at',
        'published_at',
        'centralized_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'operation_model_id' => 'integer',
        'model_version' => 'integer',
        'principal_puid' => 'string',
        'delegated_puid' => 'string',
        'received_at' => 'datetime',
        'executed_at' => 'datetime',
        'published_at' => 'datetime',
        'centralized_at' => 'datetime',
    ];
}
