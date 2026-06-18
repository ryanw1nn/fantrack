<?php

namespace SynergyERP\Shared\Models\Events;

use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Services\EventBus;
use SynergyERP\Shared\Models\Transactions\OperationKeyContext;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Models\Events\TransactionEvent;

/**
 * SystemEvent
 * 
 * Base abstract class for all system events.
 * Provides common functionality for event handling and dispatching to the event bus.
 * All event classes should extend this class.
 */
abstract class SystemEvent
{
    protected EventBus $eventBus;
    protected TransactionRequest $transactionRequest; 
    protected OperationKeyContext $operationKeyContext;
    protected ?string $eventId = null;
    protected ?string $eventType = null;
        
    public function __construct(TransactionRequest $transactionRequest)
    {
        // extract transaction request data 
        $this->transactionRequest = $transactionRequest;
        $this->operationKeyContext = $transactionRequest->getOperationKeyContext();

        // establish event bus connection
        $this->eventBus = new EventBus(
            env('EVENT_BUS_HOST', 'event-bus'),
            env('EVENT_BUS_PORT', 5672),
            env('EVENT_BUS_USER', 'guest'),
            env('EVENT_BUS_PASSWORD', 'guest')
        );
    }

    /**
     * Get the event output to be dispatched to the event bus
     * This method should be implemented by all child classes
     * 
     * @return array
     */
    abstract public function getOutput(): array;

    /**
     * Get the event output to be dispatched to the event bus
     * This method should be implemented by all child classes
     * 
     * @return array
     */ 
    abstract public function getBusExchange(): string;

    public function getIdempotencyKey(): string
    {
        return $this->transactionRequest->getIdempotencyKey();
    }
    
    /**
     * Get the routing key for the event
     * By default, uses the event type as the routing key
     * Child classes can override this method to provide custom routing logic
     * 
     * @return string
     */
    public function getBusQueue(): string
    {
        return $this->operationKeyContext->getOperation();
    }
    
    /**
     * Dispatch the event to the event bus
     * 
     * @return bool True if the event was successfully dispatched, false otherwise
     */
    public function dispatch(): bool
    {
        try {
            // Get the event output
            $output = $this->getOutput();
                    
            // configure event bus
            $this->eventBus->setup_queue($this->getBusExchange(), $this->getBusQueue());
            
            // Publish the event to the event bus
            $this->eventBus->publish($this->getBusExchange(), $this->getBusQueue(), $output);
            
            // Log the event dispatch
            Log::info('Event dispatched');
            
            return true;
        } catch (\Exception $e) {
            // Log the error
            Log::error('Failed to dispatch event', [
                'routing_key' => $this->getBusQueue(),
                'exchange' => $this->getBusExchange(),
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}