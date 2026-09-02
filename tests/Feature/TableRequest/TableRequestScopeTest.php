<?php

namespace Tests\Feature\TableRequest;

use App\Models\Restaurant;
use App\Models\TableRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableRequestScopeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Organization isolation --------------------------------------

    public function test_other_organization_request_returns_404_on_every_operation(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $requestB = $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($ownerA, 'web')->getJson("/api/v1/table-requests/{$requestB->id}")->assertStatus(404);
        $this->actingAs($ownerA, 'web')->postJson("/api/v1/table-requests/{$requestB->id}/acknowledge")->assertStatus(404);
        $this->actingAs($ownerA, 'web')->postJson("/api/v1/table-requests/{$requestB->id}/complete")->assertStatus(404);
        $this->actingAs($ownerA, 'web')->postJson("/api/v1/table-requests/{$requestB->id}/cancel")->assertStatus(404);
    }

    public function test_other_organization_request_never_appears_in_the_list(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $response = $this->actingAs($ownerA, 'web')->getJson('/api/v1/table-requests')->assertOk();

        $this->assertCount(0, $response->json('data.table_requests'));
    }

    // --- Cross-restaurant isolation within the same organization --------

    public function test_waiter_a_cannot_view_or_transition_request_of_restaurant_b(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $requestB = $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($waiterA, 'web')->getJson("/api/v1/table-requests/{$requestB->id}")->assertStatus(404);
        $this->actingAs($waiterA, 'web')->postJson("/api/v1/table-requests/{$requestB->id}/acknowledge")->assertStatus(404);
    }

    public function test_waiter_a_list_contains_only_restaurant_a(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $requestA = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $response = $this->actingAs($waiterA, 'web')->getJson('/api/v1/table-requests')->assertOk();

        $this->assertSame([$requestA->id], collect($response->json('data.table_requests'))->pluck('id')->all());
    }

    // --- Scope vs permission (tested independently) ---------------------

    public function test_staff_in_scope_but_missing_permission_gets_403_not_404(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        // Kitchen belongs to the SAME restaurant (in scope) but lacks
        // handle_table_requests — must be 403, distinct from an
        // out-of-scope restaurant's 404.
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")
            ->assertStatus(403);
    }

    public function test_list_without_permission_is_forbidden(): void
    {
        [$organization] = $this->createTenant();
        $bystander = User::factory()->create();
        $organization->users()->attach($bystander->id);

        $this->actingAs($bystander, 'web')->getJson('/api/v1/table-requests')->assertStatus(403);
    }

    public function test_cashier_can_handle_table_requests(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")
            ->assertOk();
    }

    // --- Owner scope --------------------------------------------------

    public function test_owner_can_acknowledge_requests_in_any_restaurant_of_their_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $requestA = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $requestB = $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($owner, 'web')->postJson("/api/v1/table-requests/{$requestA->id}/acknowledge")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/table-requests/{$requestB->id}/acknowledge")->assertOk();
    }

    public function test_owner_cannot_operate_a_different_organization(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $requestB = $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/table-requests/{$requestB->id}/acknowledge")
            ->assertStatus(404);
    }
}
