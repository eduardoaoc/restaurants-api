<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cross-tenant security for the Bloco 1 Floor Plan endpoints: Organization
 * A must never be able to read or write anything belonging to Organization
 * B's floors/zones/floor plan.
 */
class FloorPlanApiIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_a_cannot_view_floor_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/floors/{$floorB->id}")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_view_zone_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/zones/{$zoneB->id}")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_list_floors_of_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $this->createFloor($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/floors")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_list_zones_of_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);
        $this->createZone($floorB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/zones")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_view_floor_plan_of_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/floor-plan")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_bulk_update_layout_of_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}/floor-plan/layout", [
                'tables' => [['id' => $tableB->id, 'layout_x' => 0.5, 'layout_y' => 0.5]],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $tableB->id, 'layout_x' => null]);
    }
}
