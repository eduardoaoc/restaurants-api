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
        'close_bill',
    ];

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization) && $this->hasAnyPermission($user, $organization, self::VIEW_PERMISSIONS);
    }

    public function view(User $user, Order $order): bool
    {
        $organization = $order->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $this->hasAnyPermission($user, $organization, self::VIEW_PERMISSIONS)
            && RestaurantScope::canAccessRestaurant($user, $order->restaurant);
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
        $organization = $order->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $user->hasPermission('approve_customer_orders', $organization)
            && RestaurantScope::canAccessRestaurant($user, $order->restaurant);
    }

    public function reject(User $user, Order $order): bool
    {
        return $this->approve($user, $order);
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
}
