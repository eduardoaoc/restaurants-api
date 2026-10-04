<?php

namespace Tests\Feature\Security;

use App\Models\PaymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FinancialInputTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public static function amounts(): array
    {
        return array_map(fn ($value) => [$value], ['-1.00', '0.00', '1.001', '999999999999999999999.00', '1e3', 1.0, ['amount' => '1.00'], str_repeat('9', 10000)]);
    }

    #[DataProvider('amounts')]
    public function test_payment_and_cash_reject_invalid_money_without_side_effect(mixed $amount): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);
        $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => $amount])->assertUnprocessable();
        $this->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements", ['type' => 'pay_in', 'amount' => $amount, 'reason' => 'test'], ['Idempotency-Key' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('payment_records', 0);
        $this->assertDatabaseCount('restaurant_cash_movements', 0);
    }

    public function test_payment_currency_ownership_status_and_actor_are_server_controlled(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $otherActor = $this->createStaff($organization, $restaurant, 'cashier', 'cashier');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);
        $url = "/api/v1/table-sessions/{$session->id}/payments";
        $payload = ['method' => 'cash', 'amount' => '5.00', 'currency' => 'USD', 'restaurant_id' => 999999, 'table_session_id' => 999999,
            'status' => 'paid', 'payment_status' => 'paid', 'recorded_by_user_id' => $otherActor->id, 'closed_by' => $otherActor->id];
        $first = $this->as($owner)->postJson($url, $payload, ['Idempotency-Key' => 'same'])->assertCreated();
        $this->as($otherActor)->postJson($url, $payload, ['Idempotency-Key' => 'same'])->assertOk();
        $this->assertDatabaseCount('payment_records', 1);
        $payment = PaymentRecord::query()->sole();
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame($restaurant->id, $payment->restaurant_id);
        $this->assertSame($owner->id, $payment->recorded_by_user_id);
        $this->assertNotSame('paid', $session->fresh()->payment_status);
        $this->postJson($url, [...$payload, 'amount' => '6.00'], ['Idempotency-Key' => 'same'])->assertConflict();
        $this->assertDatabaseCount('payment_records', 1);
    }

    public function test_required_keys_and_oversized_keys_fail_before_cash_write(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $url = "/api/v1/restaurants/{$restaurant->id}/cash-movements";
        $payload = ['type' => 'pay_in', 'amount' => '1.00', 'reason' => 'test'];
        foreach (['', str_repeat('x', 101)] as $key) {
            $this->as($owner)->postJson($url, $payload, ['Idempotency-Key' => $key])->assertUnprocessable();
        }
        $this->assertDatabaseCount('restaurant_cash_movements', 0);
    }

    public static function malformedKeys(): array
    {
        return ['overlong' => [str_repeat('k', 101)], 'whitespace' => ['   ']];
    }

    #[DataProvider('malformedKeys')]
    public function test_key_bounds_and_opaque_whitespace_keys_follow_payment_and_cash_contract(string $key): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);
        $response = $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/payments",
            ['method' => 'cash', 'amount' => '1.00'], ['Idempotency-Key' => $key]);
        if (strlen($key) > 100) {
            $response->assertUnprocessable();
            $this->assertDatabaseCount('payment_records', 0);
        } else {
            $response->assertCreated();
            $this->assertSame($key, PaymentRecord::query()->firstOrFail()->idempotency_key);
        }
        $this->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements",
            ['type' => 'pay_in', 'amount' => '1.00', 'reason' => 'test'], ['Idempotency-Key' => $key])->assertUnprocessable();
        $this->assertDatabaseCount('restaurant_cash_movements', 0);
    }
}
