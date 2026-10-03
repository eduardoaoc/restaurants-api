<?php

namespace Database\Seeders;

use App\Models\PlatformRole;
use Illuminate\Database\Seeder;

class PlatformRoleSeeder extends Seeder
{
    /**
     * Platform-level roles, keyed by slug.
     *
     * MVP ships only super_admin. The table/seeder shape already supports
     * adding support / billing_admin / operations_admin / read_only_support
     * later as plain new rows plus a PlatformRolePermissionSeeder entry —
     * no migration is required to introduce them.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'super_admin' => 'Super Admin',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $slug => $name) {
            PlatformRole::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }
    }
}
