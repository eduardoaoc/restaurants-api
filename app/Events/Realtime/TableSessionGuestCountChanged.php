<?php

namespace App\Events\Realtime;

/** Dispatched after an active session's authoritative party size changes. */
class TableSessionGuestCountChanged extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly int $previousGuestCount,
        public readonly int $guestCount,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'table.session.guest_count_changed';
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'previous_guest_count' => $this->previousGuestCount,
            'guest_count' => $this->guestCount,
        ];
    }
}
