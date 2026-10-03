<?php

namespace Database\Seeders;

use App\Models\PlatformPermission;
use Illuminate\Database\Seeder;

class PlatformPermissionSeeder extends Seeder
{
    /**
     * Platform-level permissions, keyed by slug. Named after the tenant
     * `permissions` convention (verb_resource, snake_case) with a
     * `platform_` prefix — the tables never collide, the prefix is purely
     * so a slug can never be confused for a tenant one when read out of
     * context (an AuditLog row, a log line, ...).
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'view_platform_users' => 'View users globally',
        'manage_platform_users' => 'Manage user account status globally',
        'view_platform_organizations' => 'View organizations globally',
        'manage_platform_organizations' => 'Manage organization status and plan globally',
        'view_platform_restaurants' => 'View restaurants globally',
        'manage_platform_restaurants' => 'Manage restaurant status globally',
        'view_platform_audit' => 'View the platform-level audit log',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            PlatformPermission::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }
    }
}
