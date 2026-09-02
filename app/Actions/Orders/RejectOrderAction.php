<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Rejects (cancels) a customer_qr order that is waiting_approval. Same
 * lockForUpdate protection as ApproveOrderAction against a concurrent
 * approve/reject on the same order.
 */
class RejectOrderAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Order $order, User $rejectedBy): Order
    {
        return DB::transaction(function () use ($order, $rejectedBy) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActionableCustomerOrder()) {
                throw new OrderStateConflictException('This order cannot be rejected.');
            }

            $previousStatus = $locked->status;

            $locked->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_by_user_id' => $rejectedBy->id,
                'cancelled_at' => now(),
            ]);

            $fresh = $locked->fresh(['items.modifiers', 'restaurant', 'table']);

            $this->auditLogger->log(
                organizationId: $fresh->restaurant->organization_id,
                restaurantId: $fresh->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $rejectedBy,
                event: AuditLog::EVENT_ORDER_REJECTED,
                resourceType: AuditLog::RESOURCE_ORDER,
                resourceId: $fresh->id,
                metadata: ['previous_status' => $previousStatus, 'new_status' => Order::STATUS_CANCELLED],
            );

            return $fresh;
        });
    }
}
