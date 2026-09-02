<?php

namespace Tests\Concerns;

use App\Actions\Orders\CreatePublicOrderAction;
use App\Actions\Orders\CreateStaffOrderAction;
use App\Actions\Tables\OpenTableAction;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;

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
}
