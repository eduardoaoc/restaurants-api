<?php

namespace Tests\Feature\FloorPlan;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorPlanShowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_floor_plan_returns_floors_zones_and_tables(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant, 'Ground Floor');
        $zone = $this->createZone($floor, 'Interior');
        $table = $this->createTable($restaurant);
        $table->update(['zone_id' => $zone->id]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floor-plan")
            ->assertOk();

        $response->assertJsonPath('data.floor_plan.floors.0.name', 'Ground Floor');
        $response->assertJsonPath('data.floor_plan.floors.0.zones.0.name', 'Interior');
        $response->assertJsonPath('data.floor_plan.floors.0.zones.0.tables.0.id', $table->id);
    }

    public function test_floor_plan_lists_unassigned_tables_separately(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floor-plan")
            ->assertOk();

        $unassignedIds = collect($response->json('data.floor_plan.unassigned_tables'))->pluck('id');
        $this->assertTrue($unassignedIds->contains($table->id));
        $this->assertSame([], $response->json('data.floor_plan.floors'));
    }

    public function test_waiter_can_view_floor_plan_but_not_manage_it(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floor-plan")
            ->assertOk();

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'layout_x' => 0.1, 'layout_y' => 0.1]],
            ])
            ->assertForbidden();
    }

    public function test_kitchen_cannot_view_floor_plan(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floor-plan")
            ->assertForbidden();
    }

    public function test_waiter_scoped_to_a_different_restaurant_gets_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/floor-plan")
            ->assertNotFound();
    }
}
