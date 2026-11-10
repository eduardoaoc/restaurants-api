<?php

namespace Tests\Feature\Staff;

use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * ID01: a user ID is a label for audit and search, never a credential.
 * Knowing someone's ID grants no access, across restaurants or organizations.
 */
class StaffIdAccessBoundaryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_knowing_the_id_of_staff_of_another_organization_gives_no_access(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $this->actingAs($ownerA, 'web')->getJson("/api/v1/staff/{$staffB->id}")->assertNotFound();
        $this->actingAs($ownerA, 'web')->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'X'])->assertNotFound();
        $this->assertNotSame('X', $staffB->fresh()->name);

        $list = $this->actingAs($ownerA, 'web')->getJson('/api/v1/staff')->assertOk();
        $this->assertNotContains($staffB->id, collect($list->json('data.staff'))->pluck('id')->all());
    }

    public function test_restaurant_scoped_manager_cannot_open_staff_of_another_restaurant_by_id(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-1');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')->getJson("/api/v1/staff/{$waiterB->id}")->assertNotFound();
        $list = $this->actingAs($manager, 'web')->getJson('/api/v1/staff')->assertOk();
        $this->assertNotContains($waiterB->id, collect($list->json('data.staff'))->pluck('id')->all());
    }

    public function test_public_menu_never_exposes_staff_ids_or_codes(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-SECRET');
        $table = Table::factory()->create(['restaurant_id' => $restaurant->id]);

        $body = $this->getJson("/api/v1/public/tables/{$table->public_token}")->getContent();

        $this->assertStringNotContainsString('W-SECRET', $body);
        $this->assertStringNotContainsString('sub_id', $body);
        $this->assertStringNotContainsString('"user_id"', $body);
        $this->assertNotNull($staff);
    }
}
