<?php

namespace App\Events\Realtime;

/**
 * Dispatched by TransferTableSessionAction after a session's table_id is
 * committed to the target table. Structural enough that a frontend is
 * expected to refetch /operations/live rather than patch local state
 * (see docs/realtime.md) — Orders/TableRequests/PaymentRecords also moved
 * with the session but are never copied into this payload.
 */
class TableSessionTransferred extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableSessionId,
        public readonly int $fromTableId,
        public readonly int $toTableId,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'table.session.transferred';
    }

    protected function payload(): array
    {
        return [
            'table_session_id' => $this->tableSessionId,
            'from_table_id' => $this->fromTableId,
            'to_table_id' => $this->toTableId,
        ];
    }
}
