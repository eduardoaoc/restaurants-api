<?php

namespace Tests\Feature\Zone;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ZoneDeleteTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_delete_an_empty_zone(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/zones/{$zone->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
    }

    public function test_deleting_a_zone_with_tables_returns_conflict(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $table = $this->createTable($restaurant);
        $table->update(['zone_id' => $zone->id]);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/zones/{$zone->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ZONE_HAS_TABLES');

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    public function test_waiter_cannot_delete_a_zone(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->deleteJson("/api/v1/zones/{$zone->id}")
            ->assertForbidden();
    }

    public function test_deleting_a_zone_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB);

        $this->actingAs($ownerA, 'web')
            ->deleteJson("/api/v1/zones/{$zoneB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('zones', ['id' => $zoneB->id]);
    }

    /**
     * Hardening follow-up (Passo 3.2): a manager scoped only to Restaurant A
     * must not be able to delete a Zone of a SIBLING Restaurant B of the
     * same organization, not just a different organization's.
     */
    public function test_deleting_a_zone_of_a_sibling_restaurant_of_the_same_organization_returns_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB);

        $this->actingAs($managerA, 'web')
            ->deleteJson("/api/v1/zones/{$zoneB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('zones', ['id' => $zoneB->id]);
    }
}
