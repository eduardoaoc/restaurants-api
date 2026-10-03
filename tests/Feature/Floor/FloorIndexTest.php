<?php

namespace Tests\Feature\Floor;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorIndexTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_lists_floors(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floors")
            ->assertOk();

        $ids = collect($response->json('data.floors'))->pluck('id');
        $this->assertTrue($ids->contains($floor->id));
    }

    public function test_cashier_without_manage_tables_can_still_view_floors(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $this->createFloor($restaurant);

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floors")
            ->assertOk();
    }

    public function test_waiter_scoped_to_a_different_restaurant_gets_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/floors")
            ->assertNotFound();
    }

    public function test_floors_are_ordered_by_sort_order(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createFloor($restaurant, 'Second Floor', 2);
        $this->createFloor($restaurant, 'Ground Floor', 1);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/floors")
            ->assertOk();

        $names = collect($response->json('data.floors'))->pluck('name')->values()->all();
        $this->assertSame(['Ground Floor', 'Second Floor'], $names);
    }
}
