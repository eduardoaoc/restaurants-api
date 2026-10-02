<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 8.2A — restaurants[].can_manage_table_structure in GET
 * /auth/context: a per-restaurant projection of
 * TablePolicy::manageStructure, never a persisted permission.
 */
class AuthContextTableStructureCapabilityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Sanctum's request guard caches the first authenticated user for the
     * whole test, and AuthenticateSession pins the session to the previous
     * user's password hash — forget both so each request really runs as
     * $user.
     */
    private function as(User $user): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, 'web');
    }

    private function setWaiterTableManagement(Restaurant $restaurant, bool $enabled): void
    {
        $restaurant->settings()->update(['waiter_table_management_enabled' => $enabled]);
    }

    /**
     * @return array<int, bool> restaurant id => can_manage_table_structure
     */
    private function capabilities(User $user): array
    {
        $organizations = $this->as($user)->getJson('/api/v1/auth/context')->assertOk()->json('data.organizations');

        return collect($organizations[0]['restaurants'])
            ->mapWithKeys(fn (array $restaurant) => [$restaurant['id'] => $restaurant['can_manage_table_structure']])
            ->all();
    }

    public function test_waiter_with_toggle_enabled_gets_true(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->assertSame([$restaurant->id => true], $this->capabilities($waiter));
    }

    public function test_waiter_with_toggle_disabled_gets_false(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->setWaiterTableManagement($restaurant, false);

        $this->assertSame([$restaurant->id => false], $this->capabilities($waiter));
    }

    public function test_owner_and_manager_get_true_even_with_toggle_disabled(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $this->setWaiterTableManagement($restaurant, false);

        $this->assertSame([$restaurant->id => true], $this->capabilities($owner));
        $this->assertSame([$restaurant->id => true], $this->capabilities($manager));
    }

    public function test_roles_without_manage_tables_or_manage_floor_plan_get_false(): void
    {
        [$organization, , $restaurant] = $this->createTenant();

        foreach (['kitchen', 'cashier'] as $i => $role) {
            $staff = $this->createStaff($organization, $restaurant, $role, "S-{$i}");
            $this->assertSame([$restaurant->id => false], $this->capabilities($staff), $role);
        }
    }

    public function test_value_is_computed_per_restaurant(): void
    {
        [$organization, $owner, $malvarrosa] = $this->createTenant();
        $ruzafa = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaffAcrossRestaurants($organization, [$malvarrosa, $ruzafa], 'waiter', $owner);
        $this->setWaiterTableManagement($malvarrosa, false);

        $this->assertSame(
            [$malvarrosa->id => false, $ruzafa->id => true],
            collect($this->capabilities($waiter))->sortKeys()->all(),
        );
    }

    public function test_refetching_the_context_reflects_a_toggle_change_and_matches_enforcement(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->assertTrue($this->capabilities($waiter)[$restaurant->id]);

        $this->as($owner)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => false])
            ->assertOk();

        $this->assertFalse($this->capabilities($waiter)[$restaurant->id]);
        $this->as($waiter)->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'X'])->assertForbidden();

        $this->as($owner)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => true])
            ->assertOk();

        $this->assertTrue($this->capabilities($waiter)[$restaurant->id]);
        $this->as($waiter)->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'Y'])->assertCreated();
    }

    public function test_capability_is_not_a_permission_and_settings_stay_forbidden_for_waiter(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $restaurantContext = $this->as($waiter)->getJson('/api/v1/auth/context')->json('data.organizations.0.restaurants.0');
        $this->assertNotContains('can_manage_table_structure', $restaurantContext['permissions']);
        $this->assertNotContains('manage_floor_plan', $restaurantContext['permissions']);

        $this->as($waiter)->getJson("/api/v1/restaurants/{$restaurant->id}/settings")->assertForbidden();
        $this->as($waiter)->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => true])->assertForbidden();
    }
}
