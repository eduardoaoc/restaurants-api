<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canManage($user, $product->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canManage($user, $product->organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_products', $organization);
    }
}
