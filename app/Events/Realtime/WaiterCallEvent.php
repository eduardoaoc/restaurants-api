<?php

namespace App\Events\Realtime;

/**
 * Shared payload shape for the two WaiterCall events (created/
 * acknowledged) — the internal Manager/Owner -> assigned-waiter
 * escalation (Bloco 4), a domain deliberately separate from TableRequest.
 */
abstract class WaiterCallEvent extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly int $waiterCallId,
        public readonly int $waiterUserId,
        public readonly string $status,
    ) {
        parent::__construct($restaurantId);
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'waiter_call_id' => $this->waiterCallId,
            'waiter_user_id' => $this->waiterUserId,
            'status' => $this->status,
        ];
    }
}
