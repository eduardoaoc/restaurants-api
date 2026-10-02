<?php

namespace Tests\Feature\DayClose;

use App\Models\AuditLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — Cierre Diario RestaurantSettings.
 */
class DayCloseSettingsTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_defaults(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.settings.business_day_cutoff_time', '06:00')
            ->assertJsonPath('data.settings.default_opening_float', null)
            ->assertJsonPath('data.settings.cash_difference_note_threshold', '5.00')
            ->assertJsonPath('data.settings.accept_delay_threshold_minutes', 10)
            ->assertJsonPath('data.settings.preparation_delay_threshold_minutes', 30)
            ->assertJsonPath('data.settings.ready_pickup_delay_threshold_minutes', 10);
    }

    public function test_delay_threshold_defaults_are_10_30_10_in_every_layer(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        // Domain default (RestaurantSettings::createDefaultsFor).
        $this->assertSame([10, 30, 10], [
            $restaurant->settings()->first()->accept_delay_threshold_minutes,
            $restaurant->settings()->first()->preparation_delay_threshold_minutes,
            $restaurant->settings()->first()->ready_pickup_delay_threshold_minutes,
        ]);

        // DB column defaults (a row inserted without the columns).
        $restaurant->settings()->delete();
        DB::table('restaurant_settings')->insert([
            'organization_id' => $restaurant->organization_id, 'restaurant_id' => $restaurant->id,
            'default_locale' => 'es-ES', 'enabled_locales' => json_encode(['es-ES']), 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('restaurant_settings')->where('restaurant_id', $restaurant->id)->first();
        $this->assertSame([10, 30, 10], [(int) $row->accept_delay_threshold_minutes, (int) $row->preparation_delay_threshold_minutes, (int) $row->ready_pickup_delay_threshold_minutes]);

        // API + snapshot thresholds.
        $this->at('2026-10-02 18:00:00');
        $this->assertSame(['accept' => 600, 'preparation' => 1800, 'ready_pickup' => 600], $this->dayClosePreview($restaurant, $owner)['delays']['thresholds_seconds']);
    }

    public function test_update_and_audit(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
            'business_day_cutoff_time' => '05:30',
            'default_opening_float' => '150.00',
            'cash_difference_note_threshold' => '2.5',
            'accept_delay_threshold_minutes' => 7,
            'preparation_delay_threshold_minutes' => 45,
            'ready_pickup_delay_threshold_minutes' => 5,
        ])->assertOk()
            ->assertJsonPath('data.settings.business_day_cutoff_time', '05:30')
            ->assertJsonPath('data.settings.default_opening_float', '150.00')
            ->assertJsonPath('data.settings.cash_difference_note_threshold', '2.50')
            ->assertJsonPath('data.settings.preparation_delay_threshold_minutes', 45);

        $changes = AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->sole()->changes;
        $this->assertEquals(['old' => '06:00', 'new' => '05:30'], $changes['business_day_cutoff_time']);
        $this->assertEquals(['old' => null, 'new' => '150.00'], $changes['default_opening_float']);
        $this->assertEquals(['old' => 30, 'new' => 45], $changes['preparation_delay_threshold_minutes']);

        $this->as($owner)->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['default_opening_float' => null])
            ->assertOk()
            ->assertJsonPath('data.settings.default_opening_float', null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'cutoff 24:00' => [['business_day_cutoff_time' => '24:00'], 'business_day_cutoff_time'],
            'cutoff 6:00' => [['business_day_cutoff_time' => '6:00'], 'business_day_cutoff_time'],
            'float negative' => [['default_opening_float' => '-1.00'], 'default_opening_float'],
            'float 3 decimals' => [['default_opening_float' => '1.005'], 'default_opening_float'],
            'float number' => [['default_opening_float' => 12.5], 'default_opening_float'],
            'threshold negative' => [['cash_difference_note_threshold' => '-0.01'], 'cash_difference_note_threshold'],
            'threshold null' => [['cash_difference_note_threshold' => null], 'cash_difference_note_threshold'],
            'accept 0' => [['accept_delay_threshold_minutes' => 0], 'accept_delay_threshold_minutes'],
            'preparation 241' => [['preparation_delay_threshold_minutes' => 241], 'preparation_delay_threshold_minutes'],
            'pickup string' => [['ready_pickup_delay_threshold_minutes' => 'ten'], 'ready_pickup_delay_threshold_minutes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_validation(array $payload, string $field): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_settings_are_isolated_per_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->as($owner)->patchJson("/api/v1/restaurants/{$restaurantA->id}/settings", ['business_day_cutoff_time' => '04:00', 'default_opening_float' => '80.00'])->assertOk();

        $this->assertSame('04:00', $restaurantA->settings()->first()->business_day_cutoff_time);
        $this->assertSame('06:00', $restaurantB->settings()->first()->business_day_cutoff_time);
        $this->assertNull($restaurantB->settings()->first()->default_opening_float);
    }

    public function test_cutoff_and_timezone_drive_the_business_date(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Asia/Tokyo', 'business_day_cutoff_time' => '04:00']);

        // 2026-10-02 18:30Z = 03:30 on 10-03 in Tokyo, before the 04:00 cutoff.
        $this->at('2026-10-02 18:30:00');
        $this->assertSame('2026-10-02', $this->dayClosePreview($restaurant, $owner)['period']['business_date']);

        // 19:00Z = 04:00 Tokyo: the new business day.
        $this->at('2026-10-02 19:00:00');
        $period = $this->dayClosePreview($restaurant, $owner)['period'];
        $this->assertSame('2026-10-03', $period['business_date']);
        $this->assertSame('2026-10-02T19:00:00Z', $period['period_started_at']);
    }

    public function test_delay_thresholds_come_from_settings(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['accept_delay_threshold_minutes' => 3, 'preparation_delay_threshold_minutes' => 4, 'ready_pickup_delay_threshold_minutes' => 5]);
        $this->at('2026-10-02 18:00:00');

        $this->assertSame(
            ['accept' => 180, 'preparation' => 240, 'ready_pickup' => 300],
            $this->dayClosePreview($restaurant, $owner)['delays']['thresholds_seconds'],
        );
    }
}
