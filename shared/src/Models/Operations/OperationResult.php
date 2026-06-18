<?php

namespace SynergyERP\Shared\Models\Operations;

use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

/**
 * Represents the result of an operation handler
 * Provides methods to convert the result to different formats
 * and validate the result structure
 */
class OperationResult
{
    protected $handlerResult = [];
    protected $operationKeyContext;

    public function __construct($handlerResult, $operationKeyContext = null)
    {
        $this->handlerResult = $handlerResult;
        
        if ($operationKeyContext instanceof OperationKeyContext) {
            $this->operationKeyContext = $operationKeyContext;
        } elseif (is_string($operationKeyContext)) {
            $this->operationKeyContext = new OperationKeyContext($operationKeyContext);
        }
    }
    
    /**
     * Get the result as a JSON string
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
    
    /**
     * Get the result as an array
     * This is the main method that all other methods will use
     *
     * @return array
     */
    public function toArray(): array
    {
        // Handle JsonResponse objects
        if ($this->handlerResult instanceof JsonResponse) {
            // Get the original data from the JsonResponse
            if (method_exists($this->handlerResult, 'getData')) {
                $data = $this->handlerResult->getData(true); // true to get as array
                
                // If data has 'original' key, use that
                if (is_object($data) && isset($data->original)) {
                    return (array) $data->original;
                } else if (is_array($data) && isset($data['original'])) {
                    return $data['original'];
                }
                
                return (array) $data;
            }
            
            // Fallback to content
            $content = $this->handlerResult->getContent();
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // If decoded content has 'original' key, use that
                if (isset($decoded['original'])) {
                    return $decoded['original'];
                }
                return $decoded;
            }
            
            // If not valid JSON, return as is
            return [$content];
        }
        
        // If already an array, check for 'original' key
        if (is_array($this->handlerResult)) {
            if (isset($this->handlerResult['original'])) {
                return $this->handlerResult['original'];
            }
            return $this->handlerResult;
        }
        
        // If it's a string, try to decode it as JSON
        if (is_string($this->handlerResult)) {
            $decoded = json_decode($this->handlerResult, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // If decoded string has 'original' key, use that
                if (isset($decoded['original'])) {
                    return $decoded['original'];
                }
                return $decoded;
            }
            
            // If not valid JSON, wrap it in an array
            return [$this->handlerResult];
        }
        
        // If it's an object, convert to array
        if (is_object($this->handlerResult)) {
            // Check for 'original' property
            if (property_exists($this->handlerResult, 'original')) {
                return (array) $this->handlerResult->original;
            }
            
            // Check for getOriginal method
            if (method_exists($this->handlerResult, 'getOriginal')) {
                return (array) $this->handlerResult->getOriginal();
            }
            
            // Check for toArray method
            if (method_exists($this->handlerResult, 'toArray')) {
                $array = $this->handlerResult->toArray();
                if (isset($array['original'])) {
                    return $array['original'];
                }
                return $array;
            }
            
            // Cast to array
            $array = (array) $this->handlerResult;
            if (isset($array['original'])) {
                return $array['original'];
            }
            return $array;
        }
        
        // For other types (int, float, bool), wrap in an array
        return [$this->handlerResult];
    }
    
    /**
     * Get the operation key context
     *
     * @return OperationKeyContext|null
     */
    public function getOperationKeyContext(): ?OperationKeyContext
    {
        return $this->operationKeyContext;
    }
}
