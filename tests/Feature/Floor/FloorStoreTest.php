<?php

namespace Tests\Feature\Floor;

use App\Models\AuditLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_floor(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", ['name' => 'Ground Floor'])
            ->assertCreated()
            ->assertJsonPath('data.floor.name', 'Ground Floor')
            ->assertJsonPath('data.floor.sort_order', 0)
            ->assertJsonPath('data.floor.is_active', true);

        $this->assertDatabaseHas('floors', ['restaurant_id' => $restaurant->id, 'name' => 'Ground Floor']);
    }

    public function test_manager_with_manage_floor_plan_can_create_a_floor(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", ['name' => 'Ground Floor'])
            ->assertCreated();
    }

    public function test_waiter_with_manage_tables_but_not_manage_floor_plan_cannot_create_a_floor(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", ['name' => 'Ground Floor'])
            ->assertForbidden();

        $this->assertDatabaseMissing('floors', ['name' => 'Ground Floor']);
    }

    public function test_kitchen_without_permission_cannot_view_or_create_floors(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floors")
            ->assertForbidden();

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", ['name' => 'Ground Floor'])
            ->assertForbidden();
    }

    public function test_name_is_required(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_creating_a_floor_under_a_restaurant_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/floors", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('floors', ['name' => 'Pwned']);
    }

    /**
     * Hardening follow-up (Passo 3.2): a manager scoped only to Restaurant A
     * must not be able to create a Floor under a SIBLING Restaurant B of
     * the same organization, not just a different organization's.
     */
    public function test_creating_a_floor_under_a_sibling_restaurant_of_the_same_organization_returns_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/floors", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('floors', ['name' => 'Pwned']);
    }

    public function test_creating_a_floor_records_an_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", ['name' => 'Ground Floor'])
            ->assertCreated();

        $floorId = $response->json('data.floor.id');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'actor_user_id' => $owner->id,
            'event' => AuditLog::EVENT_FLOOR_CREATED,
            'resource_type' => AuditLog::RESOURCE_FLOOR,
            'resource_id' => $floorId,
        ]);
    }
}
