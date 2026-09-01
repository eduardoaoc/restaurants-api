<?php

namespace Tests\Feature\Catalog;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proves the core reuse model of Bloco 7: a single Product (organization
 * catalog entry) can be priced and enabled independently per Restaurant.
 */
class ProductReuseTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_the_same_product_has_independent_price_and_availability_per_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $product = $this->createProduct($organization, 'Coca-Cola 330ml');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/products", [
                'product_id' => $product->id,
                'price' => 3.00,
            ])->assertCreated();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/products", [
                'product_id' => $product->id,
                'price' => 3.50,
            ])->assertCreated();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantC->id}/products", [
                'product_id' => $product->id,
                'price' => 3.20,
                'available' => false,
            ])->assertCreated();

        $this->assertDatabaseHas('restaurant_products', [
            'restaurant_id' => $restaurantA->id, 'product_id' => $product->id, 'price' => 3.00, 'available' => true,
        ]);
        $this->assertDatabaseHas('restaurant_products', [
            'restaurant_id' => $restaurantB->id, 'product_id' => $product->id, 'price' => 3.50, 'available' => true,
        ]);
        $this->assertDatabaseHas('restaurant_products', [
            'restaurant_id' => $restaurantC->id, 'product_id' => $product->id, 'price' => 3.20, 'available' => false,
        ]);

        // Changing one restaurant's price/availability never touches the others.
        $restaurantProductC = $product->restaurantProducts()->where('restaurant_id', $restaurantC->id)->firstOrFail();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurant-products/{$restaurantProductC->id}", ['available' => true])
            ->assertOk();

        $this->assertDatabaseHas('restaurant_products', [
            'restaurant_id' => $restaurantA->id, 'product_id' => $product->id, 'price' => 3.00,
        ]);
        $this->assertDatabaseHas('restaurant_products', [
            'restaurant_id' => $restaurantC->id, 'product_id' => $product->id, 'available' => true,
        ]);

        $this->assertSame(3, $product->restaurantProducts()->count());
    }
}
