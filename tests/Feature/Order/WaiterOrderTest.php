<?php

namespace Tests\Feature\Order;

use App\Actions\Tables\OpenTableAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WaiterOrderTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_waiter_with_create_orders_permission_creates_an_order(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $waiter, 2);

        $response = $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ])->assertStatus(201);

        $response->assertJson(['data' => ['order' => ['status' => 'confirmed', 'origin' => 'waiter']]]);
    }

    public function test_order_is_confirmed_with_origin_waiter_and_created_by(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ])->assertStatus(201);

        $order = Order::query()->firstOrFail();
        $this->assertSame(Order::ORIGIN_WAITER, $order->origin);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame($waiter->id, $order->created_by_user_id);
    }

    public function test_waiter_from_another_restaurant_cannot_create_an_order(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);

        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $tableB = $this->createTable($restaurantB);
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $this->openSession($tableB, $owner);

        $this->actingAs($waiterA, 'web')
            ->postJson("/api/v1/tables/{$tableB->id}/orders", [
                'items' => [['restaurant_product_id' => $rpB->id, 'quantity' => 1]],
            ])->assertStatus(404);
    }

    public function test_no_active_session_returns_409(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ])->assertStatus(409);
    }

    public function test_staff_without_create_orders_permission_is_forbidden(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $this->openSession($table, $owner);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ])->assertStatus(403);
    }
}
