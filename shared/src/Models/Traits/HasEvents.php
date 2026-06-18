<?php

namespace SynergyERP\Shared\Models\Traits;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * HasEvents Trait
 * 
 * This trait provides event handling functionality for models.
 * It allows models to dispatch events on create, update, and delete operations.
 */
trait HasEvents
{
    /**
     * The event map for the model.
     *
     * @var array
     */
    protected static $eventMap = [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
    ];

    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootHasEvents()
    {
        foreach (static::$eventMap as $event => $eventClass) {
            static::$event(function ($model) use ($event, $eventClass) {
                $model->dispatchModelEvent($event, $eventClass);
            });
        }
    }

    /**
     * Dispatch a model event.
     *
     * @param string $event
     * @param string $eventClass
     * @return void
     */
    protected function dispatchModelEvent($event, $eventClass)
    {
        $modelName = Str::afterLast(static::class, '\\');
        $eventClassName = "\\SynergyERP\\Shared\\Events\\{$modelName}{$eventClass}Event";
        
        // Only dispatch if the event class exists
        if (class_exists($eventClassName)) {
            Event::dispatch(new $eventClassName($this));
        }
    }

    /**
     * Register a created model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function created($callback)
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register an updated model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function updated($callback)
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a deleted model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function deleted($callback)
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Register a restored model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function restored($callback)
    {
        static::registerModelEvent('restored', $callback);
    }
}
