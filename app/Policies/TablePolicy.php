<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
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
 * Table STRUCTURE (CARTA 8.2A) — creating a table, changing its
 * name/number/capacity — additionally goes through canManageStructure():
 * a user holding manage_floor_plan (the existing structural/admin
 * capability: owner/manager) always may; a user holding manage_tables
 * without it (waiter) may only while the restaurant's
 * RestaurantSettings::waiter_table_management_enabled is true. Viewing,
 * opening a session, status and every other operational flow keep their
 * plain canView()/canManage() gates and are never affected by the toggle.
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
        return $this->manageStructure($user, $restaurant);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->canManage($user, $table->restaurant);
    }

    /**
     * Changing a structural field of an existing table (see
     * TableController::STRUCTURAL_FIELDS) — on top of update().
     */
    public function updateStructure(User $user, Table $table): bool
    {
        return $this->manageStructure($user, $table->restaurant);
    }

    /**
     * Whether the user may change the table STRUCTURE of this restaurant
     * at all — what create() and updateStructure() enforce. Also projected
     * per restaurant into GET /auth/context as can_manage_table_structure
     * (AuthContextBuilder asks this ability through the Gate, so the UX
     * hint and the enforcement can never diverge).
     */
    public function manageStructure(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant)
            && $this->canManageStructure($user, $restaurant);
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

    /**
     * The single source of truth for the CARTA 8.2A toggle. The setting is
     * read from the database on every request (never from client state),
     * so flipping it takes effect on the very next mutation (TOCTOU-safe).
     * A missing settings row falls back to the column's own default.
     */
    private function canManageStructure(User $user, Restaurant $restaurant): bool
    {
        if ($user->hasPermission('manage_floor_plan', $restaurant->organization)) {
            return true;
        }

        return $restaurant->settings?->waiter_table_management_enabled
            ?? RestaurantSettings::DEFAULT_WAITER_TABLE_MANAGEMENT_ENABLED;
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
