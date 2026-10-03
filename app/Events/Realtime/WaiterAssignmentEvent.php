<?php

namespace App\Events\Realtime;

/**
 * Shared payload shape for the three waiter-assignment events dispatched
 * by AssignWaiterAction/UnassignWaiterAction — only broadcastAs() differs
 * per concrete subclass (assigned/reassigned/unassigned).
 */
abstract class WaiterAssignmentEvent extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableSessionId,
        public readonly int $tableId,
        public readonly ?int $previousWaiterUserId,
        public readonly ?int $newWaiterUserId,
    ) {
        parent::__construct($restaurantId);
    }

    protected function payload(): array
    {
        return [
            'table_session_id' => $this->tableSessionId,
            'table_id' => $this->tableId,
            'previous_waiter_user_id' => $this->previousWaiterUserId,
            'new_waiter_user_id' => $this->newWaiterUserId,
        ];
    }
}
