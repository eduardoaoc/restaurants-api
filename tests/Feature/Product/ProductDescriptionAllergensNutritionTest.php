<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Carta 4.2 — description, allergens and nutrition on Product's admin API.
 * See tests/Feature/Public/PublicMenuTest.php for the public-eligibility
 * side of these same rules.
 */
class ProductDescriptionAllergensNutritionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- description --------------------------------------------------

    public function test_creating_a_product_without_description_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water']],
                'allergens' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations.0.description');
    }

    public function test_creating_a_product_with_empty_description_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => '']],
                'allergens' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations.0.description');
    }

    public function test_creating_a_product_with_whitespace_only_description_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => "   \n\t  "]],
                'allergens' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations.0.description');
    }

    public function test_creating_a_product_with_a_description_over_the_limit_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => str_repeat('a', 501)]],
                'allergens' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations.0.description');
    }

    public function test_creating_a_product_with_a_valid_description_succeeds(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => 'Still mineral water.']],
                'allergens' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.product.translations.0.description', 'Still mineral water.');
    }

    public function test_updating_a_translation_with_an_invalid_description_is_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", [
                'translations' => [['locale' => 'en', 'name' => 'Cola', 'description' => '   ']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations.0.description');
    }

    // --- allergens ------------------------------------------------------

    public function test_creating_a_product_without_allergens_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => 'Still mineral water.']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allergens');
    }

    public function test_creating_a_product_with_null_allergens_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => 'Still mineral water.']],
                'allergens' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allergens');
    }

    public function test_creating_a_product_with_an_empty_allergens_array_succeeds(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => 'Still mineral water.']],
                'allergens' => [],
            ])
            ->assertCreated();

        $this->assertSame([], $response->json('data.product.allergens'));
    }

    public function test_creating_a_product_with_known_allergen_codes_succeeds(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => ['milk', 'eggs'],
            ])
            ->assertCreated();

        $this->assertEqualsCanonicalizing(['milk', 'eggs'], $response->json('data.product.allergens'));
    }

    public function test_creating_a_product_with_an_unknown_allergen_code_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => ['lactosa'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allergens.0');
    }

    public function test_creating_a_product_with_duplicate_allergen_codes_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => ['milk', 'milk'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allergens.0');
    }

    public function test_updating_a_product_omitting_allergens_preserves_the_current_declaration(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, allergens: ['milk', 'eggs']);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", ['internal_name' => 'Renamed'])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['milk', 'eggs'], $product->refresh()->allergens);
    }

    public function test_updating_a_product_with_an_empty_allergens_array_clears_the_declaration_explicitly(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, allergens: ['milk', 'eggs']);

        $response = $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", ['allergens' => []])
            ->assertOk();

        $this->assertSame([], $response->json('data.product.allergens'));
        $this->assertSame([], $product->refresh()->allergens);
    }

    public function test_updating_a_product_replaces_the_allergen_declaration(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, allergens: ['milk']);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", ['allergens' => ['gluten', 'nuts']])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['gluten', 'nuts'], $product->refresh()->allergens);
    }

    // --- nutrition --------------------------------------------------

    public function test_creating_a_product_without_nutrition_succeeds_and_nutrition_is_null(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => 'Still mineral water.']],
                'allergens' => [],
            ])
            ->assertCreated();

        $this->assertNull($response->json('data.product.nutrition'));
    }

    public function test_creating_a_product_with_explicit_null_nutrition_succeeds(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water', 'description' => 'Still mineral water.']],
                'allergens' => [],
                'nutrition' => null,
            ])
            ->assertCreated();

        $this->assertNull($response->json('data.product.nutrition'));
    }

    public function test_creating_a_product_with_complete_nutrition_succeeds(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => ['gluten', 'milk', 'eggs'],
                'nutrition' => [
                    'calories_kcal' => 720,
                    'protein_g' => 38,
                    'carbohydrates_g' => 54,
                    'fat_g' => 39,
                    'salt_g' => 2.1,
                ],
            ])
            ->assertCreated();

        $this->assertSame([
            'basis' => 'per_serving',
            'calories_kcal' => 720,
            'protein_g' => '38.00',
            'carbohydrates_g' => '54.00',
            'fat_g' => '39.00',
            'salt_g' => '2.10',
        ], $response->json('data.product.nutrition'));
    }

    public function test_creating_a_product_with_partial_nutrition_succeeds(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => [],
                'nutrition' => ['calories_kcal' => 500],
            ])
            ->assertCreated();

        $nutrition = $response->json('data.product.nutrition');
        $this->assertSame(500, $nutrition['calories_kcal']);
        $this->assertNull($nutrition['protein_g']);
        $this->assertNull($nutrition['salt_g']);
    }

    public function test_creating_a_product_with_a_negative_nutrition_value_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => [],
                'nutrition' => ['calories_kcal' => -10],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nutrition.calories_kcal');
    }

    public function test_creating_a_product_with_an_invalid_nutrition_type_is_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Burger',
                'translations' => [['locale' => 'en', 'name' => 'Burger', 'description' => 'Beef, cheddar and house sauce.']],
                'allergens' => [],
                'nutrition' => ['calories_kcal' => 'a lot'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nutrition.calories_kcal');
    }

    public function test_nutrition_decimals_persist_with_two_decimal_places(): void
    {
        [$organization] = $this->createTenant();
        $product = $this->createProduct($organization, nutrition: ['protein_g' => 38.456]);

        $this->assertSame('38.46', $product->refresh()->protein_g);
    }

    public function test_updating_a_product_with_nutrition_replaces_the_previous_values(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, nutrition: ['calories_kcal' => 500, 'protein_g' => 20]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", [
                'nutrition' => ['calories_kcal' => 300],
            ])
            ->assertOk();

        $product->refresh();
        $this->assertSame(300, $product->calories_kcal);
        $this->assertNull($product->protein_g);
    }

    public function test_updating_a_product_omitting_nutrition_preserves_it(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, nutrition: ['calories_kcal' => 500]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", ['internal_name' => 'Renamed'])
            ->assertOk();

        $this->assertSame(500, $product->refresh()->calories_kcal);
    }

    // --- ProductResource shape ------------------------------------------

    public function test_product_resource_exposes_allergens_and_nutrition_for_admin(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, allergens: ['milk'], nutrition: ['calories_kcal' => 200]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $this->assertSame(['milk'], $response->json('data.product.allergens'));
        $this->assertSame(200, $response->json('data.product.nutrition.calories_kcal'));
    }
}
