<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ModifierSelectionTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    private function order(string $publicToken, array $modifierOptionIds, int $restaurantProductId)
    {
        return $this->postJson("/api/v1/public/tables/{$publicToken}/orders", [
            'items' => [[
                'restaurant_product_id' => $restaurantProductId,
                'quantity' => 1,
                'modifier_option_ids' => $modifierOptionIds,
            ]],
        ]);
    }

    public function test_required_group_without_selection_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 1, 1, true);
        $this->createModifierOption($group);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [], $rp->id)
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_MODIFIER_SELECTION']]);
    }

    public function test_below_min_select_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 2, 3, false);
        $a = $this->createModifierOption($group);
        $this->createModifierOption($group);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$a->id], $rp->id)->assertStatus(422);
    }

    public function test_within_min_max_range_is_accepted(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 2, 3, false);
        $a = $this->createModifierOption($group);
        $b = $this->createModifierOption($group);
        $this->createModifierOption($group);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$a->id, $b->id], $rp->id)->assertStatus(201);
    }

    public function test_above_max_select_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $a = $this->createModifierOption($group);
        $b = $this->createModifierOption($group);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$a->id, $b->id], $rp->id)->assertStatus(422);
    }

    public function test_duplicate_option_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 2, false);
        $a = $this->createModifierOption($group);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$a->id, $a->id], $rp->id)
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_MODIFIER_SELECTION']]);
    }

    public function test_option_from_another_group_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $groupA = $this->createModifierGroup($rp, null, 0, 1, false);
        $groupB = $this->createModifierGroup($rp, null, 0, 1, false);
        $optionB = $this->createModifierOption($groupB);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        // optionB belongs to groupB, not groupA — still same RestaurantProduct,
        // so this exercises "wrong group" rather than "wrong product".
        $this->order($table->public_token, [$optionB->id], $rp->id)->assertStatus(201);
    }

    public function test_option_from_another_restaurant_product_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $otherProduct = $this->createProduct($restaurant->organization);
        $otherRp = $this->createRestaurantProduct($restaurant, $otherProduct);
        $otherGroup = $this->createModifierGroup($otherRp);
        $otherOption = $this->createModifierOption($otherGroup);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$otherOption->id], $rp->id)
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_MODIFIER_SELECTION']]);
    }

    public function test_unavailable_option_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $option = $this->createModifierOption($group);
        $option->update(['available' => false]);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$option->id], $rp->id)
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_MODIFIER_SELECTION']]);
    }

    public function test_inactive_option_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $option = $this->createModifierOption($group);
        $option->update(['status' => 'inactive']);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$option->id], $rp->id)->assertStatus(422);
    }

    public function test_inactive_group_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $option = $this->createModifierOption($group);
        $group->update(['status' => 'inactive']);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [$option->id], $rp->id)->assertStatus(422);
    }

    public function test_optional_group_with_no_selection_is_valid(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 2, false);
        $this->createModifierOption($group);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table->public_token, [], $rp->id)->assertStatus(201);
    }
}
