<?php

namespace SynergyERP\Shared\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantModel extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // Delegated schema logic to TenantBaseModel's static methods.
    // TenantModel keeps it's own copy of getTable() so it can continue
    // to extend Authenticatable
    protected static ?string $tenantSchema = null;

    public static function setTenantSchema($schema)
    {
        static::$tenantSchema = $schema;
        // NOTE: Do NOT sync to TenantBaseModel here.
        // TenantBaseModel is for infrastructure models (InboxItem, OutboxItem)
        // that live in the service's default database, not in tenant schemas.
    }

    public static function getTenantSchema()
    {
        return static::$tenantSchema;
    }

    public function getTable()
    {
        $table = parent::getTable();

        if (static::$tenantSchema) {
            return static::$tenantSchema . '.' . $table;
        }

        return $table;
    }
}