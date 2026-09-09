<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by OpenTableAction after a new TableSession is committed.
 */
class TableSessionOpened extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly int $guestCount,
        public readonly Carbon $openedAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'table.session.opened';
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'guest_count' => $this->guestCount,
            'opened_at' => $this->openedAt->toISOString(),
        ];
    }
}
