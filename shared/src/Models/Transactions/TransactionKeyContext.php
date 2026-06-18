<?php

namespace SynergyERP\Shared\Models\Transactions;

use Illuminate\Support\{
    Facades\Log,
    Facades\Env
};

/**
 * Base CommandHandler class for all command handlers in the system
 * Implements common functionality for command processing in CQRS pattern
 */
class TransactionKeyContext
{
    private string $key;
    private string $service;
    private string $cqrs;
    private string $model;
    private string $action;
    private int $modelId;

    public function __construct(string $input)
    {
        $this->parseInput($input);
    }

    /**
     * Create a TransactionKeyContext from an operation key
     * 
     * @param string $operationKey The operation key
     * @return self The TransactionKeyContext
     */
    public static function fromOperationKey(string $operationKey): self
    {
        // explode operation key by colon
        $parts = explode(':', $operationKey);
        if (count($parts) !== 2) {
            throw new \Exception('Invalid operation key format');
        }

        // validate model id
        if (!is_numeric($parts[1])) {
            throw new \Exception('Invalid model id');
        }

        // create instance
        $instance = new self($parts[0]);
        $instance->modelId = (int)$parts[1];
        return $instance;
    }

    /**
     * Parse the input 
     * 
     * @return void
     */
    private function parseInput($input)
    {
        // ensure it is a string
        if (!is_string($input)) {
            throw new \Exception('Invalid transaction key');
        }
                
        // explode input by dot
        $parts_by_dot = explode('.', $input);
        
        // if not enough parts
        if (count($parts_by_dot) < 4) {
            throw new \Exception('Invalid transaction key: not enough parts');
        }
        else if (count($parts_by_dot) > 4) {
            throw new \Exception('Invalid transaction key: too many parts');
        }

        // set properties from transaction key
        $this->key = $input;
        $this->setPropertiesFromTransactionKey($parts_by_dot);
    }

    private function setPropertiesFromTransactionKey($parts)
    {
        $this->service = $parts[0];
        $this->cqrs = $parts[1];
        $this->model = $this->formatModelName($parts[2]);
        $this->action = $this->formatActionName($parts[3]);
    }

    /**
     * Set properties from a model name (e.g., CreateProjectCommand)
     * 
     * @param array $parts Array of parts split by uppercase letters
     * @return void
     */
    private function setPropertiesFromModelName($parts)
    {
        // TODO: Implement this after Phase 1, if needed
    }
    
    /**
     * Format model name from dashed format to PascalCase
     * 
     * @param string $modelNameDashed Dashed model name (e.g., "project-expense")
     * @return string Formatted model name (e.g., "ProjectExpense")
     */
    private function formatModelName($modelNameDashed)
    {
        $modelName = "";
        $modelNameParts = explode('-', $modelNameDashed);
        foreach ($modelNameParts as &$part) {
            $modelName .= ucfirst($part);
        }
        return $modelName;
    }
    
    /**
     * Format action name from dashed format to camelCase
     * 
     * @param string $actionNameDashed Dashed action name (e.g., "fetch-by-id")
     * @return string Formatted action name (e.g., "fetchById")
     */
    private function formatActionName($actionNameDashed)
    {
        $first = 1;
        $actionName = "";
        $actionNameParts = explode('-', $actionNameDashed);
        foreach ($actionNameParts as &$part) {
            if ($first++ == 1) {
                $actionName .= $part;
            } else {
                $actionName .= ucfirst($part);
            }
        }
        return $actionName;
    }

    public function getTransactionName() 
    {
        return ucfirst($this->action) . ucfirst($this->model) . ucfirst($this->cqrs);
    }
    
    public function getContractName() 
    {
        return $this->getTransactionName() . 'Contract';
    }
    
    public function getContractNamespace() 
    {
        $cqrs = ucfirst($this->getCqrsPlural());
        $model = ucfirst($this->model);        
        return "App\\Contracts\\{$cqrs}\\{$model}\\{$this->getContractName()}";
    }

    public function getHandlerName() 
    {
        return $this->getTransactionName() . 'Handler';
    }

    public function getHandlerNamespace() 
    {
        $cqrs = ucfirst($this->getCqrsPlural());
        $model = ucfirst($this->model);        
        return "App\\Handlers\\{$cqrs}\\{$model}\\{$this->getHandlerName()}";
    }
    
    public function getControllerName() 
    {
        return ucfirst($this->model) . 'Controller';
    }

    public function getControllerNamespace() 
    {
        return 'App\\Http\\Controllers\\' . $this->getControllerName();
    }
    
    /**
     * Get the transaction key
     * 
     * @return string
     */
    public function getTransactionKey(): string
    {
        return $this->key;
    }
    
    /**
     * Get the service name
     * 
     * @return string
     */
    public function getService(): string
    {
        return $this->service;
    }
    
    /**
     * Get the CQRS type (command, query, event)
     * 
     * @return string
     */
    public function getCqrs(): string
    {
        return $this->cqrs;
    }

    public function getCqrsPlural(): string
    {
        switch ($this->cqrs) {
            case 'command':
                return 'commands';
            case 'query':
                return 'queries';
            case 'event':
                return 'events';
            default:
                return 'error';
        }
    }

    /**
     * Get the model name
     * 
     * @return string
     */
    public function getModelName(): string
    {
        $modelNameParts = explode('\\', $this->model);
        return end($modelNameParts);
    }

    /**
     * Get the model namespace to create the model model object
     * 
     * @return string
     */
    public function getModelNamespace() 
    {
        return "App\\Models\\{$this->getModelName()}";
    }
    
    public function hasModelId(): bool
    {
        return isset($this->modelId);
    }
    
    public function getModelId(): ?int
    {
        return $this->modelId ?? null;
    }
    
    /**
     * Get the action name
     * 
     * @return string
     */
    public function getActionName(): string
    {
        return $this->action;
    }
}