<?php

namespace SynergyERP\Shared\Models\Traits;

use Illuminate\Support\Facades\Config;

/**
 * HasTenant Trait
 * 
 * This trait provides multi-tenancy functionality for models.
 * It allows models to be scoped to a specific tenant schema.
 */
trait HasTenant
{
    /**
     * The tenant schema for this model.
     *
     * @var string|null
     */
    protected $tenantSchema = null;

    /**
     * Set the tenant schema for this model.
     *
     * @param string|null $schema
     * @return $this
     */
    public function setTenantSchema($schema)
    {
        $this->tenantSchema = $schema;
        return $this;
    }

    /**
     * Get the tenant schema for this model.
     *
     * @return string|null
     */
    public function getTenantSchema()
    {
        return $this->tenantSchema ?? Config::get('database.default_schema');
    }

    /**
     * Get the table associated with the model, prefixed with the tenant schema if set.
     *
     * @return string
     */
    public function getTable()
    {
        $schema = $this->getTenantSchema();
        $table = parent::getTable();
        
        return $schema ? "{$schema}.{$table}" : $table;
    }

    /**
     * Create a new instance of the model with tenant schema.
     *
     * @param array $attributes
     * @param string|null $schema
     * @return static
     */
    public static function createWithTenant(array $attributes = [], $schema = null)
    {
        $instance = new static($attributes);
        
        if ($schema) {
            $instance->setTenantSchema($schema);
        }
        
        return $instance;
    }

    /**
     * Scope a query to a specific tenant.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $schema
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTenant($query, $schema)
    {
        $this->setTenantSchema($schema);
        return $query;
    }
}
