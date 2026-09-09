<?php

namespace Tests\Unit\Support\Analytics;

use App\Support\Analytics\AnalyticsBucketBuilder;
use Tests\TestCase;

/**
 * Bloco 6 — bucket key generation for day/week/month series. Week starts
 * Monday (explicit, matching Postgres's own date_trunc('week', ...)).
 */
class AnalyticsBucketBuilderTest extends TestCase
{
    public function test_day_buckets_one_per_calendar_day_inclusive(): void
    {
        $keys = AnalyticsBucketBuilder::buildKeys('2026-09-01', '2026-09-03', 'day');

        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03'], $keys);
    }

    public function test_single_day_range_produces_one_bucket(): void
    {
        $keys = AnalyticsBucketBuilder::buildKeys('2026-09-01', '2026-09-01', 'day');

        $this->assertSame(['2026-09-01'], $keys);
    }

    public function test_week_buckets_start_on_monday(): void
    {
        // 2026-09-01 is a Tuesday; 2026-09-14 is a Monday.
        $keys = AnalyticsBucketBuilder::buildKeys('2026-09-01', '2026-09-14', 'week');

        // Weeks containing 09-01: Monday 08-31. Then 09-07, 09-14.
        $this->assertSame(['2026-08-31', '2026-09-07', '2026-09-14'], $keys);
    }

    public function test_month_buckets_start_on_the_first(): void
    {
        // 2026-09-15 to 2026-11-01 spans Sep, Oct, Nov.
        $keys = AnalyticsBucketBuilder::buildKeys('2026-09-15', '2026-11-01', 'month');

        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $keys);
    }
}
