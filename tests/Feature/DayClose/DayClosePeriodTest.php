<?php

namespace Tests\Feature\DayClose;

use App\Models\RestaurantDayClose;
use App\Support\DayClose\DayClosePeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — business period, business date, cutoff, DST.
 * Europe/Madrid is UTC+2 (CEST) until 2026-10-25 and UTC+1 (CET) after.
 */
class DayClosePeriodTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_first_close_starts_at_the_cutoff_of_its_business_date(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');

        // Activity before 06:00 Madrid (04:00Z) is outside the closing system.
        $this->at('2026-10-02 03:00:00');
        $this->servedPaidSession($restaurant, $owner, '99.00');
        $this->at('2026-10-02 12:00:00');
        $this->servedPaidSession($restaurant, $owner, '10.00');
        $this->at('2026-10-02 21:00:00');

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertSame([
            'business_date' => '2026-10-02',
            'business_date_from' => '2026-10-02',
            'period_started_at' => '2026-10-02T04:00:00Z',
            'period_ended_at' => '2026-10-02T21:00:00Z',
            'timezone' => 'Europe/Madrid',
            'first_close' => true,
            'covers_multiple_business_days' => false,
        ], $preview['period']);
        $this->assertSame('10.00', $preview['financial']['total_received']);

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.period_started_at', '2026-10-02T04:00:00Z')
            ->assertJsonPath('data.period_ended_at', '2026-10-02T21:00:00Z')
            ->assertJsonPath('data.report.summary.first_close', true);
    }

    public function test_second_close_starts_exactly_at_the_prior_end(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 21:00:00');
        $first = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');

        // A payment one second after the first close belongs to the second.
        $this->at('2026-10-02 21:00:01');
        $this->servedPaidSession($restaurant, $owner, '7.00');
        $this->at('2026-10-03 21:30:00');

        $second = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');
        $this->assertSame($first['period_ended_at'], $second['period_started_at']);
        $this->assertSame('2026-10-03', $second['business_date']);
        $this->assertSame('2026-10-03', $second['business_date_from']);
        $this->assertSame('7.00', $second['total_received']);
        $this->assertFalse($second['report']['summary']['first_close']);
    }

    public function test_cross_midnight_service_belongs_to_the_same_business_date(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 16:00:00'); // 18:00 Madrid
        $this->servedPaidSession($restaurant, $owner, '20.00');
        $this->at('2026-10-02 23:30:00'); // 01:30 Madrid on 10-03
        $this->servedPaidSession($restaurant, $owner, '30.00');
        $this->at('2026-10-02 23:59:00'); // 01:59 Madrid

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.business_date', '2026-10-02')
            ->assertJsonPath('data.total_received', '50.00');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cutoffBoundaries(): array
    {
        return [
            '01:59 Madrid belongs to the previous day' => ['2026-10-02 23:59:00', '2026-10-02'],
            '05:59:59 Madrid still the previous day' => ['2026-10-03 03:59:59', '2026-10-02'],
            '06:00 Madrid is the new day' => ['2026-10-03 04:00:00', '2026-10-03'],
            '23:59 Madrid same day' => ['2026-10-03 21:59:00', '2026-10-03'],
        ];
    }

    #[DataProvider('cutoffBoundaries')]
    public function test_cutoff_boundary(string $utc, string $expected): void
    {
        $this->assertSame($expected, DayClosePeriodResolver::businessDateOf(CarbonImmutable::parse($utc, 'UTC'), 'Europe/Madrid', '06:00'));
    }

    public function test_dst_spring_forward_period_is_23_real_hours(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        // 2026-03-29 02:00 CET -> 03:00 CEST. 03:00Z = 05:00 CEST: still 03-28.
        $this->at('2026-03-29 03:00:00');

        $period = $this->dayClosePreview($restaurant, $owner)['period'];
        $this->assertSame('2026-03-28', $period['business_date']);
        $this->assertSame('2026-03-28T05:00:00Z', $period['period_started_at']); // 06:00 CET
        $this->assertSame(22 * 3600, CarbonImmutable::parse($period['period_ended_at'])->getTimestamp() - CarbonImmutable::parse($period['period_started_at'])->getTimestamp());

        $this->at('2026-03-29 04:00:00'); // 06:00 CEST: new business date, cutoff instant now 04:00Z
        $this->assertSame('2026-03-29', DayClosePeriodResolver::businessDateOf(now()->toImmutable(), 'Europe/Madrid', '06:00'));
        $this->assertSame('2026-03-29T04:00:00+00:00', DayClosePeriodResolver::cutoffInstantUtc('2026-03-29', 'Europe/Madrid', '06:00')->toIso8601String());
    }

    public function test_dst_fall_back_period_and_repeated_local_hour(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        // 2026-10-25 03:00 CEST -> 02:00 CET. Sessions at 00:30Z (02:30 CEST)
        // and 01:30Z (02:30 CET) share the same local wall-clock hour.
        $this->at('2026-10-25 00:30:00');
        $this->servedPaidSession($restaurant, $owner, '10.00');
        $this->at('2026-10-25 01:30:00');
        $this->servedPaidSession($restaurant, $owner, '10.00');
        $this->at('2026-10-25 04:00:00'); // 05:00 CET -> still 10-24

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertSame('2026-10-24', $preview['period']['business_date']);
        $this->assertSame('2026-10-24T04:00:00Z', $preview['period']['period_started_at']); // 06:00 CEST
        $this->assertSame(['local_hour' => '2026-10-25T02:00', 'sessions_started' => 2], $preview['operations']['peak_hour']);

        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.total_received', '20.00');
    }

    public function test_missed_days_are_covered_by_the_next_close(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();

        $this->at('2026-10-03 19:00:00');
        $this->servedPaidSession($restaurant, $owner, '11.00');
        $this->at('2026-10-04 19:00:00');
        $this->servedPaidSession($restaurant, $owner, '12.00');
        $this->at('2026-10-04 21:00:00');

        $response = $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.business_date', '2026-10-04')
            ->assertJsonPath('data.business_date_from', '2026-10-03')
            ->assertJsonPath('data.total_received', '23.00')
            ->assertJsonPath('data.report.summary.covers_multiple_business_days', true);

        $this->assertSame('2026-10-02T21:00:00Z', $response->json('data.period_started_at'));
    }

    public function test_the_same_business_date_cannot_be_closed_twice(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 20:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();

        $this->at('2026-10-02 23:30:00'); // 01:30 Madrid: still business date 10-02
        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertFalse($preview['can_close']);
        $this->assertSame('business_date_already_closed', $preview['blockers'][0]['type']);

        $this->closeDay($restaurant, $owner)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUSINESS_DATE_ALREADY_CLOSED')
            ->assertJsonPath('error.business_date', '2026-10-02');

        $this->assertSame(1, RestaurantDayClose::query()->count());
    }

    public function test_stale_period_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 20:00:00');
        $stale = $this->dayClosePreview($restaurant, $owner);
        $this->closeDay($restaurant, $owner)->assertCreated();

        $this->at('2026-10-03 20:00:00');
        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/day-closes", [
            'period_started_at' => $stale['period']['period_started_at'],
            'expected_cash_seen' => '0.00',
            'counted_cash' => '0.00',
        ], ['Idempotency-Key' => 'stale-1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PERIOD_CHANGED')
            ->assertJsonPath('error.current_period_started_at', '2026-10-02T20:00:00Z');
    }
}
