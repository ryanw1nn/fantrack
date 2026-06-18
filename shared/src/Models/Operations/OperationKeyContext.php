<?php

namespace SynergyERP\Shared\Models\Operations;

use Illuminate\Support\{
    Facades\Log,
    Facades\Env
};

/**
 * Base CommandHandler class for all command handlers in the system
 * Implements common functionality for command processing in CQRS pattern
 */
class OperationKeyContext
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
     * Create an OperationKeyContext from an operation key
     * 
     * @param string $operationKey The operation key
     * @return self The OperationKeyContext
     */
    public static function fromOperationKey(string $operationKey): self
    {
        // Check if the operation key contains a model ID (has a colon)
        $parts = explode(':', $operationKey);
        
        if (count($parts) === 1) {
            // No model ID in the operation key
            // Format: <service-name>.<cqrs>.<model-name>.<action-name>
            $instance = new self($operationKey);
            return $instance;
        } else if (count($parts) === 2) {
            // Has model ID in the operation key
            // Format: <service-name>.<cqrs>.<model-name>.<action-name>:<model-id>
            
            // Validate model id
            if (!is_numeric($parts[1])) {
                throw new \Exception('Invalid model id');
            }
            
            // Create instance
            $instance = new self($parts[0]);
            $instance->modelId = (int)$parts[1];
            return $instance;
        } else {
            throw new \Exception('Invalid operation key format');
        }
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
            throw new \Exception('Invalid operation key');
        }
                
        // explode input by dot
        $parts_by_dot = explode('.', $input);
        
        // if not enough parts
        if (count($parts_by_dot) < 4) {
            throw new \Exception('Invalid operation key: not enough parts');
        }
        else if (count($parts_by_dot) > 4) {
            throw new \Exception('Invalid operation key: too many parts');
        }

        // set properties from operation key
        $this->key = $input;
        $this->setPropertiesFromOperationKey($parts_by_dot);
    }

    private function setPropertiesFromOperationKey($parts)
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
     * Get the operation key including model ID if available
     * Format: <service-name>.<cqrs>.<model-name>.<action-name>[:<model-id>]
     * 
     * @return string
     */
    public function getOperationKey(): string
    {
        $baseKey = "{$this->service}.{$this->cqrs}.{$this->model}.{$this->action}";
        
        if ($this->hasModelId()) {
            return "{$baseKey}:{$this->modelId}";
        }
        
        return $baseKey;
    }
    
    /**
     * Get the operation key excluding model ID
     * Format: <service-name>.<cqrs>.<model-name>.<action-name>
     * 
     * @return string
     */
    public function getOperation(): string
    {
        return "{$this->service}.{$this->cqrs}.{$this->model}.{$this->action}";
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
     * Get the EventBus exchange and routing key for this operation
     * 
     * @return array Returns ['exchange' => string, 'routing_key' => string]
     */
    public function getEventBusRoute(): array
    {
        // Exchange follows the pattern {cqrs}.exchange (e.g., command.exchange, query.exchange)
        $exchange = "{$this->cqrs}.exchange";
        
        // Routing key is the operation key without the model ID at the end
        $routingKey = "{$this->service}.{$this->cqrs}.{$this->model}.{$this->action}";
        
        return [
            'exchange' => $exchange,
            'routing_key' => $routingKey
        ];
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

    /**
     * Get a specific component of the operation key
     * Valid components: service, cqrs, model, action, id
     *
     * @param string $component The component to get (service, cqrs, model, action, id)
     * @return string|int|null The component value or null if not found
     * @throws \Exception If the component is invalid
     */
    public function getOperationComponent(string $component)
    {
        switch ($component) {
            case 'service':
                return $this->getService();
            case 'cqrs':
                return $this->getCqrs();
            case 'model':
                return $this->getModelName();
            case 'action':
                return $this->getActionName();
            case 'id':
                return $this->getModelId();
            default:
                throw new \Exception("Invalid operation component: {$component}. Valid components are: service, cqrs, model, action, id");
        }
    }
    
    /**
     * @deprecated Use getOperationKey() instead
     */
    public function getTransactionKey(): string
    {
        return $this->getOperationKey();
    }
}
