<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — peak_hours: TableSessions started per LOCAL hour, always 24
 * zero-filled buckets, never UTC hour.
 */
class AnalyticsPeakHoursTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

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

    public function test_sessions_are_bucketed_by_local_hour_not_utc(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Europe/Madrid']);
        $table = $this->createTable($restaurant);

        // 21:50 UTC = 23:50 Europe/Madrid (CEST, +2) -> local hour 23.
        Carbon::setTestNow(Carbon::parse('2026-06-15 21:50:00', 'UTC'));
        $this->openSession($table, $owner);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-06-01&to=2026-06-30")
            ->assertOk();

        $hours = collect($response->json('data.peak_hours'))->keyBy('hour');
        $this->assertCount(24, $hours);
        $this->assertSame(1, $hours->get(23)['sessions_started']);
        $this->assertSame(0, $hours->get(21)['sessions_started']);
    }

    public function test_all_24_hours_are_present_even_with_no_activity(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk();

        $hours = collect($response->json('data.peak_hours'))->pluck('hour')->sort()->values()->all();
        $this->assertSame(range(0, 23), $hours);
    }
}
