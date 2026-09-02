<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Explicit cross-tenant security scenario for modifiers:
 *
 * Organization A / Restaurant A / Product A / RestaurantProduct A / ModifierGroup A / ModifierOption A
 * Organization B / Restaurant B / Product B / RestaurantProduct B / ModifierGroup B / ModifierOption B
 *
 * User A must never read, update, or add options to anything in Organization B.
 */
class ModifierApiIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_a_cannot_view_modifier_group_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/modifier-groups/{$groupB->id}")
            ->assertNotFound();
    }

    public function test_user_a_cannot_update_modifier_group_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/modifier-groups/{$groupB->id}", ['internal_name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('modifier_groups', ['internal_name' => 'Pwned']);
    }

    public function test_user_a_cannot_add_an_option_to_modifier_group_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/modifier-groups/{$groupB->id}/options", [
                'internal_name' => 'Pwned',
                'translations' => [['locale' => 'en', 'name' => 'Pwned']],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('modifier_options', ['internal_name' => 'Pwned']);
    }

    public function test_user_a_cannot_view_modifier_option_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);
        $optionB = $this->createModifierOption($groupB, 'Bacon');

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/modifier-options/{$optionB->id}")
            ->assertNotFound();
    }

    public function test_user_a_cannot_update_modifier_option_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);
        $optionB = $this->createModifierOption($groupB, 'Bacon');

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/modifier-options/{$optionB->id}", ['internal_name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('modifier_options', ['internal_name' => 'Pwned']);
    }

    public function test_user_a_cannot_list_modifier_groups_of_restaurant_product_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProductB->id}/modifier-groups")
            ->assertNotFound();
    }

    /**
     * Section 50, second scenario: Owner A must never be able to create a
     * modifier group directly on a RestaurantProduct belonging to Organization B.
     */
    public function test_owner_a_cannot_create_a_modifier_group_on_restaurant_product_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProductB->id}/modifier-groups", [
                'internal_name' => 'Pwned',
                'max_select' => 1,
                'translations' => [['locale' => 'en', 'name' => 'Pwned']],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('modifier_groups', [
            'restaurant_product_id' => $restaurantProductB->id,
            'internal_name' => 'Pwned',
        ]);
    }
}
