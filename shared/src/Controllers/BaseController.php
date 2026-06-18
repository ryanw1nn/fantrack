<?php

namespace SynergyERP\Shared\Controllers;

use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Controllers\Execution\ContractFactory;
use SynergyERP\Shared\Controllers\Execution\HandlerResolver;
use SynergyERP\Shared\ManifestLoader;
use SynergyERP\Shared\ManifestParser;
/**
 * Base controller for shared logic for both commands and queries all service controllers should
 * extend this class
 *
 * @author Alexander Torres
 * @package SynergyERP\Shared\Controllers
 */
class BaseController
{
    /**
     * Executes the transaction request from the {Model}Controller and returns an array of data.
     * This method would interact with the database to perform the requested action.
     * 
     * On error, this method throws the exception to be handled by middleware.
     *
     * @param TransactionRequest $transactionRequest
     * @return array
     * @throws Throwable
     */
    public function execute(TransactionRequest $transactionRequest): array
    {
        $operationKeyContext = $transactionRequest->getOperationKeyContext();

        Log::info('BaseController: Starting execution', [
            'operation' => $operationKeyContext->getOperation()
        ]);

        try {
            $contract = ContractFactory::create (
                $transactionRequest,
                $operationKeyContext
            );

            Log::info('BaseController: Contract created', [
                'contract_class' => get_class($contract),
                'operation' => $operationKeyContext->getOperation()
            ]);
        } catch (\Throwable $e) {
            Log::error('BaseController: ContractFactory::create failed', [
                'operation' => $operationKeyContext->getOperation(),
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        try {
            $handler = HandlerResolver::resolve (
                $contract,
                $operationKeyContext,
                $transactionRequest->getPrincipalPuid()
            );

            // Log the handler execution
            Log::info('BaseController: Handler resolved, executing', [
                'handler' => get_class($handler),
                'operation' => $operationKeyContext->getOperation(),
                'service' => $operationKeyContext->getOperationComponent('service'),
                'model' => $operationKeyContext->getOperationComponent('model'),
                'action' => $operationKeyContext->getOperationComponent('action')
            ]);
        } catch (\Throwable $e) {
            Log::error('BaseController: HandlerResolver::resolve failed', [
                'operation' => $operationKeyContext->getOperation(),
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        try {
            // Let exceptions bubble up to middleware
            $result = $handler->handle();
            
            Log::info('BaseController: Handler executed successfully', [
                'operation' => $operationKeyContext->getOperation(),
                'result_type' => gettype($result),
                'result_count' => is_array($result) ? count($result) : 'N/A'
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('BaseController: Handler::handle failed', [
                'operation' => $operationKeyContext->getOperation(),
                'handler' => get_class($handler),
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}