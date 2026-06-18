<?php

namespace SynergyERP\Shared\Services;

use PDO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Events\CommandEvent;
use SynergyERP\Shared\Models\Events\QueryEvent;
use SynergyERP\Shared\Models\Events\TransactionEvent;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Models\Transactions\TransactionResponse;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Services\UlidFactory;
use SynergyERP\Shared\Outbox\OutboxService;

class TransactionEventService
{
    protected $outboxService;
    
    public function __construct(OutboxService $outboxService)
    {
        $this->outboxService = $outboxService;
    }
    
    /**
     * Get database connection
     */
    protected function getConnection(): PDO
    {
        // Get connection from global DB connection
        // In a real implementation, you would use the Laravel DB connection
        // This is a simplified version for demonstration purposes
        $host = env('DB_HOST');
        $database = env('DB_DATABASE');
        $username = env('DB_USERNAME');
        $password = env('DB_PASSWORD');
        
        return new PDO(
            "mysql:host={$host};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    /**
     * Ensure transaction events tables exist in the specified schema
     * 
     * @param string $schema
     * @return void
     */
    public function ensureTransactionEventTablesExist(string $schema): void
    {
        // Check if tables exist and create them if needed
        if (!$this->tableExists($schema, 'transaction_events')) {
            $this->createTransactionEventsTable($schema);
        }
        
        // Check if we need to update the table structure
        $this->updateTransactionEventsTable($schema);
    }
    
    /**
     * Create transaction events table in the specified schema
     * 
     * @param string $schema
     * @return void
     */
    protected function createTransactionEventsTable(string $schema): void
    {
        Log::info("Creating transaction_events table in schema: {$schema}");

        $sql = <<<SQL
-- Model:   TransactionEvent
-- Table:   transaction_events
-- Schema:  {$schema}
-- Service: ?
-- Generated: 2026-04-21T19:30:00+00:00
-- CREATE TABLE IF NOT EXISTS — safe to re-run against an existing database.

-- ─── Model: TransactionEvent  (table: transaction_events)
CREATE TABLE IF NOT EXISTS `{$schema}`.`transaction_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(50) NOT NULL,
  `transaction_key` VARCHAR(255) NOT NULL,
  `idempotency_key` VARCHAR(255) NOT NULL,
  `operation_service` VARCHAR(255) NOT NULL,
  `operation_cqrs` VARCHAR(255) NOT NULL,
  `operation_model` VARCHAR(255) NOT NULL,
  `operation_action` VARCHAR(255) NOT NULL,
  `operation_model_id` BIGINT NULL,
  `model_version` INT NOT NULL DEFAULT 1,
  `status` VARCHAR(50) NOT NULL,
  `response_source` VARCHAR(255) NOT NULL,
  `request_hash` VARCHAR(255) NULL,
  `response_hash` VARCHAR(255) NULL,
  `principal_puid` CHAR(26) NULL,
  `delegated_puid` CHAR(26) NULL,
  `received_at` TIMESTAMP NULL,
  `executed_at` TIMESTAMP NULL,
  `published_at` TIMESTAMP NULL,
  `centralized_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        try {
            DB::unprepared($sql);
            Log::info("Successfully created transaction_events table in schema: {$schema}");
        } catch (\Exception $e) {
            Log::error("Failed to create transaction_events table: {$e->getMessage()}", [
                'exception' => $e,
                'schema' => $schema
            ]);
            throw $e;
        }
    }
    
    /**
     * Update transaction events table structure if needed
     * 
     * @param string $schema
     * @return void
     */
    protected function updateTransactionEventsTable(string $schema): void
    {
        // Tables are assumed to exist with the correct structure
        // This is a placeholder for future schema migrations
        Log::info("Tables are assumed to exist with the correct structure, skipping update for schema: {$schema}");
    }
    
    /**
     * Check if a table exists in the given schema
     * 
     * @param string $schema
     * @param string $table
     * @return bool
     */
    protected function tableExists(string $schema, string $table): bool
    {
        $result = \Illuminate\Support\Facades\DB::select(
            "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
            [$schema, $table]
        );
        return $result[0]->cnt > 0;
    }
    
    /**
     * Create a transaction event for a request
     * 
     * @param TransactionRequest $transactionRequest
     * @return TransactionEvent
     * @throws \Exception
     */
    public function createTransactionEvent(TransactionRequest $transactionRequest): TransactionEvent
    {
        $transactionType = $transactionRequest->getCqrs();
        
        // Create the appropriate event type
        $eventClass = null;
        switch ($transactionType) {
            case 'command':
                $eventClass = CommandEvent::class;
                break;
            case 'query':
                $eventClass = QueryEvent::class;
                break;
            default:
                throw new \Exception("Unknown transaction type: {$transactionType}");
        }
        
        // Set schema for the event class
        $eventClass::setSchemaFromRequest($transactionRequest);
        
        // Create a new instance
        $instance = new $eventClass();
        
        // Generate transaction key
        $transactionKey = UlidFactory::generateWithHash('transaction', $transactionRequest->getRequestContent());
        
        // Set basic attributes
        $instance->transaction_key = $transactionKey;
        $instance->idempotency_key = $transactionRequest->getIdempotencyKey();
        $instance->model_version = $this->calculateModelVersion($transactionRequest);
        $instance->status = 'started';
        $instance->response_source = 'db';
        $instance->request_hash = hash('sha256', json_encode($transactionRequest->getRequestContent()));
        // CHAR(26) ULID (principal public_id), not an integer.
        $instance->principal_puid = $transactionRequest->getPrincipalPuid();
        $instance->received_at = date('Y-m-d H:i:s');
        
        // Set operation key components
        $this->setOperationKeyComponents($instance, $transactionRequest);
        
        // Save the instance
        $this->saveTransactionEvent($instance, $transactionRequest);
        
        return $instance;
    }
    
    /**
     * Add a transaction response to an existing transaction event
     * 
     * @param TransactionEvent $transactionEvent The transaction event to update
     * @param TransactionResponse $response The response to add
     * @return TransactionEvent The updated transaction event
     * @throws \Exception If the operation fails
     */
    public function addTransactionResponse(TransactionEvent $transactionEvent, TransactionResponse $response): TransactionEvent 
    {
        try {

            // Ensure schema is set before updating
            if (!$transactionEvent->getSchema()) {
                throw new \Exception("Cannot add response to TransactionEvent: schema is not set");
            }
            
            // Update the transaction event 
            $responseOutput = $response->getOutput();
            $transactionEvent->status = $response->getCode() === 200 ? 'completed' : 'failed';
            
            // Store response as hash instead of storing the full JSON
            $responseJson = json_encode($responseOutput);
            $transactionEvent->response_hash = hash('sha256', $responseJson);
            $transactionEvent->save();
            
            return $transactionEvent;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to add response to transaction event: {$e->getMessage()}", [
                'exception' => $e,
                'operation_key' => $transactionEvent->operation_key ?? 'unknown'
            ]);
            throw $e;
        }
    }
    
    /**
     * Process a transaction event by creating an outbox item
     * Only CommandEvent should create OutboxItem records
     * 
     * @param TransactionEvent $transactionEvent
     * @param TransactionRequest|null $transactionRequest Optional request for payload data
     */
    public function processTransactionEvent(TransactionEvent $transactionEvent, ?TransactionRequest $transactionRequest = null): void 
    {
        Log::warning('TransactionEventService: processTransactionEvent start', [
            'transaction_key' => $transactionEvent->transaction_key ?? null,
            'operation_key' => $transactionEvent->operation_key ?? null,
            'event_class' => get_class($transactionEvent),
            'kind' => method_exists($transactionEvent, 'getKind') ? $transactionEvent->getKind() : null,
        ]);

        // Create an outbox item for the event
        $this->outboxService->createOutboxItem($transactionEvent, $transactionRequest);

        Log::warning('TransactionEventService: processTransactionEvent end', [
            'transaction_key' => $transactionEvent->transaction_key ?? null,
            'operation_key' => $transactionEvent->operation_key ?? null,
        ]);

    }
    
    /**
     * Find transaction events that are not centralized yet
     */
    public function findUncentralizedEvents()
    {
        // Using Eloquent to query both types of events
        $commandEvents = CommandEvent::whereNull('centralized_at')
            ->where('status', 'completed')
            ->whereNotNull('published_at')
            ->get()
            ->toArray();
            
        $queryEvents = QueryEvent::whereNull('centralized_at')
            ->where('status', 'completed')
            ->whereNotNull('published_at')
            ->get()
            ->toArray();
            
        return array_merge($commandEvents, $queryEvents);
    }
    
    /**
     * Calculate model version based on request
     * 
     * @param TransactionRequest $request
     * @return string
     */
    protected function calculateModelVersion(TransactionRequest $request): string
    {
        // Extract model version from operation key or use default
        try {
            $context = OperationKeyContext::fromOperationKey($request->getOperationKey());
            $modelName = $context->getModelName();
            
            // Default to 1.0 if no specific version logic
            return '1.0';
        } catch (\Exception $e) {
            Log::warning("Failed to calculate model version: {$e->getMessage()}", [
                'operation_key' => $request->getOperationKey()
            ]);
            return '1.0';
        }
    }

    /**
     * Set operation key components on the transaction event
     * 
     * @param TransactionEvent $event
     * @param TransactionRequest $request
     * @return void
     */
    protected function setOperationKeyComponents(TransactionEvent $event, TransactionRequest $request): void
    {
        try {
            // Parse operation key into components
            $context = OperationKeyContext::fromOperationKey($request->getOperationKey());
            
            // Store components directly using getOperationComponent
            $event->operation_service = $context->getOperationComponent('service');
            $event->operation_cqrs = $context->getOperationComponent('cqrs');
            $event->operation_model = $context->getOperationComponent('model');
            $event->operation_action = $context->getOperationComponent('action');
            
            // Set operation_id if available
            if ($context->hasModelId()) {
                $event->operation_model_id = $context->getOperationComponent('id');
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse operation key components: {$e->getMessage()}", [
                'operation_key' => $request->getOperationKey()
            ]);
            
            // Set default values for operation components
            $parts = explode('.', $request->getOperationKey());
            $event->operation_service = $parts[0] ?? 'unknown';
            $event->operation_cqrs = $parts[1] ?? 'unknown';
            $event->operation_model = $parts[2] ?? 'unknown';
            $event->operation_action = $parts[3] ?? 'unknown';
        }
    }

    /**
     * Save transaction event with proper error handling and logging
     * 
     * @param TransactionEvent $event
     * @param TransactionRequest $request
     * @return void
     * @throws \Exception
     */
    protected function saveTransactionEvent(TransactionEvent $event, TransactionRequest $request): void
    {
        try {
            $event->save();
            $this->logTransactionEventSaved($event, $request);
        } catch (\Exception $e) {
            $this->logTransactionEventError($event, $request, $e);
            throw $e;
        }
    }

    /**
     * Log successful transaction event save
     * 
     * @param TransactionEvent $event
     * @param TransactionRequest $request
     * @return void
     */
    protected function logTransactionEventSaved(TransactionEvent $event, TransactionRequest $request): void
    {
        try {
            $context = OperationKeyContext::fromOperationKey($request->getOperationKey());
            Log::info("TransactionEvent saved successfully", [
                'transaction_key' => $event->transaction_key,
                'operation' => $context->getOperation(),
                'operation_service' => $context->getOperationComponent('service'),
                'operation_model' => $context->getOperationComponent('model'),
                'operation_action' => $context->getOperationComponent('action'),
                'type' => get_class($event)
            ]);
        } catch (\Exception $e) {
            // Log with basic information if parsing fails
            Log::info("TransactionEvent saved successfully", [
                'transaction_key' => $event->transaction_key,
                'operation_service' => explode('.', $request->getOperationKey())[0] ?? 'unknown',
                'type' => get_class($event)
            ]);
        }
    }
    
    /**
     * Log transaction event error
     * 
     * @param TransactionEvent $event
     * @param TransactionRequest $request
     * @param \Exception $exception
     * @return void
     */
    protected function logTransactionEventError(TransactionEvent $event, TransactionRequest $request, \Exception $exception): void
    {
        try {
            $context = OperationKeyContext::fromOperationKey($request->getOperationKey());
            Log::error("Failed to save TransactionEvent: {$exception->getMessage()}", [
                'exception' => $exception,
                'transaction_key' => $event->transaction_key,
                'operation' => $context->getOperation(),
                'operation_service' => $context->getOperationComponent('service'),
                'operation_model' => $context->getOperationComponent('model'),
                'operation_action' => $context->getOperationComponent('action'),
                'type' => get_class($event)
            ]);
        } catch (\Exception $e) {
            // Fallback to operation_key if parsing fails
            Log::error("Failed to save TransactionEvent: {$exception->getMessage()}", [
                'exception' => $exception,
                'transaction_key' => $event->transaction_key,
                'operation_service' => explode('.', $request->getOperationKey())[0] ?? 'unknown',
                'type' => get_class($event)
            ]);
        }
    }
}
