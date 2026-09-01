<?php

namespace Tests\Concerns;

use App\Actions\Staff\CreateStaffAction;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;

trait InteractsWithTenants
{
    /**
     * Seed the roles/permissions catalog used to authorize requests.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    /**
     * Attach a user to an organization and grant them a role in a given context.
     */
    protected function assignRole(User $user, string $roleSlug, Organization $organization, ?Restaurant $restaurant = null): UserRole
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            $organization->users()->attach($user);
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant?->id,
        ]);
    }

    /**
     * Create a fully-wired operational staff member (user + organization_users
     * + restaurant_users + user_roles) via the real creation action.
     */
    protected function createStaff(
        Organization $organization,
        Restaurant $restaurant,
        string $role,
        string $subId,
        ?string $email = null,
        ?string $name = null,
    ): User {
        return app(CreateStaffAction::class)->execute($organization, [
            'name' => $name ?? 'Staff Member',
            'email' => $email ?? sprintf('staff-%s@example.com', uniqid()),
            'password' => 'password123',
            'restaurant_id' => $restaurant->id,
            'role' => $role,
            'sub_id' => $subId,
        ]);
    }
}
