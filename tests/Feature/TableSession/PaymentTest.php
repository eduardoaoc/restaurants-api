<?php

namespace Tests\Feature\TableSession;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: TableSession, 1: User}
     */
    private function sessionWithBillableOrder(float $price = 100.0): array
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => $price]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return [$session, $owner];
    }

    public function test_content_type_is_json(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertHeader('Content-Type', 'application/json');
    }

    // --- Partial / multiple / complete -----------------------------------

    public function test_partial_payment_is_recorded(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(100.0);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '40.00'])
            ->assertStatus(201);

        $response->assertJson(['data' => ['method' => 'cash', 'amount' => '40.00', 'currency' => 'EUR']]);
        $session->refresh();
        $this->assertSame('unpaid', $session->payment_status);
    }

    public function test_multiple_payments_with_different_methods(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(100.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '40.00'])
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'card', 'amount' => '60.00'])
            ->assertStatus(201);

        $this->assertDatabaseCount('payment_records', 2);
        $session->refresh();
        $this->assertSame('paid', $session->payment_status);
    }

    public function test_payment_completes_the_bill_and_marks_paid_at(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(100.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'card', 'amount' => '100.00'])
            ->assertStatus(201);

        $session->refresh();
        $this->assertSame('paid', $session->payment_status);
        $this->assertNotNull($session->paid_at);
    }

    public function test_payment_before_order_is_served_is_allowed(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(30.0);
        // Order is `confirmed` (waiter order), not yet served.

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '30.00'])
            ->assertStatus(201);

        $session->refresh();
        $this->assertSame('paid', $session->payment_status);
    }

    // --- Overpayment ----------------------------------------------------

    public function test_overpayment_is_rejected(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '30.00'])
            ->assertStatus(422);

        $response->assertJson(['error' => ['code' => 'PAYMENT_EXCEEDS_BALANCE']]);
        $this->assertDatabaseCount('payment_records', 0);
    }

    public function test_overpayment_after_partial_payment_is_rejected(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(50.0);
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '30.00'])
            ->assertStatus(201);

        // balance is now 20.00 — 25.00 must be rejected even though it's
        // less than the original total.
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '25.00'])
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'PAYMENT_EXCEEDS_BALANCE']]);

        $this->assertDatabaseCount('payment_records', 1);
    }

    // --- Validation ---------------------------------------------------

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '0.00'])
            ->assertStatus(422);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '-1.00'])
            ->assertStatus(422);
    }

    public function test_amount_as_json_number_is_rejected(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => 20.00])
            ->assertStatus(422);
    }

    public function test_invalid_method_is_rejected(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'bitcoin', 'amount' => '10.00'])
            ->assertStatus(422);
    }

    public function test_frontend_supplied_context_is_ignored(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", [
                'method' => 'cash',
                'amount' => '20.00',
                'currency' => 'USD',
                'restaurant_id' => 999999,
                'recorded_by_user_id' => 999999,
                'payment_status' => 'paid',
            ])
            ->assertStatus(201);

        $response->assertJson(['data' => ['currency' => 'EUR']]);
        $payment = PaymentRecord::query()->firstOrFail();
        $this->assertSame($session->restaurant_id, $payment->restaurant_id);
        $this->assertSame($owner->id, $payment->recorded_by_user_id);
    }

    // --- No billable orders ----------------------------------------------

    public function test_payment_without_billable_orders_returns_409(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS']]);
    }

    public function test_payment_with_only_waiting_approval_order_returns_409(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS']]);
    }

    // --- Already paid / closed ------------------------------------------

    public function test_payment_on_already_paid_session_returns_409(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '20.00'])
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '5.00', 'reference' => 'X'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);
    }

    public function test_payment_on_closed_session_returns_409(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);
        $order = Order::query()->where('table_session_id', $session->id)->firstOrFail();
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, '20.00');
        app(CloseTableAction::class)->execute($session, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '5.00'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_CLOSED']]);
    }

    // --- Concurrency (sequential stand-in) --------------------------------

    public function test_two_sequential_full_payments_only_the_first_succeeds(): void
    {
        // Sequential stand-in for the concurrent case: both would race for
        // the same lockForUpdate()'d row inside RecordPaymentAction.
        [$session, $owner] = $this->sessionWithBillableOrder(20.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '20.00'])
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '20.00', 'reference' => 'second'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);

        $this->assertDatabaseCount('payment_records', 1);
    }

    // --- Tenant / restaurant scope ----------------------------------------

    public function test_other_organization_payment_returns_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $ownerB);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionB->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(404);
    }

    public function test_other_restaurant_same_organization_payment_returns_404(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $owner);

        $this->actingAs($waiterA, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionB->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(404);
    }

    // --- Permission -----------------------------------------------------

    public function test_kitchen_cannot_record_payments(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(403);
    }

    public function test_cashier_can_record_payments(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        [$session] = $this->sessionWithBillableOrderFor($organization, $restaurant, $owner);

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(201);
    }

    public function test_waiter_can_record_payments(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        [$session] = $this->sessionWithBillableOrderFor($organization, $restaurant, $owner);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(201);
    }

    public function test_owner_can_record_payments_in_any_restaurant_of_their_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        [$sessionA] = $this->sessionWithBillableOrderFor($organization, $restaurantA, $owner);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        [$sessionB] = $this->sessionWithBillableOrderFor($organization, $restaurantB, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionA->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionB->id}/payments", ['method' => 'cash', 'amount' => '10.00'])
            ->assertStatus(201);
    }

    /**
     * @return array{0: TableSession}
     */
    private function sessionWithBillableOrderFor($organization, $restaurant, $owner): array
    {
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 20.0);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return [$session];
    }
}
