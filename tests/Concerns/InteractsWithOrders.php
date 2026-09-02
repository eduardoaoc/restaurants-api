<?php

namespace Tests\Concerns;

use App\Actions\Orders\CreatePublicOrderAction;
use App\Actions\Orders\CreateStaffOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Actions\Tables\OpenTableAction;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use InvalidArgumentException;

/**
 * Order/OrderItem/OrderItemModifier deliberately have no factories, same as
 * TableSession: their rows must satisfy cross-model invariants (order's
 * restaurant/table/session coherence, snapshot pricing) that a blind
 * factory can't safely fake. Every helper here goes through the real
 * Actions instead, exactly like InteractsWithTenants does for
 * Category/Product/ModifierGroup/ModifierOption.
 */
trait InteractsWithOrders
{
    /**
     * Open a session for a table via the real action.
     */
    protected function openSession(Table $table, User $openedBy, int $guestCount = 2): TableSession
    {
        return app(OpenTableAction::class)->execute($table, $openedBy, $guestCount);
    }

    /**
     * Create an order via the public (customer_qr) flow, exactly as the
     * public endpoint would.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function createCustomerOrder(Table $table, array $items, array $extra = []): Order
    {
        $locale = $extra['locale'] ?? 'es';
        unset($extra['locale']);

        $result = app(CreatePublicOrderAction::class)->execute(
            $table->public_token,
            array_merge(['items' => $items], $extra),
            $locale,
        );

        return $result['order'];
    }

    /**
     * Create an order via the waiter (authenticated) flow.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function createWaiterOrder(Table $table, User $waiter, array $items, array $extra = []): Order
    {
        return app(CreateStaffOrderAction::class)->execute(
            $table,
            $waiter,
            array_merge(['items' => $items], $extra),
        );
    }

    /**
     * Drive a `confirmed` order through the kitchen/lifecycle state machine
     * up to (and including) $targetStatus, via the real
     * TransitionOrderStatusAction — the same code the endpoints use.
     *
     * $servedBy lets the final ready->served step be stamped by a
     * different actor (a waiter) than the kitchen steps, without ever
     * going through actingAs()+HTTP for more than one user in a test — see
     * OrderLifecycleTransitionTest for why that matters here.
     */
    protected function advanceOrderTo(Order $order, string $targetStatus, User $actor, ?User $servedBy = null): Order
    {
        $chain = [
            Order::STATUS_ACCEPTED => 'accept',
            Order::STATUS_PREPARING => 'startPreparing',
            Order::STATUS_READY => 'markReady',
            Order::STATUS_SERVED => 'serve',
        ];

        $statuses = array_keys($chain);
        $targetIndex = array_search($targetStatus, $statuses, true);

        if ($targetIndex === false) {
            throw new InvalidArgumentException("Unsupported target status: {$targetStatus}");
        }

        $action = app(TransitionOrderStatusAction::class);

        foreach (array_slice($statuses, 0, $targetIndex + 1) as $status) {
            $stepActor = ($status === Order::STATUS_SERVED && $servedBy) ? $servedBy : $actor;
            $order = $action->{$chain[$status]}($order, $stepActor);
        }

        return $order;
    }
}
