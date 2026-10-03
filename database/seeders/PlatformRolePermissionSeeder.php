<?php

namespace Database\Seeders;

use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use Illuminate\Database\Seeder;

class PlatformRolePermissionSeeder extends Seeder
{
    /**
     * Permission slugs granted to each platform role, keyed by role slug.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'super_admin' => [
            'view_platform_users',
            'manage_platform_users',
            'view_platform_organizations',
            'manage_platform_organizations',
            'view_platform_restaurants',
            'manage_platform_restaurants',
            'view_platform_audit',
        ],
    ];

    public function run(): void
    {
        foreach (self::ROLE_PERMISSIONS as $roleSlug => $permissionSlugs) {
            $role = PlatformRole::query()->where('slug', $roleSlug)->first();

            if (! $role) {
                continue;
            }

            $permissionIds = PlatformPermission::query()
                ->whereIn('slug', $permissionSlugs)
                ->pluck('id');

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
