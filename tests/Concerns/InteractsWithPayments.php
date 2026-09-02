<?php

namespace Tests\Concerns;

use App\Actions\Billing\RecordPaymentAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\TableSession;
use App\Models\User;

/**
 * PaymentRecord deliberately has no factory, same as Order/TableRequest/
 * TableSession: its rows must satisfy cross-model invariants that a blind
 * factory can't safely fake. This helper goes through the real Action
 * instead.
 */
trait InteractsWithPayments
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array{payment: PaymentRecord, replayed: bool}
     */
    protected function recordPayment(
        TableSession $session,
        User $recordedBy,
        string $amount,
        string $method = PaymentRecord::METHOD_CASH,
        array $extra = [],
    ): array {
        return app(RecordPaymentAction::class)->execute($session, $recordedBy, array_merge([
            'method' => $method,
            'amount' => $amount,
        ], $extra));
    }

    /**
     * Make a session's bill fully paid (one served order, paid in full)
     * and close it via the real CloseTableAction — the precondition
     * Bloco 13 requires before any session can close. Used across earlier
     * blocks' tests that just need "a closed session" as setup for an
     * unrelated scenario.
     *
     * Assumes the test class also uses InteractsWithOrders (for
     * createWaiterOrder/advanceOrderTo) and InteractsWithTenants (for
     * createProduct/createRestaurantProduct).
     */
    protected function closeSessionWithFullPayment(TableSession $session, User $actor): TableSession
    {
        $restaurantProduct = $this->createRestaurantProduct(
            $session->restaurant,
            $this->createProduct($session->restaurant->organization),
            10.0,
        );
        $order = $this->createWaiterOrder($session->table, $actor, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $actor);
        $this->recordPayment($session, $actor, $order->total);

        return app(CloseTableAction::class)->execute($session, $actor);
    }
}
