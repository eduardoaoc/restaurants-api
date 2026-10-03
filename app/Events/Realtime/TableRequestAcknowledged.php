<?php

namespace App\Events\Realtime;

/**
 * Dispatched by TransitionTableRequestStatusAction::acknowledge().
 */
class TableRequestAcknowledged extends TableRequestEvent
{
    public function broadcastAs(): string
    {
        return 'table_request.acknowledged';
    }
}
