<?php

namespace App\Events\Realtime;

/**
 * Dispatched by AssignWaiterAction when a session already had a different
 * waiter assigned (previous_waiter_user_id is never null here).
 */
class WaiterReassigned extends WaiterAssignmentEvent
{
    public function broadcastAs(): string
    {
        return 'table.waiter.reassigned';
    }
}
