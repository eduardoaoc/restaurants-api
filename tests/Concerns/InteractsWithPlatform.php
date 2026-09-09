<?php

namespace Tests\Concerns;

use App\Models\PlatformRole;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Database\Seeders\PlatformPermissionSeeder;
use Database\Seeders\PlatformRolePermissionSeeder;
use Database\Seeders\PlatformRoleSeeder;

/**
 * Platform-authorization test fixtures — deliberately separate from
 * InteractsWithTenants (which seeds/wires the tenant roles/permissions
 * catalog). Keeping them apart in tests mirrors the production separation
 * between `roles`/`permissions` and `platform_roles`/`platform_permissions`.
 */
trait InteractsWithPlatform
{
    /**
     * Seed the platform roles/permissions catalog.
     */
    protected function seedPlatformRolesAndPermissions(): void
    {
        $this->seed([PlatformRoleSeeder::class, PlatformPermissionSeeder::class, PlatformRolePermissionSeeder::class]);
    }

    /**
     * Create a User holding the given platform role — a platform admin
     * deliberately has no organization/restaurant membership of its own
     * unless a test adds one explicitly.
     */
    protected function createPlatformAdmin(string $roleSlug = 'super_admin'): User
    {
        $user = User::factory()->create();

        $role = PlatformRole::query()->where('slug', $roleSlug)->firstOrFail();

        PlatformRoleAssignment::query()->create([
            'user_id' => $user->id,
            'platform_role_id' => $role->id,
        ]);

        return $user;
    }
}
