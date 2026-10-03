<?php

namespace App\Events\Realtime;

/**
 * Dispatched by AcknowledgeWaiterCallAction.
 */
class WaiterCallAcknowledged extends WaiterCallEvent
{
    public function broadcastAs(): string
    {
        return 'waiter_call.acknowledged';
    }
}
