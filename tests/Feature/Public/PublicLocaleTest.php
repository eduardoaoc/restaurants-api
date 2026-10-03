<?php

namespace Tests\Feature\Public;

use App\Models\CategoryProduct;
use App\Support\Locale\LocaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * es-ES / ca-ES-valencia / en-GB support (Bloco 18's 3-segment locale) and
 * the enabled_locales allowlist for the public surface.
 */
class PublicLocaleTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_three_segment_locale_pattern_is_accepted(): void
    {
        $this->assertTrue(LocaleResolver::isValidFormat('ca-ES-valencia'));
        $this->assertTrue(LocaleResolver::isValidFormat('es-ES'));
        $this->assertTrue(LocaleResolver::isValidFormat('es'));
    }

    public function test_three_segment_locale_normalizes_language_lower_region_upper_variant_lower(): void
    {
        $this->assertSame('ca-ES-valencia', LocaleResolver::normalize('CA-es-VALENCIA'));
    }

    public function test_menu_accepts_ca_es_valencia_locale(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [
            ['locale' => 'ca-ES-valencia', 'name' => 'Categoria Valenciana'],
        ]);
        $product = $this->createProduct($organization, null, [
            ['locale' => 'ca-ES-valencia', 'name' => 'Producte'],
        ]);
        $rp = $this->createRestaurantProduct($restaurant, $product);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);
        $table = $this->createTable($restaurant);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=ca-ES-valencia")
            ->assertOk();

        $this->assertSame('ca-ES-valencia', $response->json('data.locale'));
        $this->assertSame('Categoria Valenciana', $response->json('data.menu.categories.0.name'));
    }

    public function test_menu_accepts_en_gb_locale(): void
    {
        [, , $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=en-GB")
            ->assertOk()
            ->assertJsonPath('data.locale', 'en-GB');
    }

    // --- enabled_locales allowlist -----------------------------------

    public function test_explicit_locale_outside_enabled_locales_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB']]);
        $this->createMenu($restaurant);
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=ca-ES-valencia")
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_LOCALE']]);
    }

    public function test_enabled_locale_is_accepted(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB']]);
        $this->createMenu($restaurant);
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=en-GB")
            ->assertOk();
    }

    public function test_no_explicit_locale_is_never_blocked_by_enabled_locales(): void
    {
        [, , $restaurant] = $this->createTenant();
        // Even with a restrictive allowlist, omitting ?locale= must keep
        // working exactly as before Bloco 18 — the allowlist only governs
        // an EXPLICIT selection, never the implicit/default resolution.
        $restaurant->settings()->update(['enabled_locales' => ['en-GB']]);
        $this->createMenu($restaurant);
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
    }

    public function test_public_restaurant_exposes_default_locale_and_enabled_locales(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();

        $response
            ->assertJsonPath('data.restaurant.default_locale', 'es-ES')
            ->assertJsonPath('data.restaurant.enabled_locales', ['es-ES', 'ca-ES-valencia', 'en-GB']);
    }
}
