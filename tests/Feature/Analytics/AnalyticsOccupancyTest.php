<?php

namespace Tests\Feature\Analytics;

use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — occupancy overlap math: effective_start = max(opened_at,
 * range_start), effective_end = min(closed_at ?? range_end, range_end).
 * Every test forces the restaurant's timezone to UTC (the default is
 * Europe/Madrid — see RestaurantSettings::DEFAULT_TIMEZONE) so the
 * requested range (2026-09-01 to 2026-09-01) resolves to exactly
 * [2026-09-01 00:00 UTC, 2026-09-02 00:00 UTC) — a clean 86400-second,
 * 1-table range for exact arithmetic, independent of the timezone
 * conversion this class isn't trying to test here (see
 * AnalyticsSeriesTest for that).
 */
class AnalyticsOccupancyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const RANGE = '?from=2026-09-01&to=2026-09-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeTableSession(Restaurant $restaurant, $table, User $owner, string $openedAt, ?string $closedAt): void
    {
        TableSession::create([
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'opened_by_user_id' => $owner->id,
            'closed_by_user_id' => $closedAt ? $owner->id : null,
            'guest_count' => 2,
            'status' => $closedAt ? 'closed' : 'occupied',
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
        ]);
    }

    public function test_session_fully_inside_range(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        // 10:00 -> 11:00 UTC on 2026-09-01: 3600s inside a 1-table, 86400s range.
        $this->makeTableSession($restaurant, $table, $owner, '2026-09-01 10:00:00', '2026-09-01 11:00:00');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(3600, $response->json('data.occupancy.occupied_seconds'));
        $this->assertSame(round(3600 / 86400, 4), $response->json('data.occupancy.occupancy_rate'));
    }

    public function test_session_starts_before_range_is_clamped(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        // Starts the day before, closes 2h into the range.
        $this->makeTableSession($restaurant, $table, $owner, '2026-08-31 22:00:00', '2026-09-01 02:00:00');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(2 * 3600, $response->json('data.occupancy.occupied_seconds'));
    }

    public function test_session_ends_after_range_is_clamped(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        // Starts 2h before range end, closes the next day.
        $this->makeTableSession($restaurant, $table, $owner, '2026-09-01 22:00:00', '2026-09-02 05:00:00');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(2 * 3600, $response->json('data.occupancy.occupied_seconds'));
    }

    public function test_session_crosses_the_entire_range(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        $this->makeTableSession($restaurant, $table, $owner, '2026-08-01 00:00:00', '2026-10-01 00:00:00');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(86400, $response->json('data.occupancy.occupied_seconds'));
        $this->assertSame(1, $response->json('data.occupancy.occupancy_rate'));
    }

    public function test_open_session_counts_up_to_range_end(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        // Opened 1h before range end, never closed.
        $this->makeTableSession($restaurant, $table, $owner, '2026-09-01 23:00:00', null);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(3600, $response->json('data.occupancy.occupied_seconds'));
    }

    public function test_no_tables_returns_zero_rate_without_division_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(0, $response->json('data.occupancy.occupancy_rate'));
        $this->assertSame(0, $response->json('data.occupancy.total_tables'));
    }

    public function test_no_sessions_returns_zero_occupancy(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $this->createTable($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(0, $response->json('data.occupancy.occupancy_rate'));
        $this->assertSame(0, $response->json('data.occupancy.occupied_seconds'));
    }

    public function test_session_entirely_outside_range_does_not_contribute(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        $this->makeTableSession($restaurant, $table, $owner, '2026-01-01 10:00:00', '2026-01-01 11:00:00');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/'.$restaurant->id.'/analytics'.self::RANGE)
            ->assertOk();

        $this->assertSame(0, $response->json('data.occupancy.occupied_seconds'));
    }
}
