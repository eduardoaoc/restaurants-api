<?php

namespace App\Policies;

use App\Models\Floor;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes Floor management within the active organization (Bloco 1:
 * Floor Plan).
 *
 * Viewing mirrors TablePolicy: anyone holding manage_tables OR close_bill
 * can read the floor plan (owner/manager/waiter via manage_tables, cashier
 * via close_bill) — a waiter needs to see the map even though they cannot
 * edit it. Creating/editing/deleting a Floor requires the dedicated
 * manage_floor_plan permission (owner/manager only) — deliberately NOT
 * manage_tables, which waiter also holds: editing the physical layout of
 * the restaurant is a distinct capability from editing an individual
 * table's name/number/status.
 */
class FloorPolicy
{
    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canView($user, $restaurant);
    }

    public function view(User $user, Floor $floor): bool
    {
        return $this->canView($user, $floor->restaurant);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function update(User $user, Floor $floor): bool
    {
        return $this->canManage($user, $floor->restaurant);
    }

    public function delete(User $user, Floor $floor): bool
    {
        return $this->canManage($user, $floor->restaurant);
    }

    private function canView(User $user, Restaurant $restaurant): bool
    {
        return $this->belongsTo($user, $restaurant->organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && ($user->hasPermission('manage_tables', $restaurant->organization)
                || $user->hasPermission('close_bill', $restaurant->organization));
    }

    private function canManage(User $user, Restaurant $restaurant): bool
    {
        return $this->belongsTo($user, $restaurant->organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && $user->hasPermission('manage_floor_plan', $restaurant->organization);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
