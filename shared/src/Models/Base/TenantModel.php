<?php

namespace SynergyERP\Shared\Models\Base;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use SynergyERP\Shared\Models\Contracts\SchemaAware;
use SynergyERP\Shared\Models\Traits\SchemaAwareTrait;

/**
 * TenantModel class for regular business models
 * Only uses created_at timestamp, not updated_at
 */
class TenantModel extends Model implements SchemaAware
{
    use HasFactory, SoftDeletes, SchemaAwareTrait;
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;
    
    /**
     * The name of the "updated at" column.
     * Set to null to disable the updated_at timestamp.
     *
     * @var string|null
     */
    const UPDATED_AT = null;
}