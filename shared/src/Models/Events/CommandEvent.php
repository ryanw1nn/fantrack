<?php

namespace SynergyERP\Shared\Models\Events;

use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Events\TransactionEvent;

class CommandEvent extends TransactionEvent
{
    /**
     * The discriminator value for this model.
     *
     * @var string
     */
    const KIND = 'command';
    
    /**
     * This class uses SchemaAwareTrait which provides static $transactionSchema property
     * and methods to manage schema context across instances during a single HTTP request lifecycle.
     */

    /**
     * Create a new CommandEvent instance from a TransactionRequest
     *
     * @param TransactionRequest $request
     * @return static
     * @throws \Exception If idempotency key is missing
     */
    public static function fromTransactionRequest(TransactionRequest $request)
    {
        // Validate that idempotency key is present for command events
        if (empty($request->getIdempotencyKey())) {
            throw new \Exception('Idempotency key is required for command events');
        }
        
        // Extract schema from request
        $schema = $request->getSchema();        
        if (!$schema) {
            throw new \Exception('Schema is required for command events');
        }
        
        // Log the schema being used
        \Illuminate\Support\Facades\Log::info("CommandEvent using schema", [
            'schema' => $schema,
            'operation_key' => $request->getOperationKey()
        ]);
        
        // Call parent method to create and populate the instance
        // This will also populate the operation key components and hash the request
        $instance = parent::fromTransactionRequest($request);
        
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

        // Enforce idempotency key for command events
        static::creating(function ($model) {
            if (empty($model->getAttribute('idempotency_key'))) {
                throw new \Exception('Idempotency key is required for command events');
            }
        });
    }
        
    /**
     * Override getSchema to ensure CommandEvent always uses the tenant schema
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
        Log::info("CommandEvent was not set, using parent method", [
            'schema' => $schema,
            'operation_key' => $this->operation_key ?? 'unknown',
        ]);

        return $schema;
    }
    
    /**
     * Override the save method to ensure schema is set for CommandEvent
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        // Ensure transaction schema is set for CommandEvent
        if (!$this->getSchema()) {
            throw new \Exception('Schema is required for command events');
        }

        return parent::save($options);
    }
}
