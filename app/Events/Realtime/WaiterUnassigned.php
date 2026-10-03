<?php

namespace App\Events\Realtime;

/**
 * Dispatched by UnassignWaiterAction (new_waiter_user_id is always null
 * here).
 */
class WaiterUnassigned extends WaiterAssignmentEvent
{
    public function broadcastAs(): string
    {
        return 'table.waiter.unassigned';
    }
}
