<?php

namespace Tests\Feature\Catalog;

use App\Models\CategoryProduct;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Model-level invariants (defense in depth beyond FormRequest validation),
 * exercised directly against the models — mirrors the pattern already used
 * for UserRole (Bloco 3) and TableSession (Bloco 6).
 */
class CatalogIntegrityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_a_restaurant_product_must_belong_to_the_same_organization_as_the_restaurant(): void
    {
        [$organizationA, , $restaurantA] = $this->createTenant();
        [$organizationB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);

        $this->expectException(InvalidArgumentException::class);

        RestaurantProduct::query()->create([
            'restaurant_id' => $restaurantA->id,
            'product_id' => $productB->id,
            'price' => 5,
            'available' => true,
        ]);
    }

    public function test_a_category_product_must_belong_to_the_same_restaurant_as_the_category(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $menuA = $this->createMenu($restaurantA);
        $categoryA = $this->createCategory($menuA);

        $product = $this->createProduct($organization);
        $restaurantProductB = $this->createRestaurantProduct($restaurantB, $product);

        $this->expectException(InvalidArgumentException::class);

        CategoryProduct::query()->create([
            'category_id' => $categoryA->id,
            'restaurant_product_id' => $restaurantProductB->id,
            'sort_order' => 0,
        ]);
    }
}
