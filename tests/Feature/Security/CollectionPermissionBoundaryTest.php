<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CollectionPermissionBoundaryTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_orders_and_kitchen_collections_do_not_borrow_permission_from_another_restaurant(): void
    {
        [$organization, $owner, $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'kitchen', 'K-COL');
        // A reachable restaurant with no role simulates a partially revoked grant.
        $a2->users()->attach($user->id, ['sub_id' => 'REVOKED-COL']);
        $ids = [];
        foreach ([$a1, $a2] as $restaurant) {
            $table = $this->createTable($restaurant);
            $this->openSession($table, $owner);
            $product = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));
            $ids[] = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $product->id, 'quantity' => 1]])->id;
        }
        $this->actingAs($user, 'web')->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $ids[0]);
        $this->getJson('/api/v1/kitchen/orders')->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $ids[0]);
        $this->getJson("/api/v1/kitchen/dashboard?restaurant_id={$a2->id}")->assertForbidden();
        $this->getJson("/api/v1/orders/{$ids[1]}")->assertForbidden();
    }

    public function test_requests_and_audit_collections_filter_by_target_grants(): void
    {
        [$organization, $owner, $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'manager', 'M-COL');
        $a2->users()->attach($user->id, ['sub_id' => 'K-COL']);
        $this->assignRole($user, 'kitchen', $organization, $a2);
        $ids = [];
        foreach ([$a1, $a2] as $restaurant) {
            $table = $this->createTable($restaurant);
            $this->openSession($table, $owner);
            $ids[] = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER)->id;
            AuditLog::create([
                'organization_id' => $organization->id, 'restaurant_id' => $restaurant->id,
                'actor_user_id' => $owner->id, 'actor_type' => AuditLog::ACTOR_USER,
                'event' => AuditLog::EVENT_ORDER_SERVED, 'resource_type' => AuditLog::RESOURCE_ORDER, 'resource_id' => 1,
            ]);
        }
        $this->actingAs($user, 'web')->getJson('/api/v1/table-requests')->assertOk()->assertJsonCount(1, 'data.table_requests')->assertJsonPath('data.table_requests.0.id', $ids[0]);
        $this->getJson("/api/v1/table-requests/{$ids[1]}")->assertForbidden();
        $logs = $this->getJson('/api/v1/audit-logs')->assertOk()->json('data.audit_logs');
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertSame($a1->id, $log['restaurant']['id']);
        }
        $this->getJson("/api/v1/audit-logs?restaurant_id={$a2->id}")->assertNotFound();
    }

    public function test_staff_lists_and_shift_management_do_not_inherit_a_manager_grant(): void
    {
        [$organization, , $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'manager', 'M-COL');
        $a2->users()->attach($user->id, ['sub_id' => 'W-COL']);
        $this->assignRole($user, 'waiter', $organization, $a2);
        $staffA = $this->createStaff($organization, $a1, 'waiter', 'S-A');
        $staffB = $this->createStaff($organization, $a2, 'waiter', 'S-B');
        $ids = collect($this->actingAs($user, 'web')->getJson('/api/v1/staff')->assertOk()->json('data.staff'))->pluck('id');
        $this->assertContains($staffA->id, $ids);
        $this->assertNotContains($staffB->id, $ids);
        $this->getJson("/api/v1/staff/{$staffB->id}")->assertForbidden();
        $this->getJson("/api/v1/restaurants/{$a1->id}/staff-shifts")->assertOk();
        $this->getJson("/api/v1/restaurants/{$a2->id}/staff-shifts")->assertForbidden();
        $this->postJson("/api/v1/restaurants/{$a2->id}/staff-shifts", ['user_id' => $staffB->id])->assertForbidden();
    }
}
