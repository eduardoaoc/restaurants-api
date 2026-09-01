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
}
