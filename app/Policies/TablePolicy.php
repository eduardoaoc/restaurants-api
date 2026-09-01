<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;

/**
 * Authorizes table management within the active organization.
 *
 * Viewing is available to anyone holding manage_tables OR close_bill
 * (owner/manager/waiter via manage_tables, cashier via close_bill), so a
 * cashier can see the floor without being able to create/edit tables.
 * Creating, editing, and opening a table require manage_tables.
 */
class TablePolicy
{
    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canView($user, $restaurant->organization);
    }

    public function view(User $user, Table $table): bool
    {
        return $this->canView($user, $table->restaurant->organization);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->canManage($user, $table->restaurant->organization);
    }

    public function open(User $user, Table $table): bool
    {
        return $this->canManage($user, $table->restaurant->organization);
    }

    private function canView(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization)
            && ($user->hasPermission('manage_tables', $organization) || $user->hasPermission('close_bill', $organization));
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization)
            && $user->hasPermission('manage_tables', $organization);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
