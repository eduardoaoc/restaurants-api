<?php

namespace Tests\Feature\RestaurantSettings;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 8.2A — RestaurantSettings::waiter_table_management_enabled itself.
 * Its effect on table mutations is covered by TableStructureManagementTest.
 */
class WaiterTableManagementSettingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_defaults_to_true(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->assertTrue($restaurant->settings()->firstOrFail()->waiter_table_management_enabled);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.settings.waiter_table_management_enabled', true);
    }

    public function test_column_default_is_true_for_rows_that_never_set_it(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->delete();

        // Simulates a pre-8.2A row: inserted without the column at all.
        $id = RestaurantSettings::query()->insertGetId([
            'organization_id' => $restaurant->organization_id,
            'restaurant_id' => $restaurant->id,
            'default_locale' => RestaurantSettings::DEFAULT_LOCALE,
            'enabled_locales' => json_encode(RestaurantSettings::DEFAULT_ENABLED_LOCALES),
            'currency' => RestaurantSettings::DEFAULT_CURRENCY,
            'timezone' => RestaurantSettings::DEFAULT_TIMEZONE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(RestaurantSettings::query()->findOrFail($id)->waiter_table_management_enabled);
    }

    public function test_manager_can_disable_and_it_is_audited(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.settings.waiter_table_management_enabled', false);

        $this->assertFalse($restaurant->settings()->firstOrFail()->waiter_table_management_enabled);

        $audit = AuditLog::query()
            ->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)
            ->where('restaurant_id', $restaurant->id)
            ->sole();
        $this->assertEquals(['old' => true, 'new' => false], $audit->changes['waiter_table_management_enabled']);
    }

    public function test_rejects_non_boolean(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('waiter_table_management_enabled');
    }

    public function test_waiter_cannot_change_the_setting(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['waiter_table_management_enabled' => false]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => true])
            ->assertForbidden();

        $this->assertFalse($restaurant->settings()->firstOrFail()->waiter_table_management_enabled);
    }

    public function test_setting_is_isolated_per_restaurant(): void
    {
        [$organization, $owner, $malvarrosa] = $this->createTenant();
        $ruzafa = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$malvarrosa->id}/settings", ['waiter_table_management_enabled' => false])
            ->assertOk();

        $this->assertFalse($malvarrosa->settings()->firstOrFail()->waiter_table_management_enabled);
        $this->assertTrue($ruzafa->settings()->firstOrFail()->waiter_table_management_enabled);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$ruzafa->id}/settings")
            ->assertJsonPath('data.settings.waiter_table_management_enabled', true);
    }
}
