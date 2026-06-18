<?php

namespace SynergyERP\Shared\Models\Infra;

use Illuminate\Database\Eloquent\Model;

/**
 * RelationshipsCache Model
 * 
 * Caches relationship information between models across services
 */
class RelationshipsCache extends Model
{
    protected $table = 'relationships_cache';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    protected $keyType = 'int';
    
    public $timestamps = false;
    
    const CREATED_AT = 'created_at';
    const DELETED_AT = 'deleted_at';
    
    protected $fillable = [
        'source_service',
        'source_model',
        'source_id',
        'target_service',
        'target_model',
        'target_id',
        'relationship_catalog_id',
        'created_by',
        'authorized_by',
    ];
    
    protected $casts = [
        'id' => 'integer',
        'source_id' => 'integer',
        'target_id' => 'integer',
        'relationship_catalog_id' => 'integer',
        'created_by' => 'integer',
        'authorized_by' => 'integer',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
