<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PlatformRoleSeeder::class,
            PlatformPermissionSeeder::class,
            PlatformRolePermissionSeeder::class,
            // Dev/test only — no-ops outside local/testing. See
            // PlatformAdminDevSeeder for why this never touches production.
            PlatformAdminDevSeeder::class,
        ]);

        // User::factory(10)->create();

        User::query()->updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => Hash::make('password'),
        ]);
    }
}
