<?php

namespace Tests\Feature\RestaurantSettings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RestaurantSettingsValidationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['default_locale' => 'fr-FR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_locale');
    }

    public function test_empty_enabled_locales_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['enabled_locales' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled_locales');
    }

    public function test_duplicate_locale_in_enabled_locales_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
                'enabled_locales' => ['es-ES', 'es-ES'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled_locales.0');
    }

    public function test_unsupported_locale_inside_enabled_locales_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
                'enabled_locales' => ['es-ES', 'de-DE'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled_locales.1');
    }

    /**
     * The FINAL merged state is validated, not just the field sent: this
     * removes es-ES from enabled_locales without changing default_locale
     * (still es-ES), which would leave the default outside the enabled
     * set.
     */
    public function test_removing_the_current_default_locale_from_enabled_locales_without_a_new_default_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
                'enabled_locales' => ['ca-ES-valencia', 'en-GB'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_locale');
    }

    public function test_changing_default_locale_together_with_a_consistent_enabled_locales_is_accepted(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
                'default_locale' => 'en-GB',
                'enabled_locales' => ['ca-ES-valencia', 'en-GB'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.default_locale', 'en-GB')
            ->assertJsonPath('data.settings.enabled_locales', ['ca-ES-valencia', 'en-GB']);
    }

    public function test_invalid_currency_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['currency' => 'USD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['timezone' => 'UTC+1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    public function test_invalid_boolean_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_call_enabled' => 'yes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('waiter_call_enabled');
    }
}
