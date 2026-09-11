<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MultilingualTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_a_product_can_have_es_en_and_pt_translations_each_appearing_once(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Coca-Cola 330ml',
                'translations' => [
                    ['locale' => 'es', 'name' => 'Coca-Cola'],
                    ['locale' => 'en', 'name' => 'Coca-Cola'],
                    ['locale' => 'pt', 'name' => 'Coca-Cola'],
                ],
            ])
            ->assertCreated();

        $locales = collect($response->json('data.product.translations'))->pluck('locale');

        $this->assertSame(3, $locales->count());
        $this->assertSame(3, $locales->unique()->count());
        $this->assertEqualsCanonicalizing(['es', 'en', 'pt'], $locales->all());
    }

    public function test_a_category_can_have_es_en_and_pt_translations_each_appearing_once(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'bebidas',
                'translations' => [
                    ['locale' => 'es', 'name' => 'Bebidas'],
                    ['locale' => 'en', 'name' => 'Drinks'],
                    ['locale' => 'pt', 'name' => 'Bebidas'],
                ],
            ])
            ->assertCreated();

        $locales = collect($response->json('data.category.translations'))->pluck('locale');

        $this->assertSame(3, $locales->count());
        $this->assertSame(3, $locales->unique()->count());
        $this->assertEqualsCanonicalizing(['es', 'en', 'pt'], $locales->all());
    }

    public function test_a_locale_outside_the_initial_three_is_still_accepted(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [
                    ['locale' => 'fr', 'name' => 'Eau'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.product.translations.0.locale', 'fr');
    }

    public function test_repeating_a_locale_on_a_category_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'bebidas',
                'translations' => [
                    ['locale' => 'pt', 'name' => 'Bebidas'],
                    ['locale' => 'pt', 'name' => 'Bebidas Again'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations');
    }

    /**
     * ca-ES-valencia is one of the three locales restaurants-web actually
     * ships (see RestaurantSettings::SUPPORTED_LOCALES) and its own public
     * menu resolution already accepts it (LocaleResolver::PATTERN). The
     * admin-facing translation validation used a stricter two-segment
     * regex that rejected this exact tag — see report Section 14.
     */
    public function test_a_category_can_have_a_ca_es_valencia_translation(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'postres',
                'translations' => [
                    ['locale' => 'es-ES', 'name' => 'Postres'],
                    ['locale' => 'ca-ES-valencia', 'name' => 'Postres (valencià)'],
                    ['locale' => 'en-GB', 'name' => 'Desserts'],
                ],
            ])
            ->assertCreated();

        $translations = collect($response->json('data.category.translations'));
        $this->assertSame(3, $translations->count());
        $this->assertSame('Postres (valencià)', $translations->firstWhere('locale', 'ca-ES-valencia')['name']);
    }

    public function test_a_product_can_have_a_ca_es_valencia_translation(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Horchata',
                'translations' => [
                    ['locale' => 'ca-ES-valencia', 'name' => 'Orxata'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.product.translations.0.locale', 'ca-ES-valencia');
    }

    public function test_a_modifier_group_and_option_can_have_a_ca_es_valencia_translation(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);

        $groupResponse = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Extras',
                'max_select' => 3,
                'translations' => [['locale' => 'ca-ES-valencia', 'name' => 'Extres']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.modifier_group.translations.0.locale', 'ca-ES-valencia');

        $modifierGroupId = $groupResponse->json('data.modifier_group.id');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/modifier-groups/{$modifierGroupId}/options", [
                'internal_name' => 'Bacon',
                'translations' => [['locale' => 'ca-ES-valencia', 'name' => 'Bacon']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.modifier_option.translations.0.locale', 'ca-ES-valencia');
    }
}
