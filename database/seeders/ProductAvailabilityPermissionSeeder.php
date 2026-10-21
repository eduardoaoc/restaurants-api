<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Additive provisioning for existing installations; does not reset role grants. */
class ProductAvailabilityPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['slug' => 'manage_product_availability'],
            ['name' => PermissionSeeder::PERMISSIONS['manage_product_availability']],
        );
        foreach (Role::query()->whereIn('slug', ['owner', 'manager', 'waiter'])->get() as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }
}
