<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drives the kitchen/lifecycle state machine:
 *
 *   confirmed -> accepted -> preparing -> ready -> served
 *
 * One shared, parameterized transition instead of four near-identical
 * implementations. Each public method just names its expected starting
 * status, target status, and which audit columns to stamp; the locking,
 * rechecking and persistence logic lives in one place.
 *
 * Same concurrency protection as Approve/RejectOrderAction: the order row
 * is locked (lockForUpdate) and its status rechecked inside the
 * transaction, so two concurrent transitions on the same order can't both
 * succeed — the first to commit wins, the second gets a 409.
 *
 * Does not consult the order's TableSession at all: once created, an
 * order's kitchen lifecycle is independent of whether its session is still
 * open (see report — a session can accumulate/outlive many orders).
 */
class TransitionOrderStatusAction
{
    public function accept(Order $order, User $actor): Order
    {
        return $this->transition($order, Order::STATUS_CONFIRMED, Order::STATUS_ACCEPTED, 'accepted', $actor);
    }

    public function startPreparing(Order $order, User $actor): Order
    {
        return $this->transition($order, Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, 'preparing', $actor);
    }

    public function markReady(Order $order, User $actor): Order
    {
        return $this->transition($order, Order::STATUS_PREPARING, Order::STATUS_READY, 'ready', $actor);
    }

    public function serve(Order $order, User $actor): Order
    {
        return $this->transition($order, Order::STATUS_READY, Order::STATUS_SERVED, 'served', $actor);
    }

    private function transition(Order $order, string $expectedFrom, string $to, string $auditFieldPrefix, User $actor): Order
    {
        return DB::transaction(function () use ($order, $expectedFrom, $to, $auditFieldPrefix, $actor) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== $expectedFrom) {
                throw new OrderStateConflictException("This order cannot transition to '{$to}'.");
            }

            $locked->update([
                'status' => $to,
                "{$auditFieldPrefix}_by_user_id" => $actor->id,
                "{$auditFieldPrefix}_at" => now(),
            ]);

            return $locked->fresh(['items.modifiers', 'restaurant', 'table']);
        });
    }
}
