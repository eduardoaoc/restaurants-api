<?php

namespace Tests\Feature\Floor;

use App\Models\AuditLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorDeleteTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_delete_an_empty_floor(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/floors/{$floor->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('floors', ['id' => $floor->id]);
    }

    public function test_deleting_a_floor_with_zones_returns_conflict(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $this->createZone($floor);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/floors/{$floor->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FLOOR_HAS_ZONES');

        $this->assertDatabaseHas('floors', ['id' => $floor->id]);
    }

    public function test_waiter_cannot_delete_a_floor(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->deleteJson("/api/v1/floors/{$floor->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('floors', ['id' => $floor->id]);
    }

    public function test_deleting_a_floor_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->deleteJson("/api/v1/floors/{$floorB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('floors', ['id' => $floorB->id]);
    }

    /**
     * Hardening follow-up (Passo 3.2): a manager scoped only to Restaurant A
     * must not be able to delete a Floor of a SIBLING Restaurant B of the
     * same organization, not just a different organization's.
     */
    public function test_deleting_a_floor_of_a_sibling_restaurant_of_the_same_organization_returns_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $floorB = $this->createFloor($restaurantB);

        $this->actingAs($managerA, 'web')
            ->deleteJson("/api/v1/floors/{$floorB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('floors', ['id' => $floorB->id]);
    }

    public function test_deleting_a_floor_records_an_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant, 'Ground Floor');

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/floors/{$floor->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'event' => AuditLog::EVENT_FLOOR_DELETED,
            'resource_type' => AuditLog::RESOURCE_FLOOR,
            'resource_id' => $floor->id,
        ]);
    }
}
