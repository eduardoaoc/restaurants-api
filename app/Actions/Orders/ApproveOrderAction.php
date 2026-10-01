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
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RestaurantActivityRecorder $activityRecorder,
    ) {}

    public function execute(Order $order, User $approvedBy): Order
    {
        return DB::transaction(function () use ($order, $approvedBy) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActionableCustomerOrder()) {
                throw new OrderStateConflictException('This order cannot be approved.');
            }

            $previousStatus = $locked->status;

            $locked->update([
                'status' => Order::STATUS_CONFIRMED,
                'approved_by_user_id' => $approvedBy->id,
                'approved_at' => now(),
            ]);

            $fresh = $locked->fresh(['items.modifiers', 'restaurant', 'table']);

            $this->auditLogger->log(
                organizationId: $fresh->restaurant->organization_id,
                restaurantId: $fresh->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $approvedBy,
                event: AuditLog::EVENT_ORDER_APPROVED,
                resourceType: AuditLog::RESOURCE_ORDER,
                resourceId: $fresh->id,
                metadata: ['previous_status' => $previousStatus, 'new_status' => Order::STATUS_CONFIRMED],
            );

            $this->activityRecorder->record(
                restaurantId: $fresh->restaurant_id,
                type: RestaurantActivityType::ORDER_APPROVED,
                actor: ActivityActor::staff($approvedBy),
                table: $fresh->table,
                tableSessionId: $fresh->table_session_id,
                order: $fresh,
                occurredAt: $fresh->approved_at,
            );

            OrderStatusChanged::dispatch($fresh->restaurant_id, $fresh->table_id, $fresh->table_session_id, $fresh->id, $previousStatus, Order::STATUS_CONFIRMED, $fresh->approved_at);

            return $fresh;
        });
    }
}
