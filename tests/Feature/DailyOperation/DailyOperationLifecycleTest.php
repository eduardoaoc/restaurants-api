<?php

namespace Tests\Feature\DailyOperation;

use App\Actions\Tables\CloseTableAction;
use App\Events\Realtime\DailyOperationChanged;
use App\Models\AuditLog;
use App\Models\CategoryProduct;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantDailyOperation;
use App\Models\RestaurantDayClose;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithPublicVisits;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Operational day: opening (permission, float, idempotency), the closed-day
 * gate on new sessions/orders/requests, the link with the existing Cierre
 * Diario, midnight/DST and per-operation metrics.
 * Europe/Madrid is UTC+2 until 2026-10-25 01:00Z and UTC+1 after; cutoff 06:00.
 */
class DailyOperationLifecycleTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithPublicVisits, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->withoutDailyOperationFixture();
    }

    private function open(Restaurant $restaurant, $user, array $body = ['opening_float' => '150.00'], ?string $key = 'open-1'): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/restaurants/{$restaurant->id}/daily-operations", $body, $key !== null ? ['Idempotency-Key' => $key] : []);
    }

    private function state(Restaurant $restaurant, $user): TestResponse
    {
        return $this->as($user)->getJson("/api/v1/restaurants/{$restaurant->id}/daily-operation")->assertOk();
    }

    public function test_owner_and_manager_open_with_audit_and_after_commit_event(): void
    {
        Event::fake([DailyOperationChanged::class]);
        [$org, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($org, $restaurant, 'manager', 'M1');
        $this->at('2026-10-02 08:00:00');

        $this->state($restaurant, $manager)->assertJsonPath('data.status', 'not_open')
            ->assertJsonPath('data.next_business_date', '2026-10-02')->assertJsonPath('data.can.open', true);

        $this->open($restaurant, $manager)->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.current.business_date', '2026-10-02')
            ->assertJsonPath('data.current.opened_by.name', $manager->name)
            ->assertJsonPath('data.current.opened_at', '2026-10-02T08:00:00Z')
            ->assertJsonPath('data.cash.opening_float', '150.00')
            ->assertJsonPath('data.cash.received', '0.00');

        $operation = RestaurantDailyOperation::sole();
        $this->assertSame($manager->id, $operation->opened_by_user_id);
        $this->assertTrue(AuditLog::where('event', AuditLog::EVENT_DAILY_OPERATION_OPENED)->where('resource_id', $operation->id)->exists());
        Event::assertDispatched(DailyOperationChanged::class, fn ($e) => $e->status === 'open' && ! array_key_exists('opening_float', $e->broadcastWith()));

        [, $owner2, $restaurant2] = $this->createTenant();
        $this->open($restaurant2, $owner2, ['opening_float' => '0'], 'zero')->assertCreated()->assertJsonPath('data.cash.opening_float', '0.00');
    }

    #[DataProvider('rolesWithoutOpen')]
    public function test_roles_without_permission_cannot_open_but_see_state_without_cash(string $role): void
    {
        [$org, , $restaurant] = $this->createTenant();
        $staff = $this->createStaff($org, $restaurant, $role, 'S1');

        $this->open($restaurant, $staff)->assertForbidden();
        $this->assertSame(0, RestaurantDailyOperation::count());
        $response = $this->state($restaurant, $staff)->assertJsonPath('data.status', 'not_open')->assertJsonPath('data.can.open', false);
        if ($role === 'kitchen') {
            $this->assertArrayNotHasKey('cash', $response->json('data'));
        }
    }

    public static function rolesWithoutOpen(): array
    {
        return [['waiter'], ['kitchen'], ['cashier']];
    }

    public function test_other_tenant_and_unassigned_restaurant_are_not_reachable(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        [, $foreignOwner] = $this->createTenant();
        $sibling = Restaurant::factory()->create(['organization_id' => $org->id]);
        $managerOfSibling = $this->createStaff($org, $sibling, 'manager', 'M2');

        $this->open($restaurant, $foreignOwner)->assertNotFound();
        $this->as($foreignOwner)->getJson("/api/v1/restaurants/{$restaurant->id}/daily-operation")->assertNotFound();
        $this->open($restaurant, $managerOfSibling)->assertNotFound();
        $this->as($managerOfSibling)->getJson("/api/v1/restaurants/{$restaurant->id}/daily-operation")->assertNotFound();
        $this->assertSame(0, RestaurantDailyOperation::count());
        $this->open($restaurant, $owner)->assertCreated();
    }

    public function test_opening_float_validation(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        foreach (['-1.00', '10.555', '1,50', 'abc', '50000.01', ''] as $i => $bad) {
            $this->open($restaurant, $owner, ['opening_float' => $bad], "bad-{$i}")->assertUnprocessable()->assertJsonValidationErrors('opening_float');
        }
        $this->open($restaurant, $owner, ['opening_float' => 12.5], 'number')->assertUnprocessable();
        $this->open($restaurant, $owner, ['opening_float' => '1.00'], null)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, RestaurantDailyOperation::count());
        $this->open($restaurant, $owner, ['opening_float' => '50000.00'], 'max')->assertCreated();
    }

    public function test_retries_and_second_opener_never_duplicate_the_day_or_its_float(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($org, $restaurant, 'manager', 'M1');

        $first = $this->open($restaurant, $owner, ['opening_float' => '100.00'], 'k1')->assertCreated()->json('data.current.id');
        $this->open($restaurant, $owner, ['opening_float' => '100.0'], 'k1')->assertOk()->assertJsonPath('data.current.id', $first);
        $this->open($restaurant, $owner, ['opening_float' => '90.00'], 'k1')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->open($restaurant, $manager, ['opening_float' => '100.00'], 'k2')->assertStatus(409)
            ->assertJsonPath('error.code', 'DAILY_OPERATION_ALREADY_OPEN')
            ->assertJsonPath('error.daily_operation.id', $first)
            ->assertJsonMissingPath('error.daily_operation.opening_float');

        $this->assertSame(1, RestaurantDailyOperation::count());
        $this->assertSame('100.00', (string) RestaurantDailyOperation::sole()->opening_float);
    }

    public function test_closed_day_keeps_the_carta_readable_but_blocks_every_new_service_write(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($org));
        $category = $this->createCategory($this->createMenu($restaurant));
        CategoryProduct::create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);
        $table = $this->createTable($restaurant);
        $qr = $table->public_token;

        $this->getJson("/api/v1/public/tables/{$qr}")->assertOk()->assertJsonPath('data.restaurant.service_open', false);
        $this->getJson("/api/v1/public/tables/{$qr}/menu")->assertOk()->assertJsonPath('data.menu.categories.0.products.0.restaurant_product_id', $rp->id);
        $this->postJson("/api/v1/public/tables/{$qr}/enter")->assertStatus(409)->assertJsonPath('error.code', 'RESTAURANT_DAY_NOT_OPEN');
        $this->as($owner)->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])->assertStatus(409)->assertJsonPath('error.code', 'RESTAURANT_DAY_NOT_OPEN');
        $this->assertSame(0, TableSession::count());

        // A legacy session (opened before the gate existed, no operation).
        $legacy = TableSession::query()->create(['restaurant_id' => $restaurant->id, 'table_id' => $table->id, 'opened_by_user_id' => $owner->id,
            'guest_count' => 2, 'status' => 'occupied', 'opened_at' => now(), 'feedback_token' => TableSession::generateUniqueFeedbackToken()]);
        $items = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $this->as($owner)->postJson("/api/v1/tables/{$table->id}/orders", $items)->assertStatus(409)->assertJsonPath('error.code', 'RESTAURANT_DAY_NOT_OPEN');
        $this->postPublicJson("/api/v1/public/tables/{$qr}/orders", $items, ['Idempotency-Key' => 'closed'])->assertStatus(409)->assertJsonPath('error.code', 'RESTAURANT_DAY_NOT_OPEN');
        $this->postPublicJson("/api/v1/public/tables/{$qr}/requests/call-waiter")->assertStatus(409)->assertJsonPath('error.code', 'RESTAURANT_DAY_NOT_OPEN');
        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('table_requests', 0);
        $this->assertNull($legacy->fresh()->daily_operation_id);

        // Catalog/availability preparation stays possible before opening.
        $this->as($owner)->patchJson("/api/v1/restaurant-products/{$rp->id}/availability", ['available' => false])->assertOk();
        $this->open($restaurant, $owner)->assertCreated();
        $this->assertFalse($rp->fresh()->available, 'opening never resets availability');
        $this->getJson("/api/v1/public/tables/{$qr}")->assertJsonPath('data.restaurant.service_open', true);
    }

    public function test_sessions_link_to_the_operation_which_crosses_midnight_until_the_close_finishes_it(): void
    {
        Event::fake([DailyOperationChanged::class]);
        [$org, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-02 18:00:00'); // 20:00 Madrid
        $operation = $this->openDay($restaurant, $owner, '100.00');
        $this->assertSame('2026-10-02', $operation->business_date->format('Y-m-d'));

        $this->at('2026-10-02 22:30:00'); // 00:30 Madrid, next calendar day
        $session = $this->servedPaidSession($restaurant, $owner, '30.00', 'cash');
        $this->assertSame($operation->id, $session->daily_operation_id);
        $this->state($restaurant, $owner)->assertJsonPath('data.current.business_date', '2026-10-02')
            ->assertJsonPath('data.cash.received', '30.00')->assertJsonPath('data.cash.opening_float', '100.00');

        $this->at('2026-10-02 23:30:00'); // 01:30 Madrid, before the cutoff
        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertSame($operation->public_id, $preview['daily_operation']['id']);
        $this->assertSame('daily_operation', $preview['cash']['opening_float_source']);
        $this->assertSame('130.00', $preview['cash']['expected_cash'], 'float + cash sales; the float is not a sale');
        $this->assertSame('30.00', $preview['financial']['total_received']);

        $close = $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.business_date', '2026-10-02')
            ->assertJsonPath('data.opening_float', '100.00')
            ->assertJsonPath('data.report.cash.opening_float_source', 'daily_operation')->json('data');

        $operation->refresh();
        $this->assertSame('closed', $operation->status);
        $this->assertSame(RestaurantDayClose::where('public_id', $close['public_id'])->value('id'), $operation->day_close_id);
        $this->assertSame($owner->id, $operation->closed_by_user_id);
        Event::assertDispatched(DailyOperationChanged::class, fn ($e) => $e->status === 'closed');
        $this->state($restaurant, $owner)->assertJsonPath('data.status', 'not_open')->assertJsonPath('data.last_close.business_date', '2026-10-02');
        $this->postJson("/api/v1/public/tables/{$session->table->public_token}/enter")->assertStatus(409)->assertJsonPath('error.code', 'RESTAURANT_DAY_NOT_OPEN');
    }

    public function test_business_date_is_closed_until_the_cutoff_and_dst_change_is_respected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-24 18:00:00'); // 20:00 CEST
        $this->openDay($restaurant, $owner);
        $this->at('2026-10-25 01:30:00'); // 02:30 CET — after the DST fall-back, before the cutoff
        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.business_date', '2026-10-24');

        $this->at('2026-10-25 04:59:00'); // 05:59 CET — still business date 10-24
        $this->open($restaurant, $owner, key: 'early')->assertStatus(409)->assertJsonPath('error.code', 'BUSINESS_DATE_ALREADY_CLOSED')
            ->assertJsonPath('error.business_date', '2026-10-24');
        $this->at('2026-10-25 05:00:00'); // 06:00 CET (would be 04:00Z in summer)
        $this->open($restaurant, $owner, key: 'cutoff')->assertCreated()->assertJsonPath('data.current.business_date', '2026-10-25');
    }

    /**
     * Correction A: a day opened on the 21st and closed on the 22nd after the
     * 06:00 cutoff stays the 21st (operation and close); closed_at is the real
     * instant; the 22nd can then be opened; nothing is counted twice and the
     * first close stays immutable.
     */
    public function test_late_close_after_the_next_cutoff_keeps_the_operation_business_date(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-21 08:00:00'); // 10:00 CEST
        $first = $this->openDay($restaurant, $owner, '50.00');
        $this->servedPaidSession($restaurant, $owner, '20.00', 'cash');

        $this->at('2026-10-22 05:00:00'); // 07:00 CEST, after the 06:00 cutoff of the 22nd
        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertSame('2026-10-21', $preview['period']['business_date']);
        $this->assertFalse($preview['period']['covers_multiple_business_days']);
        $close = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');
        $this->assertSame('2026-10-21', $close['business_date']);
        $this->assertSame($preview['period']['period_started_at'], $close['period_started_at']);
        $this->assertSame('2026-10-22T05:00:00Z', $close['period_ended_at']);
        $this->assertSame('20.00', $close['total_received']);
        $first->refresh();
        $this->assertSame('2026-10-21', $first->business_date->format('Y-m-d'));
        $this->assertSame('2026-10-22 05:00:00', $first->closed_at->utc()->format('Y-m-d H:i:s'));
        $hash = RestaurantDayClose::where('public_id', $close['public_id'])->value('report_sha256');

        $this->at('2026-10-22 05:00:30');
        $second = $this->openDay($restaurant, $owner, '30.00');
        $this->assertSame('2026-10-22', $second->business_date->format('Y-m-d'));
        $this->servedPaidSession($restaurant, $owner, '12.00', 'card');
        $this->at('2026-10-22 20:00:00');
        $next = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');
        $this->assertSame('2026-10-22', $next['business_date']);
        $this->assertSame($close['period_ended_at'], $next['period_started_at']);
        $this->assertSame('12.00', $next['total_received'], 'the first day payments are never counted again');
        $this->assertSame($hash, RestaurantDayClose::where('public_id', $close['public_id'])->value('report_sha256'));
    }

    public function test_late_close_across_the_dst_change_keeps_the_operation_business_date(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-24 18:00:00'); // 20:00 CEST
        $this->openDay($restaurant, $owner);
        $this->at('2026-10-25 06:30:00'); // 07:30 CET — clocks went back, cutoff (06:00 CET = 05:00Z) passed
        $this->assertSame('2026-10-24', $this->dayClosePreview($restaurant, $owner)['period']['business_date']);
        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.business_date', '2026-10-24');
        $this->at('2026-10-25 06:31:00');
        $this->assertSame('2026-10-25', $this->openDay($restaurant, $owner)->business_date->format('Y-m-d'));
    }

    public function test_close_requires_free_tables_and_never_resolves_them(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-02 10:00:00');
        $this->openDay($restaurant, $owner);
        $session = $this->servedPaidSession($restaurant, $owner, close: false);

        $preview = $this->dayClosePreview($restaurant, $owner);
        $this->assertFalse($preview['can_close']);
        $this->closeDay($restaurant, $owner)->assertUnprocessable()->assertJsonPath('error.code', 'CLOSE_BLOCKED');
        $this->assertTrue($session->fresh()->isActive());
        $this->assertTrue(RestaurantDailyOperation::sole()->isOpen());

        app(CloseTableAction::class)->execute($session->fresh(), $owner);
        $this->closeDay($restaurant, $owner)->assertCreated();
        $this->assertFalse(RestaurantDailyOperation::sole()->isOpen());
    }

    public function test_replay_survives_the_close_without_writing_and_new_key_is_refused(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($org));
        $this->at('2026-10-02 10:00:00');
        $this->openDay($restaurant, $owner);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $items = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $headers = ['Idempotency-Key' => 'qr-1', 'X-Table-Visit' => $session->feedback_token];
        $orderId = $this->postJson($url, $items, $headers)->assertCreated()->json('data.id');
        $order = Order::findOrFail($orderId);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session->refresh(), $owner, '10.00');
        app(CloseTableAction::class)->execute($session->fresh(), $owner);
        $this->closeDay($restaurant, $owner)->assertCreated();

        $this->postJson($url, $items, $headers)->assertOk()->assertJsonPath('data.id', $orderId);
        $this->assertSame(1, Order::count());
        $this->as($owner)->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])->assertStatus(409);
    }

    public function test_new_operation_starts_from_zero_and_the_closed_report_is_preserved(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-02 10:00:00');
        $this->openDay($restaurant, $owner, '50.00');
        $this->servedPaidSession($restaurant, $owner, '40.00', 'cash');
        $this->servedPaidSession($restaurant, $owner, '25.00', 'card');
        $this->at('2026-10-02 21:00:00');
        $first = $this->closeDay($restaurant, $owner, ['cash_left_for_next_day' => '80.00'])->assertCreated()->json('data');
        $this->assertSame('65.00', $first['total_received']);
        $this->assertSame('90.00', $first['expected_cash']);
        $firstClose = RestaurantDayClose::where('public_id', $first['public_id'])->firstOrFail();
        $hash = $firstClose->report_sha256;

        $this->at('2026-10-03 09:00:00');
        $this->state($restaurant, $owner)->assertJsonPath('data.cash.suggested_opening_float', '80.00')
            ->assertJsonPath('data.cash.suggested_opening_float_source', 'previous_close');
        $second = $this->openDay($restaurant, $owner, '75.00');
        $this->assertSame('80.00', (string) $second->suggested_opening_float, 'a different declared float keeps the suggestion for audit');
        $this->state($restaurant, $owner)->assertJsonPath('data.cash.received', '0.00')->assertJsonPath('data.cash.sessions_opened', 0)
            ->assertJsonPath('data.cash.opening_float', '75.00');
        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")->assertOk()
            ->assertJsonPath('data.summary.sales.operation', ['state' => 'open', 'business_date' => '2026-10-03', 'received' => '0.00'])
            ->assertJsonPath('data.daily_operation.business_date', '2026-10-03')
            ->assertJsonMissingPath('data.daily_operation.opening_float');

        $this->servedPaidSession($restaurant, $owner, '12.00', 'cash');
        $this->state($restaurant, $owner)->assertJsonPath('data.cash.received', '12.00');
        $this->assertSame($hash, $firstClose->fresh()->report_sha256);
        $this->as($owner)->getJson("/api/v1/day-closes/{$firstClose->id}")->assertOk()->assertJsonPath('data.total_received', '65.00');
    }

    /**
     * Correction B: the Dashboard card keeps one meaning — the open day,
     * then the last closed day (flagged), then the next day from zero;
     * "none" before any day. Opening float never counted; per restaurant.
     */
    public function test_dashboard_operation_card_is_stable_across_open_close_and_reopen(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $other = Restaurant::factory()->create(['organization_id' => $org->id]);
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $kitchen = $this->createStaff($org, $restaurant, 'kitchen', 'K1');
        $live = fn ($user, $r = null) => $this->as($user)->getJson('/api/v1/restaurants/'.($r ?? $restaurant)->id.'/operations/live');

        $live($owner)->assertOk()->assertJsonPath('data.summary.sales.operation', ['state' => 'none']);

        $this->at('2026-10-21 08:00:00');
        $this->openDay($restaurant, $owner, '200.00');
        $live($waiter)->assertOk()->assertJsonPath('data.summary.sales.operation', ['state' => 'open', 'business_date' => '2026-10-21', 'received' => '0.00']);
        $this->servedPaidSession($restaurant, $owner, '20.00', 'cash');
        $this->servedPaidSession($restaurant, $owner, '5.50', 'card');
        $live($owner)->assertJsonPath('data.summary.sales.operation.received', '25.50');

        $this->at('2026-10-22 05:00:00'); // closed after the next cutoff
        $this->closeDay($restaurant, $owner)->assertCreated();
        $live($owner)->assertJsonPath('data.summary.sales.operation', [
            'state' => 'closed', 'business_date' => '2026-10-21', 'closed_at' => '2026-10-22T05:00:00Z', 'received' => '25.50',
        ]);

        $this->at('2026-10-22 06:00:00');
        $this->openDay($restaurant, $owner, '25.50');
        $live($owner)->assertJsonPath('data.summary.sales.operation', ['state' => 'open', 'business_date' => '2026-10-22', 'received' => '0.00']);

        $live($owner, $other)->assertOk()->assertJsonPath('data.summary.sales.operation', ['state' => 'none']);
        $live($kitchen)->assertForbidden();
    }
}
