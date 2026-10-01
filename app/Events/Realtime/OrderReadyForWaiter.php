<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;

/**
 * Directed "your order is ready" signal (CARTA 7.1A) — sent ONLY to the
 * table session's responsible waiter (see ResponsibleWaiterResolver), on
 * that user's own private channel, for each real preparing -> ready
 * transition (TransitionOrderStatusAction::markReady). After-commit like
 * every RealtimeEvent: a rolled-back transition never sends it.
 *
 * It informs; it is resolved by the existing ready -> served transition —
 * there is no separate acknowledgement. It complements, never replaces,
 * the restaurant-wide `order.status_changed`, the Operations Live
 * `order_ready` alert and the `order.ready` activity entry. Sound/toast
 * are a client decision.
 *
 * Payload is just what "Mesa 07 — Pedido #1842 está pronto" needs.
 */
class OrderReadyForWaiter extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $waiterUserId,
        public readonly int $orderId,
        public readonly int $tableId,
        public readonly string $tableName,
        public readonly int $tableSessionId,
        public readonly Carbon $readyAt,
    ) {
        parent::__construct($restaurantId);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->waiterUserId}")];
    }

    public function broadcastAs(): string
    {
        return 'order.ready.attention';
    }

    protected function payload(): array
    {
        return [
            'order' => ['id' => $this->orderId, 'reference' => sprintf('#%d', $this->orderId)],
            'table' => ['id' => $this->tableId, 'name' => $this->tableName],
            'table_session_id' => $this->tableSessionId,
            'ready_at' => $this->readyAt->toISOString(),
        ];
    }
}
