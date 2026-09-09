<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes table management within the active organization.
 *
 * Viewing is available to anyone holding manage_tables OR close_bill
 * (owner/manager/waiter via manage_tables, cashier via close_bill), so a
 * cashier can see the floor without being able to create/edit tables.
 * Creating, editing, and opening a table require manage_tables.
 *
 * Every ability also requires RestaurantScope::canAccessRestaurant() —
 * organization membership alone is not enough for operational staff. This
 * was previously missing here entirely (see TableController::tableQuery()/
 * restaurantQuery() and the Bloco 4 report) — the primary gate is now the
 * controllers' own scoped queries (404 outside scope), this is defense in
 * depth, matching every other policy in the codebase.
 */
class TablePolicy
{
    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canView($user, $restaurant);
    }

    public function view(User $user, Table $table): bool
    {
        return $this->canView($user, $table->restaurant);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->canManage($user, $table->restaurant);
    }

    public function open(User $user, Table $table): bool
    {
        return $this->canManage($user, $table->restaurant);
    }

    private function canView(User $user, Restaurant $restaurant): bool
    {
        $organization = $restaurant->organization;

        return $this->belongsTo($user, $organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && ($user->hasPermission('manage_tables', $organization) || $user->hasPermission('close_bill', $organization));
    }

    private function canManage(User $user, Restaurant $restaurant): bool
    {
        $organization = $restaurant->organization;

        return $this->belongsTo($user, $organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && $user->hasPermission('manage_tables', $organization);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
