<?php

namespace Tests\Feature\DayClose;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A final contract: there is NO forced close — any active table
 * session blocks the close for every user — plus the zero-activity close
 * and the exact meaning of PERIOD_EMPTY.
 */
class DayCloseNoForceTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->at('2026-10-02 18:00:00');
    }

    /**
     * @return array{0: Restaurant, 1: User, 2: TableSession}
     */
    private function restaurantWithActiveSession(): array
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->servedPaidSession($restaurant, $owner, '12.00', 'cash');
        $active = $this->openSession($this->createTable($restaurant), $owner);
        $this->at('2026-10-02 21:00:00');

        return [$restaurant, $owner, $active];
    }

    public function test_an_active_session_blocks_every_role_with_close_daily_operation(): void
    {
        [$restaurant, $owner, $active] = $this->restaurantWithActiveSession();
        $users = ['owner' => $owner];
        foreach (['manager', 'cashier', 'waiter'] as $i => $role) {
            $users[$role] = $this->createStaff($restaurant->organization, $restaurant, $role, 'S-'.$i);
        }

        foreach ($users as $role => $user) {
            $this->closeDay($restaurant, $user)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'CLOSE_BLOCKED')
                ->assertJsonPath('error.blockers.0.table_session_id', $active->id);
        }

        $this->assertSame(0, RestaurantDayClose::query()->count());
        $this->assertTrue($active->refresh()->isActive());
    }

    public function test_force_fields_are_not_part_of_the_contract_and_change_nothing(): void
    {
        [$restaurant, $owner] = $this->restaurantWithActiveSession();
        $preview = $this->dayClosePreview($restaurant, $owner);

        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/day-closes", [
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => $preview['cash']['expected_cash'],
            'counted_cash' => $preview['cash']['expected_cash'],
            'force' => true,
            'force_reason' => 'please',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CLOSE_BLOCKED');

        $this->assertFalse(Permission::query()->where('slug', 'force_daily_close')->exists());
        $this->assertNotContains('force_daily_close', $this->as($owner)->getJson('/api/v1/auth/context')->json('data.organizations.0.permissions'));
        $this->assertFalse(Schema::hasColumn('restaurant_day_closes', 'forced'));
        $this->assertFalse(Schema::hasColumn('restaurant_day_closes', 'force_reason'));
        $this->assertFalse(defined(AuditLog::class.'::EVENT_DAY_CLOSE_FORCED'));

        $docs = (string) file_get_contents(storage_path('api-docs/api-docs.json'));
        foreach (['force_daily_close', 'force_reason', 'ignored_blockers', 'day_close.forced'] as $term) {
            $this->assertStringNotContainsString($term, $docs, $term);
        }
    }

    public function test_after_voiding_the_session_a_waiter_closes_normally(): void
    {
        [$restaurant, , $active] = $this->restaurantWithActiveSession();
        $waiter = $this->createStaff($restaurant->organization, $restaurant, 'waiter', 'W-1');

        $this->closeDay($restaurant, $waiter)->assertUnprocessable();
        $this->as($waiter)->postJson("/api/v1/table-sessions/{$active->id}/void")->assertOk();
        $this->at('2026-10-02 21:01:00');

        $data = $this->closeDay($restaurant, $waiter)->assertCreated()->json('data');
        $this->assertSame('12.00', $data['total_received']);
        $this->assertArrayNotHasKey('forced', $data);
        $this->assertArrayNotHasKey('force_reason', $data);
        $this->assertArrayNotHasKey('ignored_blockers', $data['report']['closing']);
    }

    public function test_after_settling_the_session_the_day_closes_normally(): void
    {
        [$restaurant, $owner, $active] = $this->restaurantWithActiveSession();
        $cashier = $this->createStaff($restaurant->organization, $restaurant, 'cashier', 'C-1');
        $this->closeSessionWithFullPayment($active, $owner);
        $this->at('2026-10-02 21:01:00');

        $this->closeDay($restaurant, $cashier)->assertCreated()->assertJsonPath('data.total_received', '22.00');
    }

    public function test_a_day_without_any_activity_closes_with_zeros(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 21:00:00');

        $this->assertTrue($this->dayClosePreview($restaurant, $owner)['can_close']);
        $data = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');

        foreach (['total_received', 'cash_received', 'card_received', 'other_received', 'average_ticket', 'expected_cash', 'counted_cash', 'cash_difference'] as $money) {
            $this->assertSame('0.00', $data[$money], $money);
        }
        foreach (['payments_count', 'sessions_with_payments', 'orders_registered', 'orders_valid', 'orders_served', 'orders_rejected', 'sessions_opened', 'sessions_closed', 'guests', 'feedback_count', 'critical_feedback_count', 'low_dimension_feedback_count', 'delays_count', 'unavailable_products_count'] as $count) {
            $this->assertSame(0, $data[$count], $count);
        }
        $this->assertNull($data['feedback_avg_overall']);
        $this->assertFalse($data['has_incidents']);
        $this->assertSame([], $data['report']['products']['top']);
        $this->assertNull($data['report']['operations']['peak_hour']);
        $this->assertNull($data['report']['summary']['top_product']);
        $this->assertSame([], $data['report']['warnings']);
    }

    public function test_period_empty_only_for_a_zero_length_period(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 04:00:00'); // exactly the 06:00 Madrid cutoff: T == period start

        $this->assertFalse($this->dayClosePreview($restaurant, $owner)['can_close']);
        $this->closeDay($restaurant, $owner)->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_EMPTY');

        $this->at('2026-10-02 04:00:01');
        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.total_received', '0.00');
    }
}
