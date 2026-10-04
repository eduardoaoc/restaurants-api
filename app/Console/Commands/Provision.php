<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlatformPermissionSeeder;
use Database\Seeders\PlatformRolePermissionSeeder;
use Database\Seeders\PlatformRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Provision extends Command
{
    protected $signature = 'aforo:provision';

    protected $description = 'Provision roles and permissions without creating users or demo data';

    public function handle(): int
    {
        DB::transaction(function (): void {
            foreach ([
                RoleSeeder::class,
                PermissionSeeder::class,
                RolePermissionSeeder::class,
                PlatformRoleSeeder::class,
                PlatformPermissionSeeder::class,
                PlatformRolePermissionSeeder::class,
            ] as $seeder) {
                $this->laravel->make($seeder)->__invoke();
            }
        });

        $this->info('Roles and permissions provisioned. Existing grants were preserved.');

        return self::SUCCESS;
    }
}
