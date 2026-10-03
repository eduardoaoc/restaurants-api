<?php

namespace App\Events\Realtime;

/**
 * Dispatched by CreatePublicTableRequestAction.
 */
class TableRequestCreated extends TableRequestEvent
{
    public function broadcastAs(): string
    {
        return 'table_request.created';
    }
}
