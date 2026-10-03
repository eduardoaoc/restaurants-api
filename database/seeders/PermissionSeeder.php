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
        'manage_floor_plan' => 'Manage floor plan (floors, zones, table layout)',
        'assign_waiters' => 'Assign, reassign, or unassign the waiter responsible for a table session',
        'manage_staff_shifts' => "Start or end another staff member's operational shift, and list shifts",
        'transfer_tables' => 'Transfer an active table session from one table to another',
        'view_operations' => "View the restaurant's live operations snapshot",
        'view_activity' => "View the restaurant's operational activity feed (timeline) and its unread count",
        'approve_customer_orders' => 'Approve customer orders',
        'create_orders' => 'Create orders',
        'update_kitchen_status' => 'Update kitchen status',
        'serve_orders' => 'Serve orders to the customer',
        'handle_table_requests' => 'Handle table requests (call waiter, request bill)',
        'record_payments' => 'Record manual payments',
        'close_bill' => 'Close bill',
        'view_reports' => 'View reports',
        'view_audit' => 'View audit log',
        'manage_staff_reviews' => 'Manage staff reviews',
        'view_customer_feedback' => "View customers' post-visit feedback in detail",
        'close_daily_operation' => 'Run the Cierre Diario (preview, cash movements, close the business day)',
        'view_daily_closes' => 'View past Cierres Diarios (history, detail) and add post-close annotations',
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
