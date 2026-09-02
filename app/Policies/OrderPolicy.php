<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Table;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes operational access to orders.
 *
 * Two independent checks compose every ability here: organization
 * membership + permission (the existing project-wide pattern), and
 * restaurant scope (RestaurantScope) — organization membership alone is
 * not enough for operational staff, who are tied to a specific restaurant
 * via their user_roles/restaurant_users links. Controllers additionally
 * scope their queries by RestaurantScope so an out-of-scope order/table
 * resolves as "not found" (404) rather than merely "forbidden" (403).
 */
class OrderPolicy
{
    /**
     * Any of these permissions grants read access to the operational
     * orders list/detail — the same "OR" pattern already used by
     * TablePolicy for viewing tables.
     */
    private const VIEW_PERMISSIONS = [
        'create_orders',
        'approve_customer_orders',
        'update_kitchen_status',
        'serve_orders',
        'close_bill',
    ];

    /**
     * Any of these permissions grants visibility into the Kitchen Display
     * queue — narrower than the full orders list, since it's specifically
     * about the confirmed/accepted/preparing/ready lifecycle.
     */
    private const KITCHEN_VIEW_PERMISSIONS = [
        'update_kitchen_status',
        'serve_orders',
        'approve_customer_orders',
    ];

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization) && $this->hasAnyPermission($user, $organization, self::VIEW_PERMISSIONS);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->hasAnyPermissionInRestaurantScope($user, $order, self::VIEW_PERMISSIONS);
    }

    public function viewKitchen(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization) && $this->hasAnyPermission($user, $organization, self::KITCHEN_VIEW_PERMISSIONS);
    }

    public function create(User $user, Table $table): bool
    {
        $organization = $table->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $user->hasPermission('create_orders', $organization)
            && RestaurantScope::canAccessRestaurant($user, $table->restaurant);
    }

    public function approve(User $user, Order $order): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $order, 'approve_customer_orders');
    }

    public function reject(User $user, Order $order): bool
    {
        return $this->approve($user, $order);
    }

    /**
     * confirmed -> accepted, accepted -> preparing, preparing -> ready:
     * kitchen staff's own permission.
     */
    public function accept(User $user, Order $order): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $order, 'update_kitchen_status');
    }

    public function prepare(User $user, Order $order): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $order, 'update_kitchen_status');
    }

    public function markReady(User $user, Order $order): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $order, 'update_kitchen_status');
    }

    /**
     * ready -> served: handing the order to the customer is the waiter's
     * job, not the kitchen's — a distinct permission from update_kitchen_status.
     */
    public function serve(User $user, Order $order): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $order, 'serve_orders');
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasAnyPermission(User $user, Organization $organization, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission, $organization)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermissionInRestaurantScope(User $user, Order $order, string $permission): bool
    {
        $organization = $order->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $user->hasPermission($permission, $organization)
            && RestaurantScope::canAccessRestaurant($user, $order->restaurant);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasAnyPermissionInRestaurantScope(User $user, Order $order, array $permissions): bool
    {
        $organization = $order->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $this->hasAnyPermission($user, $organization, $permissions)
            && RestaurantScope::canAccessRestaurant($user, $order->restaurant);
    }
}
