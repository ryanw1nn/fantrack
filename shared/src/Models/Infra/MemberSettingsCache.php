<?php

namespace SynergyERP\Shared\Models\Infra;

use Illuminate\Database\Eloquent\Model;

/**
 * MemberSettingsCache Model
 * 
 * Caches member-level settings and preferences
 */
class MemberSettingsCache extends Model
{
    protected $table = 'member_settings_cache';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    protected $keyType = 'int';
    
    public $timestamps = false;
    
    const CREATED_AT = 'created_at';
    const DELETED_AT = 'deleted_at';
    
    protected $fillable = [
        'model_name',
        'member',
        'label_plural',
        'label_singular',
        'regex',
        'defaults',
        'options',
        'metrics',
        'formats',
        'min_max',
        'locked_states',
        'isRequired',
        'isSearchable',
        'isFilterable',
        'isGroupable',
        'created_by',
        'authorized_by',
    ];
    
    protected $casts = [
        'id' => 'integer',
        'defaults' => 'array',
        'options' => 'array',
        'metrics' => 'array',
        'formats' => 'array',
        'min_max' => 'array',
        'locked_states' => 'array',
        'isRequired' => 'boolean',
        'isSearchable' => 'boolean',
        'isFilterable' => 'boolean',
        'isGroupable' => 'boolean',
        'created_at' => 'datetime',
        'created_by' => 'integer',
        'authorized_by' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
