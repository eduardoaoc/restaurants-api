<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_have_many_restaurants(): void
    {
        $organization = Organization::factory()->create();

        $restaurants = Restaurant::factory()->count(3)->create([
            'organization_id' => $organization->id,
        ]);

        $this->assertCount(3, $organization->restaurants);
        $this->assertTrue(
            $restaurants->pluck('id')->diff($organization->restaurants->pluck('id'))->isEmpty()
        );
    }

    public function test_user_can_belong_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user);

        $this->assertTrue($organization->fresh()->users->contains($user));
        $this->assertTrue($user->fresh()->organizations->contains($organization));
    }
}
