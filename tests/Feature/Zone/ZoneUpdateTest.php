<?php

namespace Tests\Feature\Zone;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ZoneUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_update_a_zone(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor, 'Interior');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/zones/{$zone->id}", ['name' => 'Terrace', 'sort_order' => 5])
            ->assertOk()
            ->assertJsonPath('data.zone.name', 'Terrace')
            ->assertJsonPath('data.zone.sort_order', 5);
    }

    public function test_moving_a_zone_to_a_floor_of_the_same_restaurant_succeeds(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floorA = $this->createFloor($restaurant, 'Floor A');
        $floorB = $this->createFloor($restaurant, 'Floor B');
        $zone = $this->createZone($floorA);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/zones/{$zone->id}", ['floor_id' => $floorB->id])
            ->assertOk()
            ->assertJsonPath('data.zone.floor_id', $floorB->id);
    }

    public function test_moving_a_zone_to_a_floor_of_another_restaurant_is_rejected(): void
    {
        [, $owner, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorA = $this->createFloor($restaurantA);
        $floorB = $this->createFloor($restaurantB);
        $zone = $this->createZone($floorA);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/zones/{$zone->id}", ['floor_id' => $floorB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['floor_id']);

        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'floor_id' => $floorA->id]);
    }

    public function test_waiter_cannot_update_a_zone(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/zones/{$zone->id}", ['name' => 'Pwned'])
            ->assertForbidden();
    }

    public function test_updating_a_zone_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/zones/{$zoneB->id}", ['name' => 'Pwned'])
            ->assertNotFound();
    }

    /**
     * Hardening follow-up (Passo 3.2): a manager scoped only to Restaurant A
     * must not be able to update a Zone of a SIBLING Restaurant B of the
     * same organization, not just a different organization's.
     */
    public function test_updating_a_zone_of_a_sibling_restaurant_of_the_same_organization_returns_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB, 'Original Name');

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/zones/{$zoneB->id}", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseHas('zones', ['id' => $zoneB->id, 'name' => 'Original Name']);
    }
}
