<?php

namespace Tests\Feature\DayClose;

use App\Actions\Staff\StartStaffShiftAction;
use App\Models\RestaurantDayClose;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — any active session blocks the close (no force close);
 * warnings never block.
 */
class DayCloseBlockersTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->at('2026-10-02 18:00:00');
    }

    public function test_active_session_with_balance_blocks_with_diagnostics(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $table = $this->createTable($restaurant, 'Mesa 7', 7);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner); // 10.00, unpaid
        $this->at('2026-10-02 21:00:00');

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertFalse($preview['can_close']);
        $this->assertSame([[
            'type' => 'active_session',
            'table_session_id' => $session->id,
            'table' => ['id' => $table->id, 'name' => 'Mesa 7', 'number' => 7],
            'opened_at' => '2026-10-02T18:00:00Z',
            'payment_status' => 'unpaid',
            'balance' => '10.00',
            'open_orders_count' => 0,
            'can_be_voided' => false,
        ]], $preview['blockers']);

        $this->closeDay($restaurant, $owner)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CLOSE_BLOCKED')
            ->assertJsonPath('error.blockers.0.table_session_id', $session->id);

        $this->assertSame(0, RestaurantDayClose::query()->count());
        $this->assertTrue($session->refresh()->isActive()); // never auto-closed
    }

    public function test_paid_but_still_active_session_blocks(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $session = $this->servedPaidSession($restaurant, $owner, close: false);
        $this->at('2026-10-02 21:00:00');

        $this->closeDay($restaurant, $owner)->assertUnprocessable()
            ->assertJsonPath('error.blockers.0.payment_status', 'paid')
            ->assertJsonPath('error.blockers.0.balance', '0.00');
        $this->assertSame(TableSession::PAYMENT_STATUS_PAID, $session->refresh()->payment_status);
        $this->assertTrue($session->isActive());
    }

    public function test_open_order_is_reported_as_diagnostic(): void
    {
        [, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1]]);
        $this->at('2026-10-02 21:00:00');

        $blocker = $this->dayClosePreview($restaurant, $owner)['blockers'][0];
        $this->assertSame(1, $blocker['open_orders_count']);
        $this->assertFalse($blocker['can_be_voided']);
    }

    public function test_empty_session_blocks_until_voided_then_the_day_closes(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->servedPaidSession($restaurant, $owner, '15.00');
        $empty = $this->openSession($this->createTable($restaurant), $owner);
        $this->at('2026-10-02 21:00:00');

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertTrue($preview['blockers'][0]['can_be_voided']);
        $this->closeDay($restaurant, $owner)->assertUnprocessable()->assertJsonPath('error.code', 'CLOSE_BLOCKED');

        $this->as($owner)->postJson("/api/v1/table-sessions/{$empty->id}/void")->assertOk();
        $this->at('2026-10-02 21:01:00');

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.sessions_closed', 1)
            ->assertJsonPath('data.report.operations.sessions.voided', 1)
            ->assertJsonPath('data.total_received', '15.00');
    }

    public function test_warnings_do_not_block(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo Ruiz');
        app(StartStaffShiftAction::class)->execute($restaurant, $waiter, $owner);
        $this->at('2026-10-02 21:00:00');

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertTrue($preview['can_close']);
        $this->assertSame('active_staff_shifts', $preview['warnings'][0]['type']);
        $this->assertSame('Mateo Ruiz', $preview['warnings'][0]['staff'][0]['name']);

        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.report.warnings.0.type', 'active_staff_shifts');
    }
}
