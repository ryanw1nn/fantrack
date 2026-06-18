<?php

namespace SynergyERP\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight base for infrastructure models that need tenant-schema
 * prefixing but NOT authentication, API tokens, or soft-deletes.
 */
abstract class TenantBaseModel extends Model
{
    protected static ?string $tenantSchema = null;

    public static function setTenantSchema(?string $schema): void
    {
        static::$tenantSchema = $schema;
    }

    public static function getTenantSchema(): ?string
    {
        return static::$tenantSchema;
    }

    public function getTable(): string
    {
        $table = $this->table ?? parent::getTable();

        if (static::$tenantSchema && !str_contains($table, '.')) {
            return static::$tenantSchema . '.' . $table;
        }

        return $table;
    }
}