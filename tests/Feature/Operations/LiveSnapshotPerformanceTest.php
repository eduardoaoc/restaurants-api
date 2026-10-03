<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\BuildRestaurantOperationsSnapshotAction;
use App\Actions\Tables\AssignWaiterAction;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — the snapshot must run a FIXED number of queries regardless of
 * how many tables/sessions/staff a restaurant has (see
 * BuildRestaurantOperationsSnapshotAction's docblock for the exact
 * loading strategy). This is a coarse regression guard against an
 * accidental N+1 (one query per table/session/staff), not a frozen exact
 * count — the framework/driver can shift the absolute number slightly.
 */
class LiveSnapshotPerformanceTest extends TestCase
{
    use InteractsWithOrders, InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_query_count_does_not_scale_with_table_count(): void
    {
        [$organizationSmall, $ownerSmall, $restaurantSmall] = $this->createTenant();
        $this->seedRestaurant($organizationSmall, $ownerSmall, $restaurantSmall, 5);

        [$organizationLarge, $ownerLarge, $restaurantLarge] = $this->createTenant();
        $this->seedRestaurant($organizationLarge, $ownerLarge, $restaurantLarge, 40);

        $action = app(BuildRestaurantOperationsSnapshotAction::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $action->execute($restaurantSmall->fresh());
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $action->execute($restaurantLarge->fresh());
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 5 -> 40 tables is an 8x increase. An N+1 (one query per table)
        // would blow this up by roughly 35 queries; a fixed-query-count
        // implementation should differ only by noise (a handful of extra
        // queries at most from the larger IN(...) result sets, never one
        // per row).
        $this->assertLessThan(
            $smallQueryCount + 10,
            $largeQueryCount,
            "Query count scaled with table count: small={$smallQueryCount} (5 tables), large={$largeQueryCount} (40 tables) — looks like an N+1.",
        );
    }

    private function seedRestaurant(mixed $organization, User $owner, Restaurant $restaurant, int $tableCount): void
    {
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->startShift($restaurant, $waiter, $waiter);

        for ($i = 0; $i < $tableCount; $i++) {
            $table = $this->createTable($restaurant);

            // Roughly a third of tables get an active, assigned session —
            // enough to exercise every branch (billing/orders/alerts)
            // without every table being identical.
            if ($i % 3 === 0) {
                $session = $this->openSession($table, $owner, 2);
                app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
            }
        }
    }
}
