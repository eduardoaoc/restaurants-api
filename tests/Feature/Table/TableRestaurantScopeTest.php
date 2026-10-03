<?php

namespace Tests\Feature\Table;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — TableController/TablePolicy previously scoped tables only by
 * organization, never by RestaurantScope (confirmed still present while
 * inspecting for this block — see the report): a manager restricted to
 * one restaurant could view/update/list/create tables of ANOTHER
 * restaurant of the same organization. Fixed here since Bloco 4's own
 * transfer target resolution depends on Table lookups being correctly
 * scoped — locks in the fix, mirroring StaffRestaurantScopeTest (Bloco 18).
 */
class TableRestaurantScopeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_can_view_a_table_of_their_own_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $tableA = $this->createTable($restaurantA);

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/tables/{$tableA->id}")
            ->assertOk()
            ->assertJsonPath('data.table.id', $tableA->id);
    }

    public function test_manager_gets_not_found_viewing_a_table_of_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/tables/{$tableB->id}")
            ->assertNotFound();
    }

    public function test_manager_gets_not_found_updating_a_table_of_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $tableB = $this->createTable($restaurantB, 'Original Name');

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/tables/{$tableB->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertSame('Original Name', $tableB->fresh()->name);
    }

    public function test_manager_listing_tables_never_includes_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $this->createTable($restaurantA, 'Table A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/tables")
            ->assertNotFound();
    }

    public function test_manager_gets_not_found_creating_a_table_in_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/tables", ['name' => 'Injected'])
            ->assertNotFound();

        $this->assertDatabaseMissing('tables', ['name' => 'Injected']);
    }

    public function test_owner_organization_wide_still_reaches_every_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/tables/{$tableB->id}")
            ->assertOk()
            ->assertJsonPath('data.table.id', $tableB->id);
    }
}
