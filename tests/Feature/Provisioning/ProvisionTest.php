<?php

namespace Tests\Feature\Provisioning;

use App\Models\Permission;
use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlatformPermissionSeeder;
use Database\Seeders\PlatformRolePermissionSeeder;
use Database\Seeders\PlatformRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProvisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_in_production_is_idempotent_and_creates_only_acl_data(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('aforo:provision')->assertSuccessful();
        $before = $this->snapshot();
        $this->artisan('aforo:provision')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('roles', count(RoleSeeder::ROLES));
        $this->assertDatabaseCount('permissions', count(PermissionSeeder::PERMISSIONS));
        $this->assertDatabaseCount('platform_roles', count(PlatformRoleSeeder::ROLES));
        $this->assertDatabaseCount('platform_permissions', count(PlatformPermissionSeeder::PERMISSIONS));
        foreach ([Role::class => RolePermissionSeeder::ROLE_PERMISSIONS, PlatformRole::class => PlatformRolePermissionSeeder::ROLE_PERMISSIONS] as $model => $matrix) {
            foreach ($matrix as $slug => $expected) {
                $actual = $model::where('slug', $slug)->firstOrFail()->permissions()->pluck('slug')->all();
                sort($expected);
                sort($actual);
                $this->assertSame($expected, $actual);
            }
        }
        foreach (['users', 'organizations', 'restaurants', 'products', 'platform_role_assignments', 'user_roles'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_existing_grants_are_preserved_and_missing_grants_are_restored(): void
    {
        $this->artisan('aforo:provision')->assertSuccessful();
        foreach ([[Role::class, Permission::class, 'kitchen', 'update_kitchen_status'], [PlatformRole::class, PlatformPermission::class, 'super_admin', 'view_platform_users']] as [$roleModel, $permissionModel, $slug, $expected]) {
            $role = $roleModel::where('slug', $slug)->firstOrFail();
            $custom = $permissionModel::create(['slug' => 'legacy_custom', 'name' => 'Legacy custom']);
            $role->permissions()->sync([$custom->id]);
            $this->artisan('aforo:provision')->assertSuccessful();
            $grants = $role->permissions()->pluck('slug')->all();
            $this->assertContains('legacy_custom', $grants);
            $this->assertContains($expected, $grants);
        }
    }

    public function test_failure_propagates_and_rolls_back_all_seeders(): void
    {
        $this->app->bind(PlatformPermissionSeeder::class, fn () => new class extends PlatformPermissionSeeder
        {
            public function run(): void
            {
                throw new RuntimeException('Provisioning failed');
            }
        });
        try {
            $this->artisan('aforo:provision')->run();
            $this->fail('Provisioning must propagate failures.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provisioning failed', $exception->getMessage());
        }
        foreach ($this->snapshot() as $rows) {
            $this->assertSame([], $rows);
        }
    }

    public function test_cli_returns_nonzero_when_provisioning_fails(): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'aforo:provision', '--no-interaction'],
            base_path(),
            ['APP_ENV' => 'production', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''],
        );
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('no such table: roles', $process->getOutput());
    }

    public function test_database_seeder_does_not_create_or_reset_users_outside_local_testing(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);
        $password = $user->password;
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->app->make(DatabaseSeeder::class)->__invoke();
            $this->assertDatabaseCount('users', 1);
            $this->assertSame($password, $user->fresh()->password);
        }
    }

    public function test_database_seeder_preserves_local_workflow(): void
    {
        $this->app->instance('env', 'local');
        $this->app->make(DatabaseSeeder::class)->__invoke();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['roles', 'permissions', 'role_permissions', 'platform_roles', 'platform_permissions', 'platform_role_permissions'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
