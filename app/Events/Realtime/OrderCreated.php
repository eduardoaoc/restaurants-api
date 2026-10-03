<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by OrderCreationService after an Order (any origin) is
 * committed. Deliberately omits OrderItems — a KDS/waiter client that
 * needs the detail fetches the Order through its own canonical endpoint.
 */
class OrderCreated extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly int $orderId,
        public readonly string $origin,
        public readonly string $status,
        public readonly Carbon $createdAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'order_id' => $this->orderId,
            'origin' => $this->origin,
            'status' => $this->status,
            'created_at' => $this->createdAt->toISOString(),
        ];
    }
}
