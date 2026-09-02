<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_from_organization_a_is_not_considered_part_of_organization_b(): void
    {
        $organizationA = Organization::factory()->create();
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organizationA->id]);

        $organizationB = Organization::factory()->create();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organizationB->id]);

        $userA = User::factory()->create();
        $organizationA->users()->attach($userA);
        $restaurantA->users()->attach($userA, ['sub_id' => 'W1']);

        $userA->refresh();

        $this->assertTrue($userA->organizations->contains($organizationA));
        $this->assertFalse($userA->organizations->contains($organizationB));

        $this->assertTrue($userA->restaurants->contains($restaurantA));
        $this->assertFalse($userA->restaurants->contains($restaurantB));

        $this->assertFalse($organizationB->fresh()->users->contains($userA));
        $this->assertFalse($restaurantB->fresh()->users->contains($userA));
    }
}
