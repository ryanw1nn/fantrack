<?php

namespace SynergyERP\Shared\Models\Base;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use SynergyERP\Shared\Models\Contracts\SchemaAware;
use SynergyERP\Shared\Models\Traits\SchemaAwareTrait;

/**
 * Transaction model class specifically for transaction-related models
 * Does not use any automatic timestamps (created_at or updated_at)
 */
class TransactionModel extends Model implements SchemaAware
{
    use HasFactory, SoftDeletes, SchemaAwareTrait;
    
    /**
     * Indicates if the model should be timestamped.
     * Set to false to disable all automatic timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Disable automatic updated_at timestamp
     *
     * @var string|bool
     */
    public $updatedAt = false;

    /**
     * Disable automatic created_at timestamp
     *
     * @var string|bool
     */
    public $createdAt = false;
    
    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = null;

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = null;

}
