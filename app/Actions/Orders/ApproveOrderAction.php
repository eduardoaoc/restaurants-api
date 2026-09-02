<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approves a customer_qr order that is waiting_approval. Locks the order
 * row (lockForUpdate) inside the transaction so two concurrent approvals
 * can't both succeed: the first to acquire the lock wins and transitions
 * the order; the second sees the already-updated status and is rejected
 * with a 409, never a double transition.
 */
class ApproveOrderAction
{
    public function execute(Order $order, User $approvedBy): Order
    {
        return DB::transaction(function () use ($order, $approvedBy) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActionableCustomerOrder()) {
                throw new OrderStateConflictException('This order cannot be approved.');
            }

            $locked->update([
                'status' => Order::STATUS_CONFIRMED,
                'approved_by_user_id' => $approvedBy->id,
                'approved_at' => now(),
            ]);

            return $locked->fresh(['items.modifiers', 'restaurant', 'table']);
        });
    }
}
