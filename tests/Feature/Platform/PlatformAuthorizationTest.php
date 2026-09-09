<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformRole;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The access matrix required by the Bloco 0 report: super_admin gets in,
 * every tenant role (including owner) is refused, and a guest is
 * unauthenticated — regardless of which /platform endpoint is hit.
 */
class PlatformAuthorizationTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_super_admin_can_access_platform_routes(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertOk();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/platform/users')
            ->assertOk();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/platform/restaurants')
            ->assertOk();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/platform/audit-logs')
            ->assertOk();
    }

    public function test_owner_cannot_access_platform_routes(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertForbidden();
    }

    public function test_manager_cannot_access_platform_routes(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'MGR-1');

        $this->actingAs($manager, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertForbidden();
    }

    public function test_waiter_cannot_access_platform_routes(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'OP-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertForbidden();
    }

    public function test_kitchen_cannot_access_platform_routes(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'OP-2');

        $this->actingAs($kitchen, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertForbidden();
    }

    public function test_cashier_cannot_access_platform_routes(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'OP-3');

        $this->actingAs($cashier, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertForbidden();
    }

    public function test_guest_receives_unauthorized_on_platform_routes(): void
    {
        $this->getJson('/api/v1/platform/organizations')->assertUnauthorized();
        $this->getJson('/api/v1/platform/users')->assertUnauthorized();
        $this->getJson('/api/v1/platform/restaurants')->assertUnauthorized();
        $this->getJson('/api/v1/platform/audit-logs')->assertUnauthorized();
    }

    /**
     * A platform admin with no platform role assignment removed (e.g. a
     * plain User who happens to also be a tenant owner elsewhere) still
     * cannot reach /platform — isPlatformAdmin() is never inferred from a
     * tenant role, only from an actual platform_role_assignments row.
     */
    public function test_a_user_who_is_both_owner_and_platform_admin_can_access_platform_routes_while_owner_alone_cannot(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertForbidden();

        $role = PlatformRole::query()->where('slug', 'super_admin')->firstOrFail();
        PlatformRoleAssignment::query()->create([
            'user_id' => $owner->id,
            'platform_role_id' => $role->id,
        ]);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/platform/organizations')
            ->assertOk();
    }
}
