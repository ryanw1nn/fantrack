<?php

namespace SynergyERP\Shared\Models\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;

trait SchemaAwareTrait
{
    /**
     * The tenant schema for this model.
     *
     * Public so that classes redeclaring it (an anti-pattern that's
     * historically been copy-pasted across models) don't trigger the
     * PHP 8.x "different visibility" trait-shadowing bug, where the
     * setter writes to the trait's slot and the getter reads from the
     * class's slot and the value never sticks.
     *
     * @var string|null
     */
    public static $transactionSchema = null;

    /**
     * Boot the schema aware trait for a model.
     *
     * @return void
     */
    public static function bootSchemaAwareTrait()
    {
        static::creating(function ($model) {
            if (!$model->getTransactionSchema()) {
                throw new \Exception("Cannot determine schema for " . get_class($model));
            }
        });

        static::saving(function ($model) {
            if (!$model->getTransactionSchema()) {
                throw new \Exception("Cannot determine schema for " . get_class($model));
            }
        });
    }

    /**
     * Boot from transaction request
     *
     * @param TransactionRequest $request
     * @return void
     */
    public function bootFromTransaction(TransactionRequest $request)
    {
        // get schema from JWT token
        $schema = $request->getSchema();
        if (!$schema) {
            throw new \Exception("Could not determine schema from request. No schema in token.");
        }
        
        // Set the schema
        $this->setTransactionSchema($schema);
        
        // Ensure schema exists
        $this->ensureSchemaExists($schema);
        
        // Switch to the tenant schema
        $this->switchSchema($schema);
        
        app(\SynergyERP\Shared\Services\TransactionEventService::class)
            ->ensureTransactionEventTablesExist($schema);
    }

    // Setters
    public static function setTransactionSchema(string $schema): void
    {
        static::$transactionSchema = $schema;
    }

    // Getters
    public static function getTransactionSchema(): ?string
    {
        return static::$transactionSchema;
    }
    
    public function getTable()
    {
        // Get transaction schema
        $schema = $this->getTransactionSchema();
        if (!$schema) {
            throw new \Exception("Cannot determine schema for " . get_class($this));
        }
        
        // Get the parent table name without any schema prefix
        $parentTable = parent::getTable();
        
        // Check if the parent table already has a schema prefix
        if (strpos($parentTable, '.') !== false) {
            // If it does, return the parent table as is
            return $parentTable;
        }
        
        // Otherwise, add the schema prefix
        return "{$schema}.{$parentTable}";
    
    }

    // Validation
    public function ensureTenantSchema()
    {

        // if the static schema is set 
        if (!static::getTransactionSchema()) {
            throw new \Exception("Cannot determine schema for " . get_class($this));
        }

        // Try to get the service name from the operation key
        $serviceName = null;
        if (property_exists($this, 'operation_key') && $this->operation_key) {
            $serviceName = explode('.', $this->operation_key)[0];
        }

        // if the service name is not the same as the schema, return true
        return $serviceName !== static::getTransactionSchema();
    }
    
    /**
     * Ensure the database exists
     *
     * @param string $schema
     * @return void
     */
    protected function ensureSchemaExists($schema)
    {        
        try {

            // Check if the schema exists
            $result = DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?", [$schema]);
            if (empty($result)) {
                throw new \Exception("Schema '{$schema}' does not exist. Client may not be properly onboarded.");
            }

            // Log the schema
            Log::info("Verified schema exists: {$schema}");

        } catch (\Exception $e) {
            Log::error("Failed to verify schema: {$e->getMessage()}", [
                'exception' => $e,
                'schema' => $schema
            ]);
            throw $e;
        }
    }
    
    /**
     * Switch to the specified schema
     *
     * @param string $schema
     * @return void
     */
    protected function switchSchema($schema)
    {
        $defaultConnection = config('database.default');
        
        try {

            // Switch to the tenant schema
            config(["database.connections.{$defaultConnection}.database" => $schema]);
            DB::purge($defaultConnection);
            DB::reconnect($defaultConnection);
            
            // Explicitly set the database for all Schema operations
            DB::statement("USE `{$schema}`");
            
            // Log the current database being used
            $currentDb = DB::select("SELECT DATABASE() as db")[0]->db;
            Log::info("Current Eloquent database used: {$currentDb}");
        } catch (\Exception $e) {
            Log::error("Failed to switch to schema: {$e->getMessage()}", [
                'exception' => $e,
                'schema' => $schema
            ]);
            throw $e;
        }
    }
    
    /**
     * Ensure all required tables exist
     *
     * @return void
     */
    protected function ensureTableExists()
    {
        // This should be implemented by the specific model classes
        // For example, TransactionEvent will create transaction_events table
        // OutboxItem will create outbox_items table
    }

    public function save(array $options = [])
    {
        // Ensure schema is set before saving
        if (!$this->getTransactionSchema()) {
            throw new \Exception("Cannot save model. Schema not set for " . get_class($this));
        }
        
        return parent::save($options);
    }
}
