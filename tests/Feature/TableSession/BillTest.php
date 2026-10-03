<?php

namespace Tests\Feature\TableSession;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class BillTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_content_type_is_json(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertHeader('Content-Type', 'application/json');
    }

    // --- Totals ---------------------------------------------------------

    public function test_orders_total_sums_only_billable_orders(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $productA = $this->createProduct($organization);
        $rpA = $this->createRestaurantProduct($restaurant, $productA, 20.0);
        $orderA = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);
        $this->advanceOrderTo($orderA, Order::STATUS_SERVED, $kitchen);

        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurant, $productB, 30.0);
        $orderB = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);
        $this->advanceOrderTo($orderB, Order::STATUS_SERVED, $kitchen);

        $productC = $this->createProduct($organization);
        $rpC = $this->createRestaurantProduct($restaurant, $productC, 50.0);
        $orderC = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rpC->id, 'quantity' => 1]]);
        // Only a waiting_approval customer_qr order can go through
        // RejectOrderAction; force the status directly here purely to set
        // up a cancelled order for this total calculation test.
        $orderC->forceFill(['status' => Order::STATUS_CANCELLED])->save();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertSame('50.00', $response->json('data.orders_total'));
    }

    public function test_waiting_approval_order_is_excluded_from_the_total(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertSame('0.00', $response->json('data.orders_total'));
    }

    public function test_active_non_served_order_counts_towards_total_but_blocks_close(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 20.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_PREPARING, $kitchen);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertSame('20.00', $response->json('data.orders_total'));
        $this->assertFalse($response->json('data.can_close'));
    }

    // --- Payment tracking -------------------------------------------

    public function test_partial_payment_reflects_in_bill(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 100.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen);
        $this->recordPayment($session, $owner, '40.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertSame('100.00', $response->json('data.orders_total'));
        $this->assertSame('40.00', $response->json('data.paid_total'));
        $this->assertSame('60.00', $response->json('data.balance'));
        $this->assertSame('unpaid', $response->json('data.payment_status'));
    }

    public function test_full_payment_marks_session_paid(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 100.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen);
        $this->recordPayment($session, $owner, '40.00');
        $this->recordPayment($session, $owner, '60.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertSame('0.00', $response->json('data.balance'));
        $this->assertSame('paid', $response->json('data.payment_status'));

        $session->refresh();
        $this->assertNotNull($session->paid_at);
    }

    public function test_can_close_true_only_when_paid_served_and_no_open_orders(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 30.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen);
        $this->recordPayment($session, $owner, '30.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertTrue($response->json('data.can_close'));
    }

    // --- Monetary contract ----------------------------------------------

    public function test_monetary_fields_are_strings_with_two_decimals(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertIsString($response->json('data.orders_total'));
        $this->assertIsString($response->json('data.paid_total'));
        $this->assertIsString($response->json('data.balance'));
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $response->json('data.orders_total'));
    }

    // --- Resource contract ------------------------------------------

    public function test_bill_resource_contract(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant, 'Mesa 12', 12);
        $session = $this->openSession($table, $owner);
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->recordPayment($session, $owner, '5.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $json = $response->json('data');
        $this->assertEqualsCanonicalizing([
            'table_session_id', 'status', 'payment_status', 'table',
            'orders_total', 'paid_total', 'balance', 'can_close', 'orders', 'payments',
        ], array_keys($json));

        $this->assertSame($session->id, $json['table_session_id']);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($json['table']));
        $this->assertCount(1, $json['orders']);
        $this->assertEqualsCanonicalizing(['id', 'status', 'total', 'created_at'], array_keys($json['orders'][0]));
        $this->assertCount(1, $json['payments']);
        $this->assertEqualsCanonicalizing(
            ['id', 'method', 'amount', 'currency', 'reference', 'note', 'recorded_at', 'recorded_by'],
            array_keys($json['payments'][0])
        );
        $this->assertSame($owner->id, $json['payments'][0]['recorded_by']['id']);
    }

    // --- Available for historical (closed) sessions ----------------------

    public function test_bill_still_available_after_the_session_is_closed(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 15.0);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen);
        $this->recordPayment($session, $owner, '15.00');
        $this->closeSessionWithoutExtraOrder($session, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk();

        $this->assertSame('closed', $response->json('data.status'));
        $this->assertSame('paid', $response->json('data.payment_status'));
        $this->assertSame('0.00', $response->json('data.balance'));
    }

    private function closeSessionWithoutExtraOrder(TableSession $session, User $actor): void
    {
        app(CloseTableAction::class)->execute($session, $actor);
    }

    // --- Scope / permission ------------------------------------------

    public function test_other_organization_bill_returns_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $ownerB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/table-sessions/{$sessionB->id}/bill")
            ->assertStatus(404);
    }

    public function test_other_restaurant_same_organization_bill_returns_404(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $owner);

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/table-sessions/{$sessionB->id}/bill")
            ->assertStatus(404);
    }

    public function test_kitchen_cannot_view_bill(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertStatus(403);
    }
}
