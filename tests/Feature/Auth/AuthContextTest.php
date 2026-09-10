<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\PlatformRole;
use App\Models\PlatformRoleAssignment;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * GET /api/v1/auth/context — Passo 1.2A.
 *
 * Verifies the contract built by AuthContextBuilder: organizations/
 * restaurants are limited to what the user can actually reach, permissions
 * come from the real role/permission relationships (never a role-name
 * shortcut), platform access stays fully separate from tenant access, and
 * cross-tenant/cross-restaurant data never leaks.
 */
class AuthContextTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
    }

    public function test_unauthenticated_user_receives_401(): void
    {
        $this->getJson('/api/v1/auth/context')->assertUnauthorized();
    }

    public function test_authenticated_user_receives_200(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();
    }

    public function test_owner_receives_correct_organization_restaurant_and_permissions(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $response->assertJsonPath('data.user.id', $owner->id);
        $response->assertJsonPath('data.user.email', $owner->email);
        $response->assertJsonCount(1, 'data.organizations');
        $response->assertJsonPath('data.organizations.0.id', $organization->id);
        $response->assertJsonPath('data.organizations.0.roles', ['owner']);
        $response->assertJsonPath('data.organizations.0.permissions', fn ($permissions) => in_array('manage_organization', $permissions, true)
            && in_array('manage_restaurants', $permissions, true)
            && in_array('manage_users', $permissions, true));

        $response->assertJsonCount(1, 'data.organizations.0.restaurants');
        $response->assertJsonPath('data.organizations.0.restaurants.0.id', $restaurant->id);
        $response->assertJsonPath('data.organizations.0.restaurants.0.permissions', fn ($permissions) => in_array('manage_menu', $permissions, true)
            && in_array('view_operations', $permissions, true));
    }

    public function test_manager_receives_real_capabilities_without_owner_exclusive_capability(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'MGR-1');

        $response = $this->actingAs($manager, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        // CreateStaffAction always assigns a restaurant-scoped role (never
        // organization-wide), so the manager's capabilities show up under
        // their restaurant, not at the organization level.
        $restaurantPermissions = $response->json('data.organizations.0.restaurants.0.permissions');

        // manage_organization is owner-exclusive per RolePermissionSeeder —
        // manager must never receive it.
        $this->assertNotContains('manage_organization', $restaurantPermissions);
        $this->assertContains('manage_restaurants', $restaurantPermissions);
        $this->assertContains('manage_users', $restaurantPermissions);

        // No organization-wide role was granted, so the organization-level
        // bucket must stay empty — it must never be inflated by a
        // restaurant-scoped grant (see AuthContextBuilder).
        $this->assertSame([], $response->json('data.organizations.0.permissions'));
    }

    public function test_waiter_receives_only_permitted_restaurants_and_no_administrative_permission(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $waiter = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $response = $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $response->assertJsonCount(1, 'data.organizations.0.restaurants');
        $response->assertJsonPath('data.organizations.0.restaurants.0.id', $restaurantA->id);

        $restaurantIds = collect($response->json('data.organizations.0.restaurants'))->pluck('id');
        $this->assertFalse($restaurantIds->contains($restaurantB->id));

        $waiterPermissions = $response->json('data.organizations.0.restaurants.0.permissions');
        $this->assertContains('create_orders', $waiterPermissions);
        $this->assertNotContains('view_operations', $waiterPermissions);
        $this->assertNotContains('manage_users', $waiterPermissions);
        $this->assertNotContains('manage_restaurants', $waiterPermissions);

        // A waiter holds no organization-wide role, so org-level permissions
        // (which mirror organization-scoped actions only) must be empty.
        $this->assertSame([], $response->json('data.organizations.0.permissions'));
    }

    public function test_kitchen_receives_only_kitchen_capabilities(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $response = $this->actingAs($kitchen, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $permissions = $response->json('data.organizations.0.restaurants.0.permissions');

        $this->assertSame(['update_kitchen_status'], $permissions);
    }

    public function test_cashier_receives_only_cashier_capabilities(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $response = $this->actingAs($cashier, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $permissions = $response->json('data.organizations.0.restaurants.0.permissions');

        sort($permissions);
        $this->assertSame(['close_bill', 'handle_table_requests', 'record_payments'], $permissions);
    }

    public function test_user_does_not_receive_another_tenants_organization(): void
    {
        [$organizationA, $ownerA] = $this->createTenant();
        [$organizationB] = $this->createTenant();

        $response = $this->actingAs($ownerA, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $orgIds = collect($response->json('data.organizations'))->pluck('id');

        $this->assertTrue($orgIds->contains($organizationA->id));
        $this->assertFalse($orgIds->contains($organizationB->id));
    }

    public function test_user_does_not_receive_another_tenants_restaurant_or_role(): void
    {
        [$organizationA, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();

        $response = $this->actingAs($ownerA, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $restaurantIds = collect($response->json('data.organizations'))
            ->flatMap(fn ($organization) => collect($organization['restaurants'])->pluck('id'));

        $this->assertFalse($restaurantIds->contains($restaurantB->id));
    }

    /**
     * A staff member linked to Restaurant A only must not receive
     * permissions as if they could also operate Restaurant B, even when
     * both restaurants belong to the same organization they are a member
     * of.
     */
    public function test_user_linked_to_one_restaurant_does_not_receive_permissions_for_another(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $waiter = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $response = $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $restaurants = collect($response->json('data.organizations.0.restaurants'));

        $this->assertCount(1, $restaurants);
        $this->assertSame($restaurantA->id, $restaurants->first()['id']);
        $this->assertFalse($restaurants->pluck('id')->contains($restaurantB->id));
    }

    /**
     * The core nuance from the spec (Bloco 1.2A #9): a user holding a
     * DIFFERENT role at each of two restaurants must see each restaurant's
     * permissions scoped to that restaurant only — a capability granted at
     * Restaurant B must never leak into what is shown for Restaurant A.
     */
    public function test_permissions_are_scoped_per_restaurant_when_roles_differ(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $user = User::factory()->create();
        $organization->users()->attach($user->id);
        $restaurantA->users()->attach($user->id, ['sub_id' => 'W-1']);
        $restaurantB->users()->attach($user->id, ['sub_id' => 'K-1']);

        $this->assignRole($user, 'waiter', $organization, $restaurantA);
        $this->assignRole($user, 'kitchen', $organization, $restaurantB);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $restaurants = collect($response->json('data.organizations.0.restaurants'))->keyBy('id');

        $restaurantAPermissions = $restaurants[$restaurantA->id]['permissions'];
        $restaurantBPermissions = $restaurants[$restaurantB->id]['permissions'];

        $this->assertContains('create_orders', $restaurantAPermissions);
        $this->assertNotContains('update_kitchen_status', $restaurantAPermissions);

        $this->assertContains('update_kitchen_status', $restaurantBPermissions);
        $this->assertNotContains('create_orders', $restaurantBPermissions);

        $this->assertSame(['kitchen'], $restaurants[$restaurantB->id]['roles']);
        $this->assertSame(['waiter'], $restaurants[$restaurantA->id]['roles']);
    }

    public function test_platform_superadmin_receives_platform_capabilities_separate_from_tenant(): void
    {
        [$organization, $owner] = $this->createTenant();

        $role = PlatformRole::query()->where('slug', 'super_admin')->firstOrFail();
        PlatformRoleAssignment::query()->create([
            'user_id' => $owner->id,
            'platform_role_id' => $role->id,
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $response->assertJsonPath('data.platform.is_platform_admin', true);
        $response->assertJsonPath('data.platform.roles', ['super_admin']);

        $platformPermissions = $response->json('data.platform.permissions');
        $this->assertContains('manage_platform_users', $platformPermissions);

        // Tenant permissions remain entirely separate — a platform
        // permission must never appear inside the organization's list.
        $orgPermissions = $response->json('data.organizations.0.permissions');
        $this->assertNotContains('manage_platform_users', $orgPermissions);
        $this->assertContains('manage_organization', $orgPermissions);
    }

    public function test_normal_tenant_user_has_empty_platform_context(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $response->assertJsonPath('data.platform.is_platform_admin', false);
        $response->assertJsonPath('data.platform.roles', []);
        $response->assertJsonPath('data.platform.permissions', []);
    }

    public function test_platform_admin_with_no_tenant_membership_receives_empty_organizations(): void
    {
        $admin = $this->createPlatformAdmin();

        $response = $this->actingAs($admin, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $response->assertJsonPath('data.organizations', []);
        $response->assertJsonPath('data.platform.is_platform_admin', true);
    }

    /**
     * Permission slugs must be deduplicated and returned in a
     * deterministic (sorted) order.
     */
    public function test_permissions_are_deduplicated_and_sorted(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization, null);

        // A second, restaurant-scoped role assignment for the same owner
        // — permissions it grants are already covered by the org-wide
        // role and must not appear twice.
        $this->assignRole($owner, 'manager', $organization, $restaurantA);
        $this->assignRole($owner, 'manager', $organization, $restaurantB);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $permissions = $response->json('data.organizations.0.permissions');

        $this->assertSame($permissions, array_values(array_unique($permissions)));
        $sorted = $permissions;
        sort($sorted);
        $this->assertSame($sorted, $permissions);
    }

    /**
     * Coarse N+1 regression guard: the query count must not scale with the
     * number of restaurants/role assignments a user has (see
     * LiveSnapshotPerformanceTest for the same pattern).
     */
    public function test_query_count_does_not_scale_with_restaurant_count(): void
    {
        [$organizationSmall, $ownerSmall] = $this->createTenant();
        for ($i = 0; $i < 2; $i++) {
            Restaurant::factory()->create(['organization_id' => $organizationSmall->id]);
        }

        [$organizationLarge, $ownerLarge] = $this->createTenant();
        for ($i = 0; $i < 25; $i++) {
            Restaurant::factory()->create(['organization_id' => $organizationLarge->id]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($ownerSmall, 'web')->getJson('/api/v1/auth/context')->assertOk();
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($ownerLarge, 'web')->getJson('/api/v1/auth/context')->assertOk();
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $smallQueryCount + 10,
            $largeQueryCount,
            "Query count scaled with restaurant count: small={$smallQueryCount} (3 restaurants), large={$largeQueryCount} (26 restaurants) — looks like an N+1.",
        );
    }

    public function test_suspended_organization_still_reports_its_true_status(): void
    {
        [$organization, $owner] = $this->createTenant();
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/auth/context')
            ->assertOk();

        $response->assertJsonPath('data.organizations.0.status', 'suspended');
    }
}
