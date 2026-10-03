<?php

namespace Tests\Feature\Permissions;

use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyClosePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_provision_daily_close_permissions_idempotently(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        // Re-provision an existing catalog, as a deploy would do.
        $this->seed([PermissionSeeder::class, RolePermissionSeeder::class]);

        $expected = [
            'owner' => [true, true, true],
            'manager' => [true, true, true],
            'waiter' => [false, true, false],
            'cashier' => [false, true, false],
            'kitchen' => [false, false, false],
        ];

        foreach ($expected as $slug => $grants) {
            $permissions = Role::query()->where('slug', $slug)->firstOrFail()
                ->permissions()->pluck('slug')->all();

            $this->assertSame(array_values(array_unique($permissions)), $permissions);

            foreach (['manage_restaurants', 'close_daily_operation', 'view_daily_closes'] as $index => $permission) {
                $this->assertSame($grants[$index], in_array($permission, $permissions, true), "{$slug}/{$permission}");
            }
        }
    }
}
