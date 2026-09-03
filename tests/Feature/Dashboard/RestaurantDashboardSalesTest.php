<?php

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\PaymentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * sales.total/average_ticket/sessions_with_payments and payments.by_method are all
 * derived from PaymentRecord — never from Order totals — filtered by
 * recorded_at. See RestaurantDashboardService::sales()/payments().
 */
class RestaurantDashboardSalesTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_sales_and_payment_breakdown_across_multiple_sessions_and_methods(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        // Session A: one order (50.00), paid via two payments (cash 20 + card 30).
        $tableA = $this->createTable($restaurant, 'Mesa A');
        $sessionA = $this->openSession($tableA, $owner);
        $rpA = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 50.0);
        $orderA = $this->createWaiterOrder($tableA, $owner, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);
        $this->advanceOrderTo($orderA, Order::STATUS_SERVED, $owner);
        $this->recordPayment($sessionA, $owner, '20.00', PaymentRecord::METHOD_CASH);
        $this->recordPayment($sessionA, $owner, '30.00', PaymentRecord::METHOD_CARD);

        // Session B: one order (30.00), paid via one "other" payment.
        $tableB = $this->createTable($restaurant, 'Mesa B');
        $sessionB = $this->openSession($tableB, $owner);
        $rpB = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 30.0);
        $orderB = $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);
        $this->advanceOrderTo($orderB, Order::STATUS_SERVED, $owner);
        $this->recordPayment($sessionB, $owner, '30.00', PaymentRecord::METHOD_OTHER);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk();

        $response
            ->assertJsonPath('data.dashboard.sales.total', '80.00')
            ->assertJsonPath('data.dashboard.sales.sessions_with_payments', 2)
            // Multiple payments in the same session count as ONE paid session.
            ->assertJsonPath('data.dashboard.sales.average_ticket', '40.00')
            ->assertJsonPath('data.dashboard.payments.total_records', 3)
            ->assertJsonPath('data.dashboard.payments.by_method.cash.count', 1)
            ->assertJsonPath('data.dashboard.payments.by_method.cash.amount', '20.00')
            ->assertJsonPath('data.dashboard.payments.by_method.card.count', 1)
            ->assertJsonPath('data.dashboard.payments.by_method.card.amount', '30.00')
            ->assertJsonPath('data.dashboard.payments.by_method.other.count', 1)
            ->assertJsonPath('data.dashboard.payments.by_method.other.amount', '30.00');
    }

    public function test_payments_outside_the_period_are_excluded_by_recorded_at(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 20.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $this->travelTo(CarbonImmutable::create(2026, 1, 15));
        $this->recordPayment($session, $owner, '20.00', PaymentRecord::METHOD_CASH);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-02-01&to=2026-02-28")
            ->assertOk()
            ->assertJsonPath('data.dashboard.sales.total', '0.00')
            ->assertJsonPath('data.dashboard.sales.sessions_with_payments', 0)
            ->assertJsonPath('data.dashboard.sales.average_ticket', '0.00')
            ->assertJsonPath('data.dashboard.payments.total_records', 0);
    }

    public function test_empty_period_returns_zeros_and_zero_filled_payment_methods(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.sales.total', '0.00')
            ->assertJsonPath('data.dashboard.sales.average_ticket', '0.00')
            ->assertJsonPath('data.dashboard.sales.sessions_with_payments', 0)
            ->assertJsonPath('data.dashboard.payments.total_records', 0)
            ->assertJsonPath('data.dashboard.payments.by_method.cash', ['count' => 0, 'amount' => '0.00'])
            ->assertJsonPath('data.dashboard.payments.by_method.card', ['count' => 0, 'amount' => '0.00'])
            ->assertJsonPath('data.dashboard.payments.by_method.other', ['count' => 0, 'amount' => '0.00']);
    }
}
