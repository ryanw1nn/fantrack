<?php

namespace SynergyERP\Shared\Controllers\Execution;

use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Handlers\BaseHandler;
use SynergyERP\Shared\Handlers\Commands\CreateCommandHandler;
use SynergyERP\Shared\Handlers\Commands\DeleteCommandHandler;
use SynergyERP\Shared\Handlers\Commands\UpdateCommandHandler;
use SynergyERP\Shared\Handlers\Queries\FetchQueryHandler;
use SynergyERP\Shared\Handlers\Queries\SearchQueryHandler;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;

/**
 * This class is responsible for resolving the handler based off the transaction key context.
 * The transaction key context is in the request. This class will throw a LogicException if the
 * model class is not a subclass of BaseCommandHandler or the action is not supported.
 *
 * @author Alexander Torres
 * @package SynergyERP\Shared\Controllers\Execution
 */
final class HandlerResolver
{
    /**
     * Resolves the handler object based off the transaction key context.
     *
     * @throws \LogicException If the model class does not exist or the action is not supported.
     */
    public static function resolve(
        Contract $contract,
        OperationKeyContext $operationKeyContext,
        string $principalPuid
    ): BaseHandler {
        $handlerClass = self::getHandlerClassByAction($operationKeyContext->getOperationComponent('action'));
        $modelClass = self::getModelClassByContext($operationKeyContext);
        return new $handlerClass($contract, $modelClass, $principalPuid);
    }

    private static function getHandlerClassByAction(string $action): string {
        switch ($action) {
            case 'create':
                return CreateCommandHandler::class;
            case 'delete':
                return DeleteCommandHandler::class;
            case 'fetch':
                return FetchQueryHandler::class;
            case 'search':
                return SearchQueryHandler::class;
            default:
                return UpdateCommandHandler::class;
        }
    }
    /**
     * Resolves the model class based off the operation key context.
     *
     * @param OperationKeyContext $operationKeyContext
     * @return string
     * @throws \LogicException If the model class does not exist.
     */
    private static function getModelClassByContext(OperationKeyContext $operationKeyContext): string {
        $modelClass = $operationKeyContext->getModelNamespace();
        if (!class_exists($modelClass)) {
            throw new \LogicException("Invalid model class: {$modelClass}");
        }
        return $modelClass;
    }
}