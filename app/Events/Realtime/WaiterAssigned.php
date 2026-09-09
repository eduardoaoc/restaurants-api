<?php

namespace App\Events\Realtime;

/**
 * Dispatched by AssignWaiterAction when a session had no previous waiter.
 */
class WaiterAssigned extends WaiterAssignmentEvent
{
    public function broadcastAs(): string
    {
        return 'table.waiter.assigned';
    }
}
