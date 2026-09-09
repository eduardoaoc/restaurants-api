<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by every Action that transitions an Order's status
 * (ApproveOrderAction, RejectOrderAction, TransitionOrderStatusAction) —
 * one event per actual transition, never duplicated across Actions.
 */
class OrderStatusChanged extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly int $orderId,
        public readonly string $previousStatus,
        public readonly string $status,
        public readonly Carbon $changedAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'order.status_changed';
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'order_id' => $this->orderId,
            'previous_status' => $this->previousStatus,
            'status' => $this->status,
            'changed_at' => $this->changedAt->toISOString(),
        ];
    }
}
