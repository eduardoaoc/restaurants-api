<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * The roles available in the system, keyed by slug.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'waiter' => 'Waiter',
        'kitchen' => 'Kitchen',
        'cashier' => 'Cashier',
    ];

    /**
     * Seed the application's roles.
     */
    public function run(): void
    {
        foreach (self::ROLES as $slug => $name) {
            Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }
    }
}
