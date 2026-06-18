<?php

namespace SynergyERP\Shared\Models\Infra;

use Illuminate\Database\Eloquent\Model;

/**
 * PoliciesCache Model
 * 
 * Caches policy rules and permissions for operations
 */
class PoliciesCache extends Model
{
    protected $table = 'policies_cache';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    protected $keyType = 'int';
    
    public $timestamps = false;
    
    const CREATED_AT = 'created_at';
    const DELETED_AT = 'deleted_at';
    
    protected $fillable = [
        'operation_key',
        'effect',
        'priority',
        'rules',
        'notes',
        'active_at',
        'expires_at',
        'subject_service',
        'subject_model',
        'subject_id',
        'created_by',
        'authorized_by',
    ];
    
    protected $casts = [
        'id' => 'integer',
        'effect' => 'string',
        'priority' => 'integer',
        'rules' => 'array',
        'subject_id' => 'integer',
        'created_at' => 'datetime',
        'created_by' => 'integer',
        'authorized_by' => 'integer',
        'deleted_at' => 'datetime',
        'active_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
