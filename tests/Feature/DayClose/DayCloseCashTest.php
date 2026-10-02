<?php

namespace Tests\Feature\DayClose;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantCashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — payments by method, cash movements, opening float,
 * expected/counted cash and the difference rule.
 */
class DayCloseCashTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function movement(Restaurant $restaurant, User $user, string $type, string $amount, string $key, string $reason = 'Test'): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements", ['type' => $type, 'amount' => $amount, 'reason' => $reason], ['Idempotency-Key' => $key]);
    }

    public function test_payment_methods_and_cent_precision(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, '0.10', 'cash');
        $this->servedPaidSession($restaurant, $owner, '0.20', 'cash');
        $this->servedPaidSession($restaurant, $owner, '12.35', 'card');
        $this->servedPaidSession($restaurant, $owner, '1.01', 'other');
        $this->at('2026-10-02 21:00:00');

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.total_received', '13.66')
            ->assertJsonPath('data.cash_received', '0.30')
            ->assertJsonPath('data.card_received', '12.35')
            ->assertJsonPath('data.other_received', '1.01')
            ->assertJsonPath('data.payments_count', 4)
            ->assertJsonPath('data.sessions_with_payments', 4)
            ->assertJsonPath('data.average_ticket', '3.42') // 13.66 / 4 = 3.415 -> 3.42
            ->assertJsonPath('data.expected_cash', '0.30')
            ->assertJsonPath('data.cash_difference', '0.00')
            ->assertJsonPath('data.has_incidents', false);
    }

    public function test_cash_movements_enter_expected_cash_and_are_idempotent(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, '40.00', 'cash');
        $this->servedPaidSession($restaurant, $owner, '60.00', 'card');

        $this->movement($restaurant, $waiter, 'pay_in', '20.00', 'in-1', 'Cambio del banco')->assertCreated()
            ->assertJsonPath('data.type', 'pay_in')
            ->assertJsonPath('data.amount', '20.00')
            ->assertJsonPath('data.recorded_by.id', $waiter->id);
        $this->movement($restaurant, $waiter, 'pay_in', '20.00', 'in-1', 'Cambio del banco')->assertOk(); // replay
        $this->movement($restaurant, $waiter, 'pay_in', '21.00', 'in-1')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->movement($restaurant, $waiter, 'pay_out', '35.50', 'out-1', 'Compra de hielo')->assertCreated();

        $this->assertSame(2, RestaurantCashMovement::query()->count());
        $this->assertSame(2, AuditLog::query()->where('event', AuditLog::EVENT_CASH_MOVEMENT_RECORDED)->count());

        $this->as($waiter)->getJson("/api/v1/restaurants/{$restaurant->id}/cash-movements")->assertOk()
            ->assertJsonPath('data.pay_ins_total', '20.00')
            ->assertJsonPath('data.pay_outs_total', '35.50')
            ->assertJsonCount(2, 'data.movements');

        $this->at('2026-10-02 21:00:00');
        $preview = $this->dayClosePreview($restaurant, $waiter);
        // 100 + 40 + 20 - 35.50
        $this->assertSame('124.50', $preview['cash']['expected_cash']);

        $this->closeDay($restaurant, $waiter)->assertCreated()
            ->assertJsonPath('data.cash_pay_ins', '20.00')
            ->assertJsonPath('data.cash_pay_outs', '35.50')
            ->assertJsonPath('data.expected_cash', '124.50')
            ->assertJsonCount(2, 'data.report.cash.movements');

        // After the close the current period has no movements.
        $this->as($waiter)->getJson("/api/v1/restaurants/{$restaurant->id}/cash-movements")->assertOk()->assertJsonCount(0, 'data.movements');
    }

    public function test_cash_movement_validation(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements", ['type' => 'pay_out', 'amount' => '5.00', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->movement($restaurant, $owner, 'refund', '5.00', 'k1')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->movement($restaurant, $owner, 'pay_out', '0.00', 'k2')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->movement($restaurant, $owner, 'pay_out', '1.234', 'k3')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements", ['type' => 'pay_out', 'amount' => 5, 'reason' => 'x'], ['Idempotency-Key' => 'k4'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_opening_float_is_inherited_from_the_previous_close(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner, ['cash_left_for_next_day' => '80.00'])->assertCreated()
            ->assertJsonPath('data.opening_float', '100.00')
            ->assertJsonPath('data.report.cash.opening_float_source', 'restaurant_default');

        $this->at('2026-10-03 21:00:00');
        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertSame('80.00', $preview['cash']['suggested_opening_float']);
        $this->assertSame('previous_close', $preview['cash']['opening_float_source']);

        // A different explicit opening float is refused: the drawer
        // discrepancy must show up as the cash difference instead.
        $this->closeDay($restaurant, $owner, ['opening_float' => '90.00', 'expected_cash_seen' => '80.00', 'counted_cash' => '80.00'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'OPENING_FLOAT_MISMATCH')
            ->assertJsonPath('error.suggested_opening_float', '80.00');

        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.opening_float', '80.00');
    }

    public function test_previous_close_without_left_cash_falls_back_to_the_default(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '50.00');
        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();

        $this->at('2026-10-03 21:00:00');
        $this->assertSame('restaurant_default', $this->dayClosePreview($restaurant, $owner)['cash']['opening_float_source']);
    }

    public function test_opening_float_required_without_previous_close_nor_default(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, '30.00', 'cash');
        $this->at('2026-10-02 21:00:00');

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertSame('required', $preview['cash']['opening_float_source']);
        $this->assertNull($preview['cash']['suggested_opening_float']);
        $this->assertNull($preview['cash']['expected_cash']);
        $this->assertSame('30.00', $preview['cash']['expected_cash_excluding_opening_float']);
        $this->assertSame('130.00', $this->dayClosePreview($restaurant, $owner, ['opening_float' => '100.00'])['cash']['expected_cash']);

        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/day-closes", [
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => '30.00',
            'counted_cash' => '30.00',
        ], ['Idempotency-Key' => 'no-float'])->assertUnprocessable()->assertJsonValidationErrors('opening_float');

        $this->closeDay($restaurant, $owner, ['opening_float' => '100.00'])->assertCreated()
            ->assertJsonPath('data.opening_float', '100.00')
            ->assertJsonPath('data.expected_cash', '130.00')
            ->assertJsonPath('data.report.cash.opening_float_source', 'required');
    }

    public function test_difference_positive_negative_zero(): void
    {
        foreach ([['103.00', '3.00', true], ['98.50', '-1.50', true], ['100.00', '0.00', false]] as $i => [$counted, $difference, $incident]) {
            [, $owner, $restaurant] = $this->createTenant();
            $this->setDefaultOpeningFloat($restaurant, '100.00');
            $this->at('2026-10-0'.($i + 2).' 21:00:00');

            $this->closeDay($restaurant, $owner, ['counted_cash' => $counted])->assertCreated()
                ->assertJsonPath('data.cash_difference', $difference)
                ->assertJsonPath('data.has_incidents', $incident);
        }
    }

    public function test_note_required_strictly_above_the_threshold(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $this->at('2026-10-02 21:00:00');

        // |diff| = 5.01 > 5.00: note required.
        $this->closeDay($restaurant, $owner, ['counted_cash' => '94.99'])
            ->assertUnprocessable()->assertJsonValidationErrors('cash_difference_note');
        $this->closeDay($restaurant, $owner, ['counted_cash' => '94.99', 'cash_difference_note' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('cash_difference_note');

        // |diff| = 5.00 exactly: no note needed (strictly greater rule).
        [, $owner2, $restaurant2] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant2, '100.00');
        $this->closeDay($restaurant2, $owner2, ['counted_cash' => '105.00'])->assertCreated()
            ->assertJsonPath('data.cash_difference', '5.00')
            ->assertJsonPath('data.has_incidents', true);

        $this->closeDay($restaurant, $owner, ['counted_cash' => '94.99', 'cash_difference_note' => 'Falta cambio'])->assertCreated()
            ->assertJsonPath('data.cash_difference', '-5.01')
            ->assertJsonPath('data.cash_difference_note', 'Falta cambio');
    }

    public function test_threshold_comes_from_settings(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $restaurant->settings()->update(['cash_difference_note_threshold' => '0.50']);
        $this->at('2026-10-02 21:00:00');

        $this->closeDay($restaurant, $owner, ['counted_cash' => '100.51'])->assertUnprocessable()->assertJsonValidationErrors('cash_difference_note');
    }

    public function test_stale_expected_cash_is_rejected_with_the_current_value(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $this->at('2026-10-02 20:00:00');
        $preview = $this->dayClosePreview($restaurant, $owner);

        // A cash payment arrives after the user counted.
        $this->servedPaidSession($restaurant, $owner, '12.00', 'cash');
        $this->at('2026-10-02 20:05:00');

        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/day-closes", [
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => $preview['cash']['expected_cash'],
            'counted_cash' => '100.00',
        ], ['Idempotency-Key' => 'stale-cash'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CASH_EXPECTATION_CHANGED')
            ->assertJsonPath('error.current_expected_cash', '112.00');
    }

    public function test_negative_expected_cash_is_refused(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '10.00');
        $this->at('2026-10-02 20:00:00');
        $this->movement($restaurant, $owner, 'pay_out', '25.00', 'big-out')->assertCreated();
        $this->at('2026-10-02 20:01:00'); // [start, T) is half-open: T must be after the movement

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertFalse($preview['can_close']);
        $this->assertSame(['type' => 'expected_cash_negative', 'expected_cash' => '-15.00'], $preview['blockers'][0]);

        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/day-closes", [
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => '0.00',
            'counted_cash' => '0.00',
        ], ['Idempotency-Key' => 'neg'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'EXPECTED_CASH_NEGATIVE')
            ->assertJsonPath('error.expected_cash', '-15.00');

        // Corrected with an opposite movement, never an edit.
        $this->movement($restaurant, $owner, 'pay_in', '15.00', 'fix')->assertCreated();
        $this->at('2026-10-02 20:02:00');
        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.expected_cash', '0.00');
    }

    public function test_counted_cash_must_be_non_negative_money(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 20:00:00');

        $this->closeDay($restaurant, $owner, ['counted_cash' => '-1.00'])->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->closeDay($restaurant, $owner, ['counted_cash' => 10])->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
    }
}
