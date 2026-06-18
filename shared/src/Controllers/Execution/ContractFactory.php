<?php

namespace SynergyERP\Shared\Controllers\Execution;

use SynergyERP\Shared\Models\Contracts\SchemaAware;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Contracts\Queries\FetchQueryContract;
use SynergyERP\Shared\Contracts\Queries\SearchQueryContract;
use SynergyERP\Shared\Contracts\Commands\BaseCommandContract;
use Illuminate\Support\Facades\Log;
/**
 * Factory class responsible for creating Contract instances based on transaction context
 * 
 * This factory validates request data and instantiates the appropriate contract type
 * (custom override or default) based on the transaction key context. Supports both
 * query contracts (fetch, search) and command contracts (create, update, delete).
 * 
 * @author Alexander Torres
 * @package SynergyERP\Shared\Controllers\Execution
 */
final class ContractFactory
{
    /**
     * Create a contract instance based on transaction context
     * 
     * Attempts to create a custom contract if one exists for the transaction,
     * otherwise falls back to creating a default contract based on the action type.
     * 
     * @param TransactionRequest $request
     * @param OperationKeyContext $operationKeyContext
     * @throws ValidationException
     * @throws LogicException
     * @return Contract
     */
    public static function create(TransactionRequest $request, OperationKeyContext $operationKeyContext): Contract
    {
        // extract tenant schema from request
        $tenantSchema = $request->getSchema();
        $requestData = $request->getRequestContent();

        // The authenticated principal's public_id is forwarded to the
        // contract so BaseCommandContract::beforeValidation() can inject
        // `created_by_principal` when (and only when) the concrete create
        // contract marks it required. Audit-column population now lives
        // on the contract layer — no more pre-writing requestData here.
        $principalPuid = $request->getPrincipalPuid();
        $contractClass = $operationKeyContext->getContractNamespace();
        
        Log::info('ContractFactory: Attempting to create contract', [
            'contract_class' => $contractClass,
            'schema' => $tenantSchema,
            'operation' => $operationKeyContext->getOperation()
        ]);
        
        try {

            if (class_exists($contractClass)) {
                Log::info('ContractFactory: Contract class exists, creating override contract', [
                    'contract_class' => $contractClass
                ]);
                return self::createOverrideContract(
                    $contractClass,
                    $requestData,
                    $tenantSchema,
                    $operationKeyContext,
                    $principalPuid
                );
            }

            Log::info('ContractFactory: Contract class does not exist, creating default contract', [
                'contract_class' => $contractClass,
                'operation' => $operationKeyContext->getOperation()
            ]);
            return self::createDefaultContract(
                $operationKeyContext,
                $requestData,
                $tenantSchema,
                $principalPuid
            );
        } catch (\ErrorException $e) {
            Log::error('ContractFactory: ErrorException caught', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'contract_class' => $contractClass,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \LogicException('Contract creation failed - composer dump-autoload may be needed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            Log::error('ContractFactory: Unexpected exception', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'contract_class' => $contractClass,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
        
        Log::error('ContractFactory: Contract class not found', [
            'contract_class' => $contractClass,
            'class_exists' => class_exists($contractClass)
        ]);
        throw new \LogicException('Contract class not found: ' . $contractClass);
    }

    /**
     * Create a custom override contract instance
     * 
     * Instantiates a custom contract class that overrides the default behavior.
     * Validates that the class is a proper Contract subclass before instantiation.
     * 
     * @param string $contractType Fully qualified class name of the contract
     * @param array $requestData Request data to pass to the contract
     * @param string $tenantSchema Tenant schema identifier
     * @param OperationKeyContext $operationKeyContext Operation key context
     * @return Contract The instantiated contract
     * @throws \InvalidArgumentException If contract class is not a Contract subclass
     */
    private static function createOverrideContract(
        string $contractType,
        array $requestData,
        string $tenantSchema,
        OperationKeyContext $operationKeyContext,
        ?string $principalPuid = null
    ): Contract {
        try {
            Log::info('ContractFactory: Instantiating override contract', [
                'contract_type' => $contractType,
                'schema' => $tenantSchema,
                'request_data_keys' => array_keys($requestData)
            ]);

            // Search and Fetch query contracts require $table (and $searchableColumns
            // for search) as positional constructor args — their signature differs from
            // command contracts, so we must branch here.
            if (is_subclass_of($contractType, SearchQueryContract::class)) {
                $modelClass = $operationKeyContext->getModelNamespace();
                $model = new $modelClass();
                if ($model instanceof \SynergyERP\Shared\Models\Contracts\SchemaAware) {
                    $model::setTransactionSchema($tenantSchema);
                }
                $contract = new $contractType(
                    $requestData,
                    $tenantSchema,
                    $model->getTable(),
                    ['id', ...$model->getFillable()],
                    $operationKeyContext
                );
            } elseif (is_subclass_of($contractType, FetchQueryContract::class)) {
                $modelClass = $operationKeyContext->getModelNamespace();
                $model = new $modelClass();
                if ($model instanceof \SynergyERP\Shared\Models\Contracts\SchemaAware) {
                    $model::setTransactionSchema($tenantSchema);
                }
                $contract = new $contractType(
                    $requestData,
                    $tenantSchema,
                    $model->getTable(),
                    $operationKeyContext
                );
            } else {
                $contract = new $contractType($requestData, $tenantSchema, $operationKeyContext, $principalPuid);
            }

            Log::info('ContractFactory: Override contract created successfully', [
                'contract_class' => get_class($contract)
            ]);
            return $contract;
        } catch (\Throwable $e) {
            Log::error('ContractFactory: Failed to instantiate override contract', [
                'contract_type' => $contractType,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Create a default contract based on the action type
     * 
     * Routes to the appropriate contract type based on the action:
     * - fetch: FetchQueryContract
     * - search: SearchQueryContract  
     * - create/update: BaseCommandContract
     * - delete: BaseCommandContract
     * 
     * @param OperationKeyContext $operationKeyContext
     * @param array $requestData
     * @param string $tenantSchema
     * @throws LogicException
     * @return Contract
     */
    private static function createDefaultContract(
        OperationKeyContext $operationKeyContext,
        array $requestData,
        string $tenantSchema,
        ?string $principalPuid = null
    ): Contract {

        $action = $operationKeyContext->getOperationComponent('action');
        $modelClass = $operationKeyContext->getModelNamespace();
        $model = new $modelClass();

        // Prime the per-request schema. SchemaAware models (TenantModel,
        // TransactionModel) need the tenant schema from the JWT; manifest-
        // scoped models (SystemModel, AuthModel) resolve their own schema.
        // The setTenantSchema fallback covers the legacy TenantBaseModel /
        // Authenticatable TenantModel pair, which still use that method name.
        if ($model instanceof SchemaAware) {
            $model::setTransactionSchema($tenantSchema);
        } elseif (method_exists($model, 'setTenantSchema')) {
            $model->setTenantSchema($tenantSchema);
        }

        if ($action === 'fetch')  {
            return new FetchQueryContract(
                $requestData, 
                $tenantSchema, 
                $model->getTable(),
                $operationKeyContext
            );
        } else if ($action === 'search') {
            return new SearchQueryContract(
                $requestData, 
                $tenantSchema, 
                $model->getTable(),
                ['id', ...$model->getFillable()],
                $operationKeyContext
            );
        } else if ($action === 'create' || $action === 'update' || $action === 'delete') {
            return new BaseCommandContract(
                $requestData,
                $tenantSchema,
                $operationKeyContext,
                $principalPuid
            );
        } else {
            throw new \LogicException("Action not supported: " . $operationKeyContext->getOperation());
            throw new \LogicException(
                "Action not supported: {$action}. Supported actions: fetch, search, create, update, delete"
            );
        }   
    }
}
