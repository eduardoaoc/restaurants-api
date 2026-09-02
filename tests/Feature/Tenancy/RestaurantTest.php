<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_belongs_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->assertTrue($restaurant->organization->is($organization));
    }

    public function test_slug_can_repeat_across_different_organizations(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        Restaurant::factory()->create([
            'organization_id' => $organizationA->id,
            'slug' => 'downtown',
        ]);

        $restaurantB = Restaurant::factory()->create([
            'organization_id' => $organizationB->id,
            'slug' => 'downtown',
        ]);

        $this->assertSame('downtown', $restaurantB->slug);
        $this->assertDatabaseCount('restaurants', 2);
    }

    public function test_slug_cannot_repeat_within_the_same_organization(): void
    {
        $organization = Organization::factory()->create();

        Restaurant::factory()->create([
            'organization_id' => $organization->id,
            'slug' => 'downtown',
        ]);

        $this->expectException(QueryException::class);

        Restaurant::factory()->create([
            'organization_id' => $organization->id,
            'slug' => 'downtown',
        ]);
    }
}
