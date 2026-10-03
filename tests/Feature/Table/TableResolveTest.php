<?php

namespace Tests\Feature\Table;

use App\Actions\Tables\AssignWaiterAction;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 8.1A — staff-side resolution of the SAME physical QR a customer
 * scans (GET /tables/resolve/{publicToken}). The public_token is only a
 * lookup key: access comes from the authenticated user's membership,
 * RestaurantScope and TablePolicy::view, never from the token itself.
 */
class TableResolveTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_waiter_resolves_a_table_of_their_own_restaurant(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant, 'Mesa 7', 7);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'restaurant' => ['id' => $restaurant->id],
                    'table' => ['id' => $table->id, 'name' => 'Mesa 7', 'number' => 7],
                ],
            ]);
    }

    public function test_owner_resolves(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertOk()
            ->assertJsonPath('data.table.id', $table->id);
    }

    public function test_manager_resolves(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertOk()
            ->assertJsonPath('data.table.id', $table->id);
    }

    public function test_anonymous_gets_unauthorized(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertUnauthorized();
    }

    public function test_waiter_of_another_organization_gets_not_found_for_a_valid_token(): void
    {
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        [$organizationA, , $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-A');

        // The token is publicly valid...
        $this->getJson("/api/v1/public/tables/{$tableB->public_token}")->assertOk();

        // ...but reveals no operational context to another tenant's staff,
        // and is indistinguishable from an unknown token.
        $foreign = $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/tables/resolve/{$tableB->public_token}")
            ->assertNotFound();
        $unknown = $this->actingAs($waiterA, 'web')
            ->getJson('/api/v1/tables/resolve/'.str_repeat('x', 48))
            ->assertNotFound();

        $this->assertSame($unknown->json('message'), $foreign->json('message'));
        $this->assertNull($foreign->json('data'));
    }

    public function test_waiter_of_another_restaurant_of_the_same_organization_gets_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/tables/resolve/{$tableB->public_token}")
            ->assertNotFound();
    }

    public function test_unknown_token_gets_not_found(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/tables/resolve/does-not-exist')
            ->assertNotFound();
    }

    public function test_kitchen_without_table_permission_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertForbidden();
    }

    /**
     * Cashier holds close_bill, which already lets them view tables (and
     * their public_token) by id via TablePolicy::view — resolving the QR
     * grants nothing beyond that, so it follows the same rule.
     */
    public function test_cashier_follows_table_view_permission(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertOk()
            ->assertJsonPath('data.table.id', $table->id);
    }

    public function test_resolving_a_table_without_session_never_opens_one(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertOk();

        $this->assertSame(0, TableSession::query()->where('table_id', $table->id)->count());
    }

    public function test_resolving_is_a_pure_read_on_an_active_session(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $assigned = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $scanner = $this->createStaff($organization, $restaurant, 'waiter', 'W-2');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $assigned, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $sessionBefore = $session->fresh()->toArray();
        $ordersBefore = Order::query()->where('table_id', $table->id)->get()->toArray();
        $auditBefore = AuditLog::query()->count();
        $activityBefore = RestaurantActivityEvent::query()->count();

        $this->actingAs($scanner, 'web')
            ->getJson("/api/v1/tables/resolve/{$table->public_token}")
            ->assertOk();

        $this->assertSame($sessionBefore, $session->fresh()->toArray());
        $this->assertSame($assigned->id, $session->fresh()->assigned_waiter_user_id);
        $this->assertSame(1, TableSession::query()->where('table_id', $table->id)->count());
        $this->assertSame($ordersBefore, Order::query()->where('table_id', $table->id)->get()->toArray());
        $this->assertSame($auditBefore, AuditLog::query()->count());
        $this->assertSame($activityBefore, RestaurantActivityEvent::query()->count());
    }

    public function test_public_resolution_is_unchanged_for_anonymous_clients(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant, 'Mesa 3', 3);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJsonPath('data.table.name', 'Mesa 3');
    }
}
