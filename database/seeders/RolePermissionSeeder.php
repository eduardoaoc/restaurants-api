<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Permission slugs granted to each role, keyed by role slug.
     *
     * This mapping is a starting point for the MVP and is expected to
     * evolve as operational features are implemented.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'owner' => [
            'manage_organization',
            'manage_restaurants',
            'manage_users',
            'manage_menu',
            'manage_products',
            'manage_tables',
            'manage_floor_plan',
            'approve_customer_orders',
            'create_orders',
            'update_kitchen_status',
            'serve_orders',
            'handle_table_requests',
            'record_payments',
            'close_bill',
            'view_reports',
            'view_audit',
            'manage_staff_reviews',
            'assign_waiters',
            'manage_staff_shifts',
            'transfer_tables',
            'view_operations',
        ],
        'manager' => [
            'manage_restaurants',
            'manage_users',
            'manage_menu',
            'manage_products',
            'manage_tables',
            'manage_floor_plan',
            'approve_customer_orders',
            'create_orders',
            'update_kitchen_status',
            'serve_orders',
            'handle_table_requests',
            'record_payments',
            'close_bill',
            'view_reports',
            'view_audit',
            'manage_staff_reviews',
            'assign_waiters',
            'manage_staff_shifts',
            'transfer_tables',
            'view_operations',
        ],
        'waiter' => [
            'create_orders',
            'approve_customer_orders',
            'serve_orders',
            'handle_table_requests',
            'record_payments',
            'manage_tables',
            'close_bill',
            // Read-only: the live operations snapshot a waiter needs to see
            // tables/sessions/orders in real time while working the floor.
            // Never grants any write — see RestaurantOperationsController
            // (a single GET, no mutating side effect) and the Passo 3.2 fix
            // report for the audit that confirmed this.
            'view_operations',
        ],
        'kitchen' => [
            'update_kitchen_status',
        ],
        'cashier' => [
            'handle_table_requests',
            'record_payments',
            'close_bill',
        ],
    ];

    /**
     * Seed the role/permission relationships.
     */
    public function run(): void
    {
        foreach (self::ROLE_PERMISSIONS as $roleSlug => $permissionSlugs) {
            $role = Role::query()->where('slug', $roleSlug)->first();

            if (! $role) {
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('slug', $permissionSlugs)
                ->pluck('id');

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
