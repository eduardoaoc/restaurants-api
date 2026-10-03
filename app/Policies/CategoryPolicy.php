<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\Concerns\AuthorizesRestaurantScopedCatalog;

class CategoryPolicy
{
    use AuthorizesRestaurantScopedCatalog;

    /**
     * Read-only: a staff member who can create orders (waiter) needs to
     * browse categories to build a manual order, but must never be able to
     * create/update a category or attach/detach its products — see
     * update() below, still manage_menu-only. Passo 3.2 fix — see report.
     */
    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canRead($user, $restaurant);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->canRead($user, $category->menu->restaurant);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    /**
     * Also used to authorize managing the category's products
     * (POST/PATCH/DELETE /categories/{category}/products...) — deliberately
     * NOT opened to create_orders: attaching/detaching/reordering products
     * is still menu management, not order-building.
     */
    public function update(User $user, Category $category): bool
    {
        return $this->canManage($user, $category->menu->restaurant);
    }

    /**
     * Read access: manage_menu (full Carta management) OR create_orders
     * (needs the catalog to build an order).
     */
    private function canRead(User $user, Restaurant $restaurant): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurant, ['manage_menu', 'create_orders']);
    }

    /**
     * Write access: manage_menu only.
     */
    private function canManage(User $user, Restaurant $restaurant): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurant, ['manage_menu']);
    }
}
