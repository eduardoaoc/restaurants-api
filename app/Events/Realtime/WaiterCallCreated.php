<?php

namespace App\Events\Realtime;

/**
 * Dispatched by CallResponsibleWaiterAction.
 */
class WaiterCallCreated extends WaiterCallEvent
{
    public function broadcastAs(): string
    {
        return 'waiter_call.created';
    }
}
