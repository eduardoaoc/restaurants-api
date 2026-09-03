<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Orders\RejectOrderAction;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * orders.created/customer_qr/staff_created are filtered by created_at,
 * served by served_at, cancelled by cancelled_at — see
 * RestaurantDashboardService::orders().
 */
class RestaurantDashboardOrdersTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_created_counts_split_by_origin(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.orders.created', 5)
            ->assertJsonPath('data.dashboard.orders.customer_qr', 2)
            ->assertJsonPath('data.dashboard.orders.staff_created', 3);
    }

    public function test_served_is_filtered_by_served_at_not_created_at(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $this->travelTo(CarbonImmutable::create(2026, 1, 15));
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->travelTo(CarbonImmutable::create(2026, 2, 15));
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-01-01&to=2026-01-31")
            ->assertOk()
            ->assertJsonPath('data.dashboard.orders.created', 1)
            ->assertJsonPath('data.dashboard.orders.served', 0);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-02-01&to=2026-02-28")
            ->assertOk()
            ->assertJsonPath('data.dashboard.orders.created', 0)
            ->assertJsonPath('data.dashboard.orders.served', 1);
    }

    public function test_cancelled_counts_rejected_customer_orders_by_cancelled_at(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $customerOrder = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($customerOrder, $owner);

        // A staff order that is never cancelled must not count.
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.orders.cancelled', 1)
            ->assertJsonPath('data.dashboard.orders.created', 2);
    }
}
