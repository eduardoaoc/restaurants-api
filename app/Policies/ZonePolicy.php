<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Zone;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes Zone management within the active organization (Bloco 1:
 * Floor Plan). Same shape as FloorPolicy — see its docblock for the
 * view/manage permission split rationale.
 */
class ZonePolicy
{
    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canView($user, $restaurant);
    }

    public function view(User $user, Zone $zone): bool
    {
        return $this->canView($user, $zone->restaurant);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function update(User $user, Zone $zone): bool
    {
        return $this->canManage($user, $zone->restaurant);
    }

    public function delete(User $user, Zone $zone): bool
    {
        return $this->canManage($user, $zone->restaurant);
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
