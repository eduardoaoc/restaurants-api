<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rejects (cancels) a customer_qr order that is waiting_approval. Same
 * lockForUpdate protection as ApproveOrderAction against a concurrent
 * approve/reject on the same order.
 */
class RejectOrderAction
{
    public function execute(Order $order, User $rejectedBy): Order
    {
        return DB::transaction(function () use ($order, $rejectedBy) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActionableCustomerOrder()) {
                throw new OrderStateConflictException('This order cannot be rejected.');
            }

            $locked->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_by_user_id' => $rejectedBy->id,
                'cancelled_at' => now(),
            ]);

            return $locked->fresh(['items.modifiers', 'restaurant', 'table']);
        });
    }
}
