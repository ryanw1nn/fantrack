<?php

namespace SynergyERP\Shared\Inbox;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use SynergyERP\Shared\Models\InboxItem;
use SynergyERP\Shared\Models\TenantModel;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Controllers\Execution\ContractFactory;
use SynergyERP\Shared\Controllers\Execution\HandlerResolver;

/**
 * Dispatches an InboxItem to the proper CQRS handler.
 * 
 * Mirrors Outbox\OutboxPublisher - that class publishes OutboxItems
 * to RabbitMQ; this class dispatches InboxItems to command/query/event
 * handlers within the receiving service.
 * 
 * Routing key format: {service}.{cqrs}.{model}.{action}
 * Example: "project-service.command.project.create"
 */
final class InboxDispatcher {

    /**
     * Dispatch a single InboxItem to the appropriate CQRS handler.
     *
     * Return true if dispatch succeeded, false if it failed.
     * On failure the item is marked as failed with exponential backoff.
     */
    public function dispatch(InboxItem $inboxItem): bool
    {
        // Already processed - skip
        if ($inboxItem->status === InboxItem::STATUS_PUBLISHED) {
            return true;
        }

        try {
            $this->route($inboxItem->payload, $inboxItem->exchange, $inboxItem->route);

            $inboxItem->markAsPublished();

            Log::info('[InboxDispatcher] Inbox item dispatched successfully', [
                'inbox_item_id'     => $inboxItem->id,
                'transaction_key'   => $inboxItem->transaction_key,
                'exchange'          => $inboxItem->exchange,
                'route'             => $inboxItem->route,
            ]);

            return true;
        } catch (Throwable $e) {
            $inboxItem->markAsFailed($e->getMessage());

            Log::error('[InboxDispatcher] Inbox dispatch failed', [
                'inbox_item_id'     => $inboxItem->id,
                'transaction_key'   => $inboxItem->transaction_key,
                'exchange'          => $inboxItem->exchange,
                'route'             => $inboxItem->route,
                'retry_count'       => $inboxItem->retry_count,
                'error_type'        => get_class($e),
                'error_message'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ---- CQRS Routing ----

    /**
     * Parse the routing key and delegate to the correct dispatch method
     * based on the CQRS type segment (command, query, or event).
     * 
     * @throws \InvalidArgumentException If the CQRS type is not recognized.
     */
    private function route(array $payload, string $exchange, string $routingKey): void
    {
        $operationKeyContext = new OperationKeyContext($routingKey);
        $cqrsType = $operationKeyContext->getCqrs();

        match ($cqrsType) {
            'command'   => $this->dispatchCommand($payload, $routingKey, $operationKeyContext),
            'query'     => $this->dispatchQuery($payload, $routingKey, $operationKeyContext),
            'event'     => $this->dispatchEvent($payload, $routingKey, $operationKeyContext),
            default     => throw new \InvalidArgumentException(
                "Unkown CQRS type: '{$cqrsType}' in routing key '{$routingKey}'. "
                . "Expected 'command', 'query', or 'event'."
            ),
        };
    }

    /**
     * Dispatch a command payload to the resolved CommandHandler.
     *
     * Reconstructs the CQRS pipeline that normally runs during an HTTP request:
     * OperationKeyContext → TransactionRequest → ContractFactory → HandlerResolver → handle()
     */
    protected function dispatchCommand(array $payload, string $routingKey, OperationKeyContext $operationKeyContext): void
    {
        $this->validatePayloadFields($payload, $routingKey);

        $schema = $this->resolveTenantSchema($payload);
        $principalPuid = $payload['principal_puid'] ?? 'system';

        // Set tenant schema so business models (Project, etc.) resolve to correct database
        TenantModel::setTenantSchema($schema);

        $contract = ContractFactory::create(
            $this->buildTransactionRequest($payload['transaction_request'] ?? [], $schema, $principalPuid, $operationKeyContext),
            $operationKeyContext
        );

        $handler = HandlerResolver::resolve($contract, $operationKeyContext, $principalPuid);
        $handler->handle();
    }

    /**
     * Dispatch a query payload to the resolved QueryHandler.
     *
     * Same pipeline as dispatchCoommand - only the handler type differs.
     */
    protected function dispatchQuery(array $payload, string $routingKey, OperationKeyContext $operationKeyContext): void
    {
        $this->validatePayloadFields($payload, $routingKey);

        $schema = $this->resolveTenantSchema($payload);
        $principalPuid = $payload['principal_puid'] ?? 'system';

        TenantModel::setTenantSchema($schema);

        $contract = ContractFactory::create(
            $this->buildTransactionRequest($payload['transaction_request'] ?? [], $schema, $principalPuid, $operationKeyContext),
            $operationKeyContext
        );

        $handler = HandlerResolver::resolve($contract, $operationKeyContext, $principalPuid);
        $handler->handle();
    }

    /**
     * Dispatch an event payload to the appropriate event handler.
     *
     * Events are optional - if no handler class exists for the routing key,
     * the event is logged and skipped rather than throwing. This allows a 
     * service to subscribe to broad routing patterns and only handle the
     * events it cares about.
     */
    protected function dispatchEvent(array $payload, string $routingKey, OperationKeyContext $operationKeyContext): void
    {
        $handlerClass = $operationKeyContext->getHandlerNamespace();

        if (!class_exists($handlerClass)) {
            Log::info('[InboxDispatcher] No event handler registered, skipping', [
                'routing_key'   => $routingKey,
                'handler_class' => $handlerClass,
            ]);
            return;
        }

        $schema = $this->resolveTenantSchema($payload);
        TenantModel::setTenantSchema($schema);

        $handler = app()->make($handlerClass);
        $handler->handle($payload, $routingKey);
    }

    // ---- Dispatch Helpers ----

    /**
     * Validate that the payload contains the required fields for command/query dispatch.
     * 
     * Required: transaction_key, operation (array), transaction_request
     * 
     * @throws \InvalidArgumentException If required fields are missing or malformed.
     */
    private function validatePayloadFields(array $payload, string $routingKey): void
    {
        $requiredFields = ['transaction_key', 'operation', 'transaction_request'];
        $missing = [];

        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                "Inbox payload for '{$routingKey}' is missing required fields: "
                . implode(', ', $missing)
                . ". Payload keys present: " . implode(', ', array_keys($payload))
            );
        }

        if (!is_array($payload['operation'])) {
            throw new \InvalidArgumentException(
                "Inbox payload for '{$routingKey}': 'operation' must be an array, got "
                . gettype($payload['operation'])
            );
        }
    }

    /**
     * Resolve the tenant schema from the inbox payload.
     *
     * Priority:
     *  1. Explicitly set in the payload ('schema' key)
     *  2. Derived from operation.service in the payload
     *  3. Fall back to service DB name from config
     * 
     * @throws \RuntimeException If no schema can be determined.
     */
    private function resolveTenantSchema(array $payload): string
    {
        if (!empty($payload['schema'])) {
            return $payload['schema'];
        }

        $operation = $payload['operation'] ?? [];
        if (!empty($operation['service'])) {
            return $operation['service'];
        }

        $envSchema = config('database.connections.mysql.database');
        if ($envSchema) {
            return $envSchema;
        }

        throw new \RuntimeException(
            "Cannot resolve tenant schema from inbox payload. "
            . "Ensure 'schema' or 'operation.service' is included in the payload."
        );
    }

    /**
     * Build a synthetic TransactionRequest from inbox payload data.
     *
     * During normal HTTP flow, TransactionRequest is built from the Laravel Request.
     * In the inbox flow, we reconstruct a Request with the payload data so that
     * ContractFactory and HandlerResolver work identically to the HTTP path.
     */
    private function buildTransactionRequest(
        array $requestData,
        string $schema,
        string $principalPuid,
        OperationKeyContext $operationKeyContext
    ): TransactionRequest {
        $syntheticRequest = new Request($requestData);

        $syntheticRequest->headers->set('Authorization', 'Bearer inbox-synthetic-token');
        $syntheticRequest->headers->set('X-Tenant-Schema', $schema);
        $syntheticRequest->headers->set('X-Principal-Puid', $principalPuid);

        return new TransactionRequest($syntheticRequest, $operationKeyContext);
    }
}