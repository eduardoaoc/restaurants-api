<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * The permissions available in the system, keyed by slug.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'manage_organization' => 'Manage organization',
        'manage_restaurants' => 'Manage restaurants',
        'manage_users' => 'Manage users',
        'manage_menu' => 'Manage menu',
        'manage_products' => 'Manage products',
        'manage_tables' => 'Manage tables',
        'approve_customer_orders' => 'Approve customer orders',
        'create_orders' => 'Create orders',
        'update_kitchen_status' => 'Update kitchen status',
        'serve_orders' => 'Serve orders to the customer',
        'handle_table_requests' => 'Handle table requests (call waiter, request bill)',
        'record_payments' => 'Record manual payments',
        'close_bill' => 'Close bill',
        'view_reports' => 'View reports',
        'view_audit' => 'View audit log',
    ];

    /**
     * Seed the application's permissions.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }
    }
}
