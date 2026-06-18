<?php

namespace SynergyERP\Shared\Models\Events;

use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Events\TransactionEvent;

class QueryEvent extends TransactionEvent
{
    /**
     * The discriminator value for this model.
     *
     * @var string
     */
    const KIND = 'query';
    
    /**
     * This class uses SchemaAwareTrait which provides static $transactionSchema property
     * and methods to manage schema context across instances during a single HTTP request lifecycle.
     */
    
    /**
     * Create a new QueryEvent instance from a TransactionRequest
     *
     * @param TransactionRequest $request
     * @return static
     */
    public static function fromTransactionRequest(TransactionRequest $request)
    {
        // Extract schema from request
        $schema = $request->getSchema();        
        if (!$schema) {
            throw new \Exception('Schema is required for query events');
        }
                
        // Call parent method to create and populate the instance
        // This will also populate the operation key components and hash the request
        $instance = parent::fromTransactionRequest($request);
        
        // Always set the schema on the instance to ensure it's properly set
        $instance->setSchema($schema);
        \Illuminate\Support\Facades\Log::info("Set schema on QueryEvent instance", [
            'schema' => $schema,
            'instance_schema' => $instance->getSchema()
        ]);
        
        // Double-check that the static schema is set
        if (static::$transactionSchema !== $schema) {
            static::$transactionSchema = $schema;
            \Illuminate\Support\Facades\Log::info("Updated static schema for QueryEvent", [
                'schema' => $schema,
                'static_schema' => static::$transactionSchema
            ]);
        }
        
        return $instance;
    }
    
    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        parent::booted();
    }
    
    /**
     * Override the save method to ensure schema is set for QueryEvent
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        // Ensure transaction schema is set for QueryEvent
        if (!$this->getSchema()) {
            throw new \Exception('Schema is required for query events');
        }

        return parent::save($options);
    }
    
    /**
     * Override getSchema to ensure QueryEvent always uses the tenant schema
     * This is a critical fix to prevent using project-service schema
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        // If transaction schema is set, use it 
        if (static::getTransactionSchema()) {
            return static::getTransactionSchema();
        }

        // If transaction schema is not set, use the parent method
        $schema = parent::getSchema();
        static::setTransactionSchema($schema);
        Log::info("QueryEvent was not set, using parent method", [
            'schema' => $schema,
            'operation_key' => $this->operation_key ?? 'unknown',
        ]);

        return $schema;
    }
}
