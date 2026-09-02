<?php

namespace Tests\Feature\Kitchen;

use App\Actions\Orders\RejectOrderAction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OrderLifecycleTransitionTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: Order, 1: Organization, 2: Restaurant, 3: User, 4: User}
     */
    private function confirmedOrder(): array
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return [$order, $organization, $restaurant, $kitchen, $waiter];
    }

    // --- accept ------------------------------------------------------

    public function test_kitchen_accepts_a_confirmed_order(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();

        $response = $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertOk();

        $response->assertJson(['data' => ['order' => ['status' => 'accepted']]]);

        $order->refresh();
        $this->assertSame(Order::STATUS_ACCEPTED, $order->status);
        $this->assertSame($kitchen->id, $order->accepted_by_user_id);
        $this->assertNotNull($order->accepted_at);
    }

    public function test_accept_without_permission_is_forbidden(): void
    {
        [$order, $organization, $restaurant] = $this->confirmedOrder();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertStatus(403);
    }

    public function test_accept_cross_tenant_returns_404(): void
    {
        [$order] = $this->confirmedOrder();
        [, $otherOwner] = $this->createTenant();

        $this->actingAs($otherOwner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertStatus(404);
    }

    public function test_accept_cross_restaurant_scope_returns_404(): void
    {
        [$order, $organization] = $this->confirmedOrder();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $otherKitchen = $this->createStaff($organization, $otherRestaurant, 'kitchen', 'K-OTHER');

        $this->actingAs($otherKitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertStatus(404);
    }

    public function test_accept_waiting_approval_order_returns_409(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertStatus(409);
    }

    public function test_accept_already_accepted_returns_409(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->actingAs($kitchen, 'web')->postJson("/api/v1/orders/{$order->id}/accept")->assertOk();

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertStatus(409);
    }

    // --- preparing -----------------------------------------------------

    public function test_kitchen_starts_preparing_an_accepted_order(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_ACCEPTED, $kitchen);

        $response = $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/preparing")
            ->assertOk();

        $response->assertJson(['data' => ['order' => ['status' => 'preparing']]]);

        $order->refresh();
        $this->assertSame($kitchen->id, $order->preparing_by_user_id);
        $this->assertNotNull($order->preparing_at);
    }

    public function test_preparing_without_permission_is_forbidden(): void
    {
        [$order, $organization, $restaurant, $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_ACCEPTED, $kitchen);
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/orders/{$order->id}/preparing")
            ->assertStatus(403);
    }

    public function test_preparing_cross_restaurant_returns_404(): void
    {
        [$order, $organization, , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_ACCEPTED, $kitchen);
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $otherKitchen = $this->createStaff($organization, $otherRestaurant, 'kitchen', 'K-OTHER');

        $this->actingAs($otherKitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/preparing")
            ->assertStatus(404);
    }

    public function test_preparing_confirmed_order_returns_409(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/preparing")
            ->assertStatus(409);
    }

    // --- ready --------------------------------------------------------

    public function test_kitchen_marks_a_preparing_order_ready(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_PREPARING, $kitchen);

        $response = $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/ready")
            ->assertOk();

        $response->assertJson(['data' => ['order' => ['status' => 'ready']]]);

        $order->refresh();
        $this->assertSame($kitchen->id, $order->ready_by_user_id);
        $this->assertNotNull($order->ready_at);
    }

    public function test_ready_tenant_isolation(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_PREPARING, $kitchen);
        [, $otherOwner] = $this->createTenant();

        $this->actingAs($otherOwner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/ready")
            ->assertStatus(404);
    }

    public function test_ready_restaurant_isolation(): void
    {
        [$order, $organization, , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_PREPARING, $kitchen);
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $otherKitchen = $this->createStaff($organization, $otherRestaurant, 'kitchen', 'K-OTHER');

        $this->actingAs($otherKitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/ready")
            ->assertStatus(404);
    }

    public function test_ready_from_accepted_returns_409(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_ACCEPTED, $kitchen);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/ready")
            ->assertStatus(409);
    }

    // --- served ---------------------------------------------------------

    public function test_waiter_serves_a_ready_order(): void
    {
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_READY, $kitchen);

        $response = $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/served")
            ->assertOk();

        $response->assertJson(['data' => ['order' => ['status' => 'served']]]);

        $order->refresh();
        $this->assertSame($waiter->id, $order->served_by_user_id);
        $this->assertNotNull($order->served_at);
    }

    public function test_kitchen_without_serve_orders_permission_cannot_serve(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_READY, $kitchen);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/served")
            ->assertStatus(403);
    }

    public function test_waiter_with_permission_can_serve(): void
    {
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_READY, $kitchen);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/served")
            ->assertOk();
    }

    public function test_serving_twice_returns_409(): void
    {
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_READY, $kitchen);
        $this->actingAs($waiter, 'web')->postJson("/api/v1/orders/{$order->id}/served")->assertOk();

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/served")
            ->assertStatus(409);
    }

    // --- full state machine ----------------------------------------------

    public function test_full_lifecycle_chain_stamps_every_actor_and_timestamp(): void
    {
        // Kitchen's three steps go through the real action directly rather
        // than HTTP + actingAs(): switching actingAs() to a different user
        // mid-test breaks auth on the next request in this environment (see
        // report), so only the final, waiter-performed step below goes
        // through the actual HTTP endpoint — every step still runs through
        // the exact same TransitionOrderStatusAction the controller calls.
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();
        $order = $this->advanceOrderTo($order, Order::STATUS_READY, $kitchen);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/orders/{$order->id}/served")->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_SERVED, $order->status);
        $this->assertSame($kitchen->id, $order->accepted_by_user_id);
        $this->assertSame($kitchen->id, $order->preparing_by_user_id);
        $this->assertSame($kitchen->id, $order->ready_by_user_id);
        $this->assertSame($waiter->id, $order->served_by_user_id);
        $this->assertNotNull($order->accepted_at);
        $this->assertNotNull($order->preparing_at);
        $this->assertNotNull($order->ready_at);
        $this->assertNotNull($order->served_at);
    }

    // --- invalid transitions ---------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'confirmed -> preparing' => ['confirmed', 'preparing'],
            'confirmed -> ready' => ['confirmed', 'ready'],
            'confirmed -> served' => ['confirmed', 'served'],
            'accepted -> ready' => ['accepted', 'ready'],
            'accepted -> served' => ['accepted', 'served'],
            'preparing -> accept' => ['preparing', 'accept'],
            'preparing -> served' => ['preparing', 'served'],
            'ready -> accept' => ['ready', 'accept'],
            'ready -> preparing' => ['ready', 'preparing'],
        ];
    }

    /**
     * update_kitchen_status covers accept/preparing/ready; serve_orders
     * covers served — the endpoint under test decides which single actor
     * (never both in the same test, see report) can even reach the 409.
     */
    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_returns_409(string $fromStatus, string $endpoint): void
    {
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();

        if ($fromStatus !== 'confirmed') {
            $order = $this->advanceOrderTo($order, $fromStatus, $kitchen);
        }

        $actor = $endpoint === 'served' ? $waiter : $kitchen;

        $this->actingAs($actor, 'web')
            ->postJson("/api/v1/orders/{$order->id}/{$endpoint}")
            ->assertStatus(409);
    }

    public function test_served_order_rejects_further_kitchen_transitions(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen);

        foreach (['accept', 'preparing', 'ready'] as $endpoint) {
            $this->actingAs($kitchen, 'web')
                ->postJson("/api/v1/orders/{$order->id}/{$endpoint}")
                ->assertStatus(409);
        }
    }

    public function test_served_order_rejects_being_served_again(): void
    {
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/served")
            ->assertStatus(409);
    }

    public function test_cancelled_order_rejects_kitchen_transitions(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($order, $owner);

        foreach (['accept', 'preparing', 'ready'] as $endpoint) {
            $this->actingAs($kitchen, 'web')
                ->postJson("/api/v1/orders/{$order->id}/{$endpoint}")
                ->assertStatus(409);
        }
    }

    public function test_cancelled_order_rejects_being_served(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($order, $owner);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/served")
            ->assertStatus(409);
    }

    // --- concurrency (sequential stand-in) --------------------------------

    public function test_two_sequential_accepts_only_the_first_succeeds(): void
    {
        // Sequential stand-in for the concurrent case: both would race for
        // the same lockForUpdate()'d row inside TransitionOrderStatusAction.
        // Whichever transaction commits first flips the status; the second
        // always observes the already-updated row and is rejected — see
        // TransitionOrderStatusAction. Exercised through the real action
        // twice in a row rather than a fragile parallel-thread harness.
        [$order, , , $kitchen] = $this->confirmedOrder();

        $this->actingAs($kitchen, 'web')->postJson("/api/v1/orders/{$order->id}/accept")->assertOk();
        $this->actingAs($kitchen, 'web')->postJson("/api/v1/orders/{$order->id}/accept")->assertStatus(409);
    }

    public function test_two_sequential_ready_transitions_only_the_first_succeeds(): void
    {
        [$order, , , $kitchen] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_PREPARING, $kitchen);

        $this->actingAs($kitchen, 'web')->postJson("/api/v1/orders/{$order->id}/ready")->assertOk();
        $this->actingAs($kitchen, 'web')->postJson("/api/v1/orders/{$order->id}/ready")->assertStatus(409);
    }

    // --- TableSession independence -----------------------------------

    public function test_lifecycle_continues_after_the_table_session_is_closed(): void
    {
        // CloseTableAction (Bloco 13) now refuses to close a session with
        // any open order, so a real close is no longer reachable while
        // this order is still `confirmed`. The status is forced directly
        // to prove the actual guarantee under test: TransitionOrderStatusAction
        // never consults the session's status at all (see its class
        // docblock) — a real close could never produce this order state
        // anyway, but the code must not rely on that being true.
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $session->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $owner->id])->save();

        // Full chain via the real action (see note on the actingAs()
        // limitation above) — the point under test is that none of these
        // consult activeSession at all, which this exercises identically
        // to going through HTTP.
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen, $waiter);

        $this->assertSame(Order::STATUS_SERVED, $order->status);
    }

    public function test_serving_an_order_does_not_close_the_table_session(): void
    {
        [$order, , , $kitchen, $waiter] = $this->confirmedOrder();
        $this->advanceOrderTo($order, Order::STATUS_READY, $kitchen);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/orders/{$order->id}/served")->assertOk();

        $session = $order->tableSession()->firstOrFail();
        $this->assertTrue($session->isActive());
    }
}
