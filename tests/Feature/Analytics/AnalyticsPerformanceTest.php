<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\BuildRestaurantAnalyticsAction;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — every Analytics section must run a FIXED number of queries
 * regardless of how many tables/sessions/staff/days are involved (see
 * each Support class's own docblock). Coarse regression guard against an
 * accidental N+1, not a frozen exact count.
 */
class AnalyticsPerformanceTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_query_count_does_not_scale_with_table_and_session_count(): void
    {
        [$organizationSmall, $ownerSmall, $restaurantSmall] = $this->createTenant();
        $this->seedRestaurant($organizationSmall, $ownerSmall, $restaurantSmall, 5);

        [$organizationLarge, $ownerLarge, $restaurantLarge] = $this->createTenant();
        $this->seedRestaurant($organizationLarge, $ownerLarge, $restaurantLarge, 40);

        $action = app(BuildRestaurantAnalyticsAction::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $action->execute($restaurantSmall->fresh(), '2026-01-01', '2026-01-31', 'day');
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $action->execute($restaurantLarge->fresh(), '2026-01-01', '2026-01-31', 'day');
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $smallQueryCount + 10,
            $largeQueryCount,
            "Query count scaled with table/session count: small={$smallQueryCount} (5 tables), large={$largeQueryCount} (40 tables) — looks like an N+1.",
        );
    }

    public function test_query_count_does_not_scale_with_staff_count(): void
    {
        [$organizationSmall, $ownerSmall, $restaurantSmall] = $this->createTenant();
        $this->seedStaff($organizationSmall, $restaurantSmall, 5);

        [$organizationLarge, $ownerLarge, $restaurantLarge] = $this->createTenant();
        $this->seedStaff($organizationLarge, $restaurantLarge, 40);

        $action = app(BuildRestaurantAnalyticsAction::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $action->execute($restaurantSmall->fresh(), '2026-01-01', '2026-01-31', 'day');
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $action->execute($restaurantLarge->fresh(), '2026-01-01', '2026-01-31', 'day');
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $smallQueryCount + 10,
            $largeQueryCount,
            "Query count scaled with staff count: small={$smallQueryCount} (5 staff), large={$largeQueryCount} (40 staff) — looks like an N+1.",
        );
    }

    public function test_query_count_does_not_scale_with_period_length(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->seedRestaurant($organization, $owner, $restaurant, 5);

        $action = app(BuildRestaurantAnalyticsAction::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $action->execute($restaurant->fresh(), '2026-01-01', '2026-01-10', 'day');
        $shortQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $action->execute($restaurant->fresh(), '2025-01-01', '2025-12-31', 'day');
        $longQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $shortQueryCount + 10,
            $longQueryCount,
            "Query count scaled with period length: short={$shortQueryCount} (10 days), long={$longQueryCount} (365 days) — the daily bucket zero-fill should happen in PHP, not per-day queries.",
        );
    }

    private function seedRestaurant(mixed $organization, User $owner, Restaurant $restaurant, int $tableCount): void
    {
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->startShift($restaurant, $waiter, $waiter);

        for ($i = 0; $i < $tableCount; $i++) {
            $table = $this->createTable($restaurant);

            if ($i % 3 === 0) {
                $session = $this->openSession($table, $owner, 2);
                $this->closeSessionWithFullPayment($session, $owner);
            }
        }
    }

    private function seedStaff(mixed $organization, Restaurant $restaurant, int $staffCount): void
    {
        for ($i = 0; $i < $staffCount; $i++) {
            $waiter = $this->createStaff($organization, $restaurant, 'waiter', "W-{$i}");
            $this->startShift($restaurant, $waiter, $waiter);
        }
    }
}
