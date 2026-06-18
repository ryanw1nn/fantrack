<?php

namespace SynergyERP\Shared\Models\Infra;

use Illuminate\Database\Eloquent\Model;

/**
 * ModelSettingsCache Model
 * 
 * Caches model-level settings and configurations
 */
class ModelSettingsCache extends Model
{
    protected $table = 'model_settings_cache';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    protected $keyType = 'int';
    
    public $timestamps = false;
    
    const CREATED_AT = 'created_at';
    const DELETED_AT = 'deleted_at';
    
    protected $fillable = [
        'label_plural',
        'label_singular',
        'member_defaults',
        'member_options',
        'member_metrics',
        'configurations',
        'created_by',
        'authorized_by',
    ];
    
    protected $casts = [
        'id' => 'integer',
        'member_defaults' => 'array',
        'member_options' => 'array',
        'member_metrics' => 'array',
        'configurations' => 'array',
        'created_at' => 'datetime',
        'created_by' => 'integer',
        'authorized_by' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
