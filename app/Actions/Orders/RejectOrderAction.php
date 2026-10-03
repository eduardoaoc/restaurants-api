<?php

namespace App\Actions\Orders;

use App\Events\Realtime\OrderStatusChanged;
use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Audit\AuditLogger;
use App\Support\Restaurants\RestaurantOperationalLock;
use Illuminate\Support\Facades\DB;

/**
 * Rejects (cancels) a customer_qr order that is waiting_approval. Same
 * lockForUpdate protection as ApproveOrderAction against a concurrent
 * approve/reject on the same order.
 */
class RejectOrderAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RestaurantActivityRecorder $activityRecorder,
    ) {}

    public function execute(Order $order, User $rejectedBy): Order
    {
        return DB::transaction(function () use ($order, $rejectedBy) {
            RestaurantOperationalLock::shared($order->restaurant_id);

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

            $this->activityRecorder->record(
                restaurantId: $fresh->restaurant_id,
                type: RestaurantActivityType::ORDER_REJECTED,
                actor: ActivityActor::staff($rejectedBy),
                table: $fresh->table,
                tableSessionId: $fresh->table_session_id,
                order: $fresh,
                occurredAt: $fresh->cancelled_at,
            );

            OrderStatusChanged::dispatch($fresh->restaurant_id, $fresh->table_id, $fresh->table_session_id, $fresh->id, $previousStatus, Order::STATUS_CANCELLED, $fresh->cancelled_at);

            return $fresh;
        });
    }
}
