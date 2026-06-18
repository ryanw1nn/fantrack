<?php

namespace SynergyERP\Shared\Models\Base;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Schema\ManifestSchemaResolver;

/**
 * Base model for system-wide models whose schema is declared in the
 * manifest rather than derived from a per-request tenant JWT.
 *
 * Schema resolution is delegated to ManifestSchemaResolver, which consults
 * `manifest.models.X.eloquent.schema` first and falls back to the service
 * name. A child class can still pin `$schema` directly to short-circuit
 * the lookup.
 */
class SystemModel extends Model
{
    use HasFactory;

    /**
     * Pinned schema override for a subclass. When null, the schema is
     * resolved from the manifest at first `getTable()` call.
     *
     * @var string|null
     */
    protected $schema = null;
    protected $connection = 'mysql';

    /**
     * Get the table name with schema prefix.
     *
     * @return string
     */
    public function getTable()
    {
        $table = parent::getTable();

        if (strpos($table, '.') !== false) {
            return $table;
        }

        if ($this->schema === null) {
            try {
                $this->schema = ManifestSchemaResolver::resolve(class_basename($this));
            } catch (\Throwable $e) {
                Log::error('Failed to resolve schema for SystemModel', [
                    'model' => get_class($this),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return "{$this->schema}.{$table}";
    }
}
