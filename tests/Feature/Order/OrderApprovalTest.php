<?php

namespace Tests\Feature\Order;

use App\Actions\Orders\ApproveOrderAction;
use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OrderApprovalTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: Order, 1: Organization, 2: Restaurant, 3: User}
     */
    private function waitingApprovalOrder(): array
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return [$order, $organization, $restaurant, $owner];
    }

    // --- Approve ----------------------------------------------------

    public function test_owner_approves_a_waiting_customer_order(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertOk();

        $response->assertJson(['data' => ['order' => ['status' => 'confirmed']]]);
    }

    public function test_approve_sets_approved_by_and_approved_at(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/approve")->assertOk();

        $order->refresh();
        $this->assertSame($owner->id, $order->approved_by_user_id);
        $this->assertNotNull($order->approved_at);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
    }

    public function test_approve_without_permission_is_forbidden(): void
    {
        [$order, $organization, $restaurant] = $this->waitingApprovalOrder();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(403);
    }

    public function test_approve_cross_tenant_returns_404(): void
    {
        [$order] = $this->waitingApprovalOrder();
        [, $otherOwner] = $this->createTenant();

        $this->actingAs($otherOwner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(404);
    }

    public function test_approve_cross_restaurant_scope_returns_404(): void
    {
        [$order, $organization] = $this->waitingApprovalOrder();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $otherWaiter = $this->createStaff($organization, $otherRestaurant, 'waiter', 'W-OTHER');

        $this->actingAs($otherWaiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(404);
    }

    public function test_approve_already_confirmed_returns_409(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/approve")->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(409);
    }

    public function test_approve_cancelled_order_returns_409(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/reject")->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(409);
    }

    public function test_approve_a_waiter_order_returns_409(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(409);
    }

    public function test_two_sequential_approvals_only_the_first_succeeds(): void
    {
        // Sequential stand-in for the concurrent case both approvals would
        // race for the same lockForUpdate()'d row: whichever transaction
        // commits first flips the status, and the second always observes
        // the up-to-date (already confirmed) row and is rejected — see
        // ApproveOrderAction. Exercised here through the real action twice
        // in a row rather than a fragile parallel-thread test harness.
        [$order, , , $owner] = $this->waitingApprovalOrder();

        $first = app(ApproveOrderAction::class)->execute($order, $owner);
        $this->assertSame(Order::STATUS_CONFIRMED, $first->status);

        $this->expectException(OrderStateConflictException::class);
        app(ApproveOrderAction::class)->execute($order->fresh(), $owner);
    }

    // --- Reject -------------------------------------------------------

    public function test_owner_rejects_a_waiting_customer_order(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/reject")
            ->assertOk();

        $response->assertJson(['data' => ['order' => ['status' => 'cancelled']]]);
    }

    public function test_reject_sets_cancelled_by_and_cancelled_at(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/reject")->assertOk();

        $order->refresh();
        $this->assertSame($owner->id, $order->cancelled_by_user_id);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
    }

    public function test_reject_without_permission_is_forbidden(): void
    {
        [$order, $organization, $restaurant] = $this->waitingApprovalOrder();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/orders/{$order->id}/reject")
            ->assertStatus(403);
    }

    public function test_reject_confirmed_order_returns_409(): void
    {
        [$order, , , $owner] = $this->waitingApprovalOrder();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/approve")->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/reject")
            ->assertStatus(409);
    }

    public function test_reject_cross_tenant_returns_404(): void
    {
        [$order] = $this->waitingApprovalOrder();
        [, $otherOwner] = $this->createTenant();

        $this->actingAs($otherOwner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/reject")
            ->assertStatus(404);
    }

    public function test_reject_a_waiter_order_returns_409(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/orders/{$order->id}/reject")
            ->assertStatus(409);
    }
}
