<?php

namespace SynergyERP\Shared\Models\Events;

use SynergyERP\Shared\Services\UlidFactory;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Models\Base\TransactionModel;
use SynergyERP\Shared\Models\Events\TransactionEventService;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

abstract class TransactionEvent extends TransactionModel
{
    /**
     * The discriminator value for the polymorphic transaction event type.
     * Child classes MUST override this constant with their specific type.
     *
     * @var string
     */
    protected const KIND = null;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transaction_events';
    
    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'kind',
        'idempotency_key',
        'transaction_key',
        'model_version',
        'status',
        'response_source',
        'request_hash',
        'response_hash',
        'principal_puid',
        'delegated_puid',
        'received_at',
        'executed_at',
        'published_at',
        'centralized_at',
        'operation_service',
        'operation_cqrs',
        'operation_model',
        'operation_action',
        'operation_model_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'model_version' => 'integer',
        'principal_puid' => 'string',
        'delegated_puid' => 'string',
        'received_at' => 'datetime',
        'executed_at' => 'datetime',
        'published_at' => 'datetime',
        'centralized_at' => 'datetime',
        'operation_model_id' => 'integer'
    ];
    
    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        if (static::KIND === null) {
            throw new \Exception('Child class must define KIND constant');
        }
        
        // Apply the kind discriminator when querying
        static::addGlobalScope('kind', function ($query) {
            if (static::class !== self::class) {
                $query->where('kind', static::KIND);
            }
        });
        
        // Set the kind when creating a new record
        static::creating(function ($model) {
            if (static::class !== self::class) {
                $model->kind = static::KIND;
            }
        });

    }
    
    /**
     * Override the newQuery method to ensure schema is set
     *
     * @param bool $excludeDeleted
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQuery($excludeDeleted = true)
    {
        // Use operation_service directly if available
        if (!$this->schema && $this->operation_service) {
            $this->setTenantSchema($this->operation_service);
        }
        // Fallback to operation_key for backward compatibility
        else if (!$this->schema && $this->operation_key) {
            $serviceName = explode('.', $this->operation_key)[0] ?? null;
            if ($serviceName) {
                $this->setTenantSchema($serviceName);
            }
        }
        
        return parent::newQuery($excludeDeleted);
    }
    
    /**
     * Override the save method to ensure schema is set before saving
     * and to populate operation key component fields
     *
     * @param array $options
     * @return bool
     * @throws \Exception If schema cannot be determined
     */
    public function save(array $options = [])
    {
        // Parse operation key components if operation_key is set
        if (!empty($this->operation_key) &&
            (empty($this->operation_service) || empty($this->operation_cqrs) ||
             empty($this->operation_model) || empty($this->operation_action))) {

            try {
                $context = \SynergyERP\Shared\Models\Operations\OperationKeyContext::fromOperationKey($this->operation_key);
                $this->operation_service = $context->getOperationComponent('service');
                $this->operation_cqrs = $context->getOperationComponent('cqrs');
                $this->operation_model = $context->getOperationComponent('model');
                $this->operation_action = $context->getOperationComponent('action');
                if ($context->hasModelId()) {
                    $this->operation_model_id = $context->getOperationComponent('id');
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to parse operation key: {$e->getMessage()}");
            }
        }

        // Resolve schema: static property > instance > operation_service > operation_key
        $schema = $this->getSchema();

        if (!$schema && isset(static::$transactionSchema) && static::$transactionSchema) {
            $schema = static::$transactionSchema;
            $this->setSchema($schema);
        }

        if (!$schema && $this->operation_service) {
            $schema = $this->operation_service;
            $this->setSchema($schema);
        }

        if (!$schema && $this->operation_key) {
            $serviceName = explode('.', $this->operation_key)[0] ?? null;
            if ($serviceName) {
                $schema = $serviceName;
                $this->setSchema($schema);
            } else {
                throw new \Exception("Schema not set for TransactionEvent and cannot be derived");
            }
        }

        if (!$schema) {
            throw new \Exception("Cannot determine schema for " . get_class($this));
        }

        // Switch the database connection to the resolved schema
        $defaultConnection = config('database.default');
        config(["database.connections.{$defaultConnection}.database" => $schema]);
        \Illuminate\Support\Facades\DB::purge($defaultConnection);
        \Illuminate\Support\Facades\DB::reconnect($defaultConnection);
        \Illuminate\Support\Facades\DB::statement("USE `{$schema}`");

        return parent::save($options);
    }

    public function getId(): int
    {
        return $this->id;
    }
    
    /**
     * Tables are assumed to exist, no need for table creation
     * This method is kept as a placeholder for backward compatibility
     *
     * @return void
     */
    protected function ensureTablesExist()
    {
        $schema = $this->getSchema();
        if (!$schema && isset(static::$transactionSchema) && static::$transactionSchema) {
            $schema = static::$transactionSchema;
            $this->setSchema($schema);
        }
    }
    
    /**
     * Prevents schema duplication by not calling parent::getTable() which would add schema again
     *
     * @return string
     */
    public function getTable()
    {
        // First try to use instance schema
        $schema = $this->getSchema();
        
        // If no instance schema, try static schema
        if (!$schema && isset(static::$transactionSchema)) {
            $schema = static::$transactionSchema;
        }
        
        // If still no schema, extract from operation_service
        if (!$schema && isset($this->operation_service)) {
            $schema = $this->operation_service;
        }
        
        // If still no schema, use the default from config
        if (!$schema) {
            $schema = config('database.default_schema');
        }
        
        // Log the schema being used for the table
        Log::info("Using schema for table {$this->table}", [
            'schema' => $schema,
            'table' => $this->table,
            'operation_service' => $this->operation_service ?? 'unknown'
        ]);
        
        // Return table name with schema prefix, but avoid duplication
        // by using $this->table directly instead of parent::getTable()
        return $schema ? "{$schema}.{$this->table}" : $this->table;
    }

    public function getTransactionKey(): string
    {
        return $this->transaction_key;
    }
    
    /**
     * Get the schema for this model
     * Uses SchemaAwareTrait's getTransactionSchema method
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return static::getTransactionSchema();
    }
    
    /**
     * Set the schema for this model
     * Uses SchemaAwareTrait's setTransactionSchema method
     *
     * @param string $schema
     * @return self
     */
    public function setSchema(string $schema): self
    {
        static::setTransactionSchema($schema);
        return $this;
    }
    
    /**
     * Mark the transaction event as received
     * Sets received_at timestamp
     *
     * @return self
     */
    public function setReceived(): self
    {
        $this->received_at = now();
        $this->save();
        
        return $this;
    }
    
    /**
     * Mark the transaction event as executed
     * Updates status and sets executed_at timestamp
     *
     * @return self
     */
    public function setExecuted(): self
    {
        $this->status = 'executed';
        $this->executed_at = now();
        $this->save();
        
        return $this;
    }
    
    /**
     * Mark the transaction event as published
     * Updates status and sets published_at timestamp
     *
     * @return self
     */
    public function setPublished(): self
    {
        $this->status = 'published';
        $this->published_at = now();
        $this->save();
        
        return $this;
    }
    
    /**
     * Mark the transaction event as centralized
     * Sets centralized_at timestamp
     *
     * @return self
     */
    public function setCentralized(): self
    {
        $this->centralized_at = now();
        $this->save();
        
        return $this;
    }
    
    /**
     * Get the kind of transaction event
     *
     * @return string
     */
    public function getKind(): string
    {
        return $this->kind;
    }
    
    /**
     * Get the model version
     *
     * @return int
     */
    public function getModelVersion(): int
    {
        return $this->model_version;
    }
    
    /**
     * Get the request hash
     *
     * @return string
     */
    public function getRequestHash(): string
    {
        return $this->request_hash;
    }
    
    /**
     * Get the response hash
     *
     * @return string|null
     */
    public function getResponseHash(): ?string
    {
        return $this->response_hash;
    }
    
    /**
     * Get the status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    
    /**
     * Get the principal public_id (CHAR(26) ULID).
     *
     * @return string|null
     */
    public function getPrincipalPuid(): ?string
    {
        return $this->principal_puid;
    }

    /**
     * Get the delegated-by public_id (CHAR(26) ULID).
     *
     * @return string|null
     */
    public function getDelegatedBy(): ?string
    {
        return $this->delegated_puid;
    }
    
    /**
     * Get the received at timestamp
     *
     * @return \DateTime|null
     */
    public function getReceivedAt(): ?\DateTime
    {
        return $this->received_at;
    }
    
    /**
     * Get the executed at timestamp
     *
     * @return \DateTime|null
     */
    public function getExecutedAt(): ?\DateTime
    {
        return $this->executed_at;
    }
    
    /**
     * Get the published at timestamp
     *
     * @return \DateTime|null
     */
    public function getPublishedAt(): ?\DateTime
    {
        return $this->published_at;
    }
    
    /**
     * Get the centralized at timestamp
     *
     * @return \DateTime|null
     */
    public function getCentralizedAt(): ?\DateTime
    {
        return $this->centralized_at;
    }
    
    /**
     * Get the idempotency key
     *
     * @return string|null
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotency_key;
    }
    
    /**
     * Calculate model version based on multiple strategies:
     * 1. Check for 'version' in request content
     * 2. Find latest transaction with same model and ID and increment
     * 3. Default to 1 if no previous versions found
     *
     * @param TransactionRequest $request
     * @return int
     */
    protected static function calculateModelVersion(TransactionRequest $request): int
    {
        // Strategy 1: Check if version is explicitly provided in request content
        $requestContent = $request->getRequestContent();
        if (isset($requestContent['version'])) {
            return (int)$requestContent['version'];
        }
        
        // Strategy 2: Find latest transaction for the same model and ID
        $operationKey = $request->getOperationKey();
        $parts = explode('.', $operationKey);
        
        // Only proceed if we have a valid operation key format
        if (count($parts) >= 3) {
            $service = $parts[0];
            $modelName = $parts[2];
            
            // Extract model ID from request content if available
            $modelId = null;
            if (isset($requestContent['id'])) {
                $modelId = $requestContent['id'];
            } elseif (isset($requestContent[$modelName . '_id'])) {
                $modelId = $requestContent[$modelName . '_id'];
            }
            
            if ($modelId) {
                // Look for previous transactions for this model and ID
                $pattern = "{$service}.command.{$modelName}.*:{$modelId}";
                
                try {
                    // Set the tenant schema for the query
                    static::setSchemaFromOperationKey($operationKey);
                    
                    // Find the latest transaction for this model and ID
                    $latestTransaction = static::where('operation_key', 'LIKE', $pattern)
                        ->orderBy('model_version', 'desc')
                        ->first();
                    
                    if ($latestTransaction) {
                        // If found, increment the version number
                        $newVersion = $latestTransaction->model_version + 1;
                        
                        Log::info("Found previous transaction, incrementing version", [
                            'operation_key' => $operationKey,
                            'model_id' => $modelId,
                            'previous_version' => $latestTransaction->model_version,
                            'new_version' => $newVersion
                        ]);
                        
                        return $newVersion;
                    }
                } catch (\Exception $e) {
                    // Log the error but continue with default version
                    Log::error("Error finding previous transaction versions: {$e->getMessage()}", [
                        'operation_key' => $operationKey,
                        'model_id' => $modelId
                    ]);
                }
            }
        }
        
        // Strategy 3: Default to version 1 if no previous versions found
        return 1;
    }
    
    /**
     * Extract the target model class from the operation key
     * Format: {service}.{cqrs}.{model}.{action}
     * Example: project-service.command.project.create
     *
     * @param string $operationKey
     * @return string|null
     */
    protected static function getTargetModelClass(string $operationKey): ?string
    {
        $parts = explode('.', $operationKey);
        if (count($parts) < 3) {
            return null;
        }
        
        // Extract model name from operation key (3rd part)
        $modelName = ucfirst($parts[2]); // Convert to PascalCase
        $serviceName = $parts[0];
        
        // Try to find the model class in the service namespace
        $possibleClasses = [
            "\\App\\Models\\{$modelName}",
            "\\{$serviceName}\\Models\\{$modelName}",
            "\\SynergyERP\\{$serviceName}\\Models\\{$modelName}"
        ];
        
        foreach ($possibleClasses as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }
        
        return null;
    }

    /**
     * Set the schema for all instances based on TransactionRequest
     * This method will either successfully set the schema or throw an exception
     *
     * @param TransactionRequest $request
     * @return void
     * @throws \Exception If schema cannot be determined or set
     */
    public static function setSchemaFromRequest(TransactionRequest $request)
    {
        try {
            
            // Use the bootFromTransaction method from SchemaAwareTrait
            $instance = new static();
            $instance->bootFromTransaction($request);
            
            // Set the schema on the static class
            $schema = $instance->getTransactionSchema();
            static::setTransactionSchema($schema);
            
            Log::info("Set schema for TransactionEvent using SchemaAwareTrait", [
                'schema' => $schema,
                'class' => static::class,
                'kind' => static::KIND ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            // Log the error
            Log::error("Failed to set schema: {$e->getMessage()}", [
                'exception' => $e,
                'schema' => $request->getSchema(),
                'operation_key' => $request->getOperationKey()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Ensure transaction events tables exist
     * Delegates to TransactionEventService
     *
     * @param string $schema
     * @return void
     */
    protected static function ensureTransactionEventTablesExist(string $schema)
    {
        $service = app(TransactionEventService::class);
        $service->ensureTransactionEventTablesExist($schema);
    }
    
    /**
     * Tables are assumed to exist, no need for table update methods
     * This method is kept for backward compatibility and delegates to TransactionEventService
     *
     * @return void
     * @deprecated Use TransactionEventService::ensureTransactionEventTablesExist() instead
     */
    protected static function updateTransactionEventsTable()
    {
        // Get the current schema
        $schema = static::getTransactionSchema() ?? config('database.connections.mysql.database');
        
        // Delegate to service
        static::ensureTransactionEventTablesExist($schema);
    }
    
    /**
     * Tables are assumed to exist, no need for table creation methods
     * This method is kept for backward compatibility and delegates to TransactionEventService
     *
     * @return void
     * @deprecated Use TransactionEventService::ensureTransactionEventTablesExist() instead
     */
    protected static function createTransactionEventsTable()
    {
        // Get the current schema
        $schema = static::getTransactionSchema() ?? config('database.connections.mysql.database');
        
        // Delegate to service
        static::ensureTransactionEventTablesExist($schema);
    }

    /**
     * Create a new instance from a TransactionRequest
     * Delegates to TransactionEventService
     *
     * @param TransactionRequest $request
     * @return static
     * @throws \Exception If the operation fails
     */
    public static function fromTransactionRequest(TransactionRequest $request)
    {
        // Delegate to TransactionEventService
        $service = app(\SynergyERP\Shared\Services\TransactionEventService::class);
        return $service->createTransactionEvent($request);
    }
    
}
