<?php

namespace App\Actions\Orders;

use App\Exceptions\Billing\TableSessionAlreadyPaidException;
use App\Exceptions\Orders\OrderCreationConflictException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for creating an order, used by both the public
 * (customer_qr) and staff (waiter) endpoints so their rules never diverge
 * (see BuildOrderItemsAction for the shared item validation/pricing).
 *
 * Runs inside one DB transaction and re-locks+rechecks the table session
 * (SELECT ... FOR UPDATE via lockForUpdate()) instead of trusting the
 * already-loaded session passed in: the session may have been closed by a
 * concurrent request between when the caller resolved it and this
 * transaction actually running. If a concurrent CloseTableAction is mid
 * commit, this lock simply waits for it — whichever transaction commits
 * first is the one that wins; the other observes the up-to-date row.
 */
class OrderCreationService
{
    public function __construct(
        private readonly BuildOrderItemsAction $buildOrderItems,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function execute(
        Table $table,
        int $tableSessionId,
        string $origin,
        ?User $createdBy,
        string $locale,
        array $items,
        ?string $customerName = null,
        ?string $customerNote = null,
        ?string $idempotencyKey = null,
        ?string $idempotencyPayloadHash = null,
    ): Order {
        return DB::transaction(function () use (
            $table, $tableSessionId, $origin, $createdBy, $locale, $items,
            $customerName, $customerNote, $idempotencyKey, $idempotencyPayloadHash,
        ) {
            $session = TableSession::query()->whereKey($tableSessionId)->lockForUpdate()->first();

            if (! $session || ! $session->isActive()) {
                throw new OrderCreationConflictException('The table session is no longer active.');
            }

            if ($session->isPaid()) {
                throw new TableSessionAlreadyPaidException;
            }

            $built = $this->buildOrderItems->execute($table->restaurant, $items, $locale);

            $status = $origin === Order::ORIGIN_WAITER ? Order::STATUS_CONFIRMED : Order::STATUS_WAITING_APPROVAL;
            $totalCents = $built['subtotalCents'] + $built['modifiersTotalCents'];

            $order = Order::query()->create([
                'restaurant_id' => $table->restaurant_id,
                'table_id' => $table->id,
                'table_session_id' => $session->id,
                'origin' => $origin,
                'created_by_user_id' => $createdBy?->id,
                'customer_name' => $customerName,
                'status' => $status,
                'subtotal' => Money::centsToDecimal($built['subtotalCents']),
                'modifiers_total' => Money::centsToDecimal($built['modifiersTotalCents']),
                'total' => Money::centsToDecimal($totalCents),
                'customer_note' => $customerNote,
                'idempotency_key' => $idempotencyKey,
                'idempotency_payload_hash' => $idempotencyPayloadHash,
            ]);

            foreach ($built['itemSpecs'] as $spec) {
                $orderItem = $order->items()->create([
                    'restaurant_product_id' => $spec['restaurant_product_id'],
                    'product_id' => $spec['product_id'],
                    'product_name_snapshot' => $spec['product_name_snapshot'],
                    'product_description_snapshot' => $spec['product_description_snapshot'],
                    'unit_price_snapshot' => $spec['unit_price_snapshot'],
                    'quantity' => $spec['quantity'],
                    'modifiers_unit_total_snapshot' => $spec['modifiers_unit_total_snapshot'],
                    'unit_total_snapshot' => $spec['unit_total_snapshot'],
                    'line_total_snapshot' => $spec['line_total_snapshot'],
                    'customer_note' => $spec['customer_note'],
                ]);

                foreach ($spec['modifiers'] as $modifierSpec) {
                    $orderItem->modifiers()->create($modifierSpec);
                }
            }

            $this->auditLogger->log(
                organizationId: $table->restaurant->organization_id,
                restaurantId: $table->restaurant_id,
                actorType: $createdBy ? AuditLog::ACTOR_USER : AuditLog::ACTOR_PUBLIC,
                actor: $createdBy,
                event: AuditLog::EVENT_ORDER_CREATED,
                resourceType: AuditLog::RESOURCE_ORDER,
                resourceId: $order->id,
                metadata: ['origin' => $origin],
            );

            return $order->load('items.modifiers');
        });
    }
}
