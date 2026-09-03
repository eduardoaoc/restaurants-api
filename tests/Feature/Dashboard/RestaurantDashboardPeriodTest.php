<?php

namespace Tests\Feature\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * ReportPeriodResolver's contract: from/to required together, half-open,
 * max 366 days, default current month.
 */
class RestaurantDashboardPeriodTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_from_without_to_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-01-01")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_REPORT_PERIOD');
    }

    public function test_to_without_from_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?to=2026-01-31")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_REPORT_PERIOD');
    }

    public function test_to_before_from_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-02-01&to=2026-01-01")
            ->assertStatus(422);
    }

    public function test_period_longer_than_366_days_is_422(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2025-01-01&to=2026-01-02")
            ->assertStatus(422);
    }

    public function test_default_period_is_the_current_calendar_month(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->travelTo(CarbonImmutable::create(2026, 9, 15));

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.period.from', '2026-09-01')
            ->assertJsonPath('data.dashboard.period.to', '2026-09-30');
    }

    public function test_custom_period_is_echoed_back(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-03-01&to=2026-03-15")
            ->assertOk()
            ->assertJsonPath('data.dashboard.period.from', '2026-03-01')
            ->assertJsonPath('data.dashboard.period.to', '2026-03-15');
    }
}
