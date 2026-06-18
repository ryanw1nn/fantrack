<?php

namespace SynergyERP\Shared\Contracts\Events;

use SynergyERP\Shared\Events\SystemEvent;

/**
 * EventHandlerInterface
 * 
 * Interface for event handlers that process system events
 */
interface EventHandlerInterface
{
    /**
     * Handle the event
     * 
     * @param SystemEvent $event The event to handle
     * @return bool True if the event was successfully handled, false otherwise
     */
    public function handle(SystemEvent $event): bool;
}
