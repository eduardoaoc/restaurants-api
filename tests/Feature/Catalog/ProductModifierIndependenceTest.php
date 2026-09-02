<?php

namespace Tests\Feature\Catalog;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proves modifiers are NOT tied to Product — they are tied to
 * RestaurantProduct. The same Product used by two RestaurantProducts
 * (one per Restaurant, same Organization) can have completely independent
 * modifier configurations, because there is no direct Product -> ModifierGroup
 * foreign key: modifier_groups.restaurant_product_id is the only link.
 */
class ProductModifierIndependenceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_adding_a_modifier_group_to_one_restaurant_product_does_not_affect_another_selling_the_same_product(): void
    {
        [$organization, $owner, $restaurant1] = $this->createTenant();
        $restaurant2 = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $product = $this->createProduct($organization, 'Hamburguer Clássico');

        $restaurantProduct1 = $this->createRestaurantProduct($restaurant1, $product, 12.90);
        $restaurantProduct2 = $this->createRestaurantProduct($restaurant2, $product, 13.90);

        // Modifier groups only for restaurant 1's listing of the product.
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct1->id}/modifier-groups", [
                'internal_name' => 'Ponto da carne',
                'required' => true,
                'min_select' => 1,
                'max_select' => 1,
                'translations' => [['locale' => 'pt', 'name' => 'Ponto da carne']],
            ])
            ->assertCreated();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct1->id}/modifier-groups", [
                'internal_name' => 'Extras',
                'max_select' => 4,
                'translations' => [['locale' => 'pt', 'name' => 'Adicionais']],
            ])
            ->assertCreated();

        $groupsForRestaurant1 = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProduct1->id}/modifier-groups")
            ->assertOk()
            ->json('data.modifier_groups');

        $groupsForRestaurant2 = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProduct2->id}/modifier-groups")
            ->assertOk()
            ->json('data.modifier_groups');

        $this->assertCount(2, $groupsForRestaurant1);
        $this->assertCount(0, $groupsForRestaurant2);

        // Same underlying Product, but the modifier data itself is scoped
        // to restaurant_product_id, never to product_id.
        $this->assertSame($product->id, $restaurantProduct1->fresh()->product_id);
        $this->assertSame($product->id, $restaurantProduct2->fresh()->product_id);

        $this->assertDatabaseHas('modifier_groups', ['restaurant_product_id' => $restaurantProduct1->id]);
        $this->assertDatabaseMissing('modifier_groups', ['restaurant_product_id' => $restaurantProduct2->id]);

        // Now add different modifier groups to restaurant 2's listing, and
        // confirm restaurant 1's own groups are untouched.
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct2->id}/modifier-groups", [
                'internal_name' => 'Molhos',
                'max_select' => 2,
                'translations' => [['locale' => 'pt', 'name' => 'Molhos']],
            ])
            ->assertCreated();

        $this->assertCount(2, $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProduct1->id}/modifier-groups")
            ->json('data.modifier_groups'));

        $this->assertCount(1, $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProduct2->id}/modifier-groups")
            ->json('data.modifier_groups'));
    }
}
