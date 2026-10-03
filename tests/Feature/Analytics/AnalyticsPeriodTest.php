<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — Analytics period/granularity validation: default current
 * local month, custom range, invalid combinations, range limit.
 */
class AnalyticsPeriodTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

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

    public function test_default_period_is_the_current_local_month(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Europe/Madrid']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'UTC'));

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.period.from', '2026-09-01')
            ->assertJsonPath('data.period.to', '2026-09-30')
            ->assertJsonPath('data.period.granularity', 'day')
            ->assertJsonPath('data.period.timezone', 'Europe/Madrid');
    }

    public function test_custom_from_to_and_granularity_are_echoed_back(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-01-31&granularity=week")
            ->assertOk()
            ->assertJsonPath('data.period.from', '2026-01-01')
            ->assertJsonPath('data.period.to', '2026-01-31')
            ->assertJsonPath('data.period.granularity', 'week');
    }

    public function test_from_without_to_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01")
            ->assertStatus(422);
    }

    public function test_to_before_from_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-31&to=2026-01-01")
            ->assertStatus(422);
    }

    public function test_range_longer_than_366_days_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2027-01-05")
            ->assertStatus(422);
    }

    public function test_invalid_granularity_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?granularity=hour")
            ->assertStatus(422);
    }

    public function test_malformed_date_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-13-40&to=2026-01-01")
            ->assertStatus(422);
    }

    public function test_empty_restaurant_returns_200_with_zeroed_data(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '0.00')
            ->assertJsonPath('data.summary.average_ticket', '0.00')
            ->assertJsonPath('data.products.top_by_quantity', [])
            ->assertJsonPath('data.staff', []);
    }
}
