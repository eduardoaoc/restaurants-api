<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by CloseTableAction after a TableSession is committed closed.
 */
class TableSessionClosed extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly Carbon $closedAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'table.session.closed';
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'closed_at' => $this->closedAt->toISOString(),
        ];
    }
}
