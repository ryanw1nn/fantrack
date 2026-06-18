<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;

class ReferenceResolver
{
    /**
     * Resolve references in data.
     *
     * @param string $schema The tenant schema to use
     * @param array $data Array of data items to process
     * @param array $ignoreProperties Properties to ignore when resolving references
     * @param array &$references Output parameter to store resolved references
     * @return array The resolved references
     */
    public static function resolve($schema, $data, $ignoreProperties = [], &$references = [])
    {
        // First pass: collect all models and their IDs
        self::collectReferences($schema, $data, $ignoreProperties, $references);
        
        // Second pass: resolve all collected references until no new ones are found
        $previousCount = 0;
        $currentCount = count($references);
        
        // Continue resolving until we don't find any new references
        while ($currentCount > $previousCount) {
            $previousCount = $currentCount;
            self::resolveReferences($schema, $ignoreProperties, $references);
            $currentCount = count($references);
        }
        
        return $references;
    }
    
    /**
     * First pass: Collect all models and their potential references
     */
    protected static function collectReferences($schema, $data, $ignoreProperties, &$references)
    {
        foreach ($data as $item) {
            // Handle Eloquent models
            if ($item instanceof Model) {
                $attributes = $item->getAttributes();
                $modelClass = get_class($item);
                $modelName = class_basename($modelClass);
                $modelKey = $modelName . ':' . $item->getKey();
                
                // Add model to references if not already there
                if (!isset($references[$modelKey])) {
                    $references[$modelKey] = $attributes;
                }
                
                // Process model attributes for potential references
                self::processAttributes($attributes, $ignoreProperties, $modelClass);
            } 
            // Handle array data (could be model data converted to array)
            elseif (is_array($item)) {
                self::processAttributes($item, $ignoreProperties);
            }
        }
    }
    
    /**
     * Process attributes to find potential references
     */
    protected static function processAttributes($attributes, $ignoreProperties, $parentModelClass = null)
    {
        // Collect all potential reference IDs
        $referencesToResolve = [];
        
        foreach ($attributes as $property => $value) {
            if (in_array($property, $ignoreProperties)) {
                continue;
            }
            
            // Find all properties ending with _id that have non-null values
            if (Str::endsWith($property, '_id') && !is_null($value) && $value !== 0 && $value !== '') {
                $modelClass = self::guessModelClass($property, $parentModelClass);
                $referencesToResolve[$property] = [
                    'model_class' => $modelClass,
                    'id' => $value
                ];
            }
        }
        
        return $referencesToResolve;
    }
    
    /**
     * Resolve all collected references
     */
    protected static function resolveReferences($schema, $ignoreProperties, &$references)
    {
        $newReferencesToResolve = [];
        
        // Process each reference to find nested references
        foreach ($references as $referenceKey => $attributes) {
            foreach ($attributes as $property => $value) {
                if (in_array($property, $ignoreProperties)) {
                    continue;
                }
                
                // Find properties ending with _id
                if (Str::endsWith($property, '_id') && !is_null($value) && $value !== 0 && $value !== '') {
                    // Extract class name from the reference key or guess from property
                    $parts = explode(':', $referenceKey);
                    $parentModelClass = null;
                    if (count($parts) > 0) {
                        $className = $parts[0];
                        $parentModelClass = 'App\\Models\\' . $className;
                    }
                    
                    $modelClass = self::guessModelClass($property, $parentModelClass);
                    $newReferenceKey = class_basename($modelClass) . ':' . $value;
                    
                    // Only process if we haven't already resolved this reference
                    if (!isset($references[$newReferenceKey]) && self::isLocalModel($modelClass) && class_exists($modelClass)) {
                        try {
                            // Create instance and set schema
                            $instance = new $modelClass();
                            if ($schema && method_exists($instance, 'setSchema')) {
                                $instance->setSchema($schema);
                            }
                            
                            // Find the model
                            $model = $instance->find($value);
                            if ($model) {
                                // Add to references
                                $references[$newReferenceKey] = $model->getAttributes();
                                $newReferencesToResolve[] = $model;
                            }
                        } catch (\Exception $e) {
                            Log::warning("Failed to resolve reference {$newReferenceKey}: {$e->getMessage()}");
                        }
                    }
                }
            }
        }
        
        // Process any new references we found
        if (!empty($newReferencesToResolve)) {
            self::collectReferences($schema, $newReferencesToResolve, $ignoreProperties, $references);
        }
    }

    /**
     * Guess the related model class from a foreign key property.
     */
    protected static function guessModelClass($property, $parentModelClass = null)
    {
        // Convert property name to model class name (e.g., project_status_id => ProjectStatus)
        $base = Str::studly(str_replace('_id', '', $property));
        
        // Try to infer namespace from parent model if possible
        if ($parentModelClass && Str::startsWith($parentModelClass, 'App\\Models\\')) {
            return 'App\\Models\\' . $base;
        }
        
        // Fallback to default namespace
        return 'App\\Models\\' . $base;
    }

    /**
     * Check if a model class is local to this application.
     */
    protected static function isLocalModel($modelClass)
    {
        // Models in the App namespace are considered local
        return Str::startsWith($modelClass, 'App\\');
    }
}
