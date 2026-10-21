<?php

namespace Tests\Feature\Public;

use App\Actions\Tables\OpenTableAction;
use App\Events\Realtime\TableSessionOpened;
use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\RestaurantActivityEvent;
use App\Models\TableSession;
use App\Support\Activity\RestaurantActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicTableEntryTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    public function test_gets_do_not_open_tables_or_backfill_tokens(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk()->assertJsonPath('data.session.active', false);
        $this->assertDatabaseCount('table_sessions', 0);
        $session = $this->openSession($table, $owner);
        $session->update(['feedback_token' => null]);
        $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();
        $this->assertNull($session->fresh()->feedback_token);
    }

    public function test_valid_qr_opens_once_with_public_actor_and_authoritative_context(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $url = "/api/v1/public/tables/{$table->public_token}/enter";
        $first = $this->postJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $first->assertJsonPath('data.session.active', true)->assertJsonPath('data.session.status', 'occupied');
        $token = $first->json('data.session.feedback.token');
        $this->assertNotEmpty($token);
        $this->postJson($url)->assertOk()->assertJsonPath('data.session.feedback.token', $token);
        $this->postJson($url, [], ['X-Table-Visit' => $token])->assertOk()->assertJsonPath('data.session.feedback.token', $token);
        $this->assertDatabaseCount('table_sessions', 1);
        $session = TableSession::query()->sole();
        $this->assertSame($restaurant->id, $session->restaurant_id);
        $this->assertSame($table->id, $session->table_id);
        $this->assertNull($session->opened_by_user_id);
        $this->assertSame(0, $session->guest_count);
        $audit = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_OPENED)->sole();
        $this->assertSame(AuditLog::ACTOR_PUBLIC, $audit->actor_type);
        $this->assertNull($audit->actor_user_id);
        $this->assertSame(1, RestaurantActivityEvent::query()->where('type', RestaurantActivityType::TABLE_SESSION_OPENED)->count());
        $this->assertStringNotContainsString($token, json_encode($audit->metadata));
        $this->assertArrayNotHasKey('id', $first->json('data.session'));
        $this->assertArrayNotHasKey('orders', $first->json('data'));
    }

    public function test_existing_staff_session_is_reused_without_changing_service(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner, 4);
        $waiter = $this->createStaff($restaurant->organization, $restaurant, 'waiter', 'W-QR');
        $session->update(['assigned_waiter_user_id' => $waiter->id]);
        $before = $session->fresh()->getAttributes();
        $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertOk();
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('table_sessions', 1);
        $this->expectException(TableSessionConflictException::class);
        app(OpenTableAction::class)->execute($table, $owner, 2);
    }

    public function test_legacy_token_backfill_is_only_done_by_entry_post(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $session->update(['feedback_token' => null]);
        $token = $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertOk()->json('data.session.feedback.token');
        $this->assertNotEmpty($token);
        $this->assertSame($token, $session->fresh()->feedback_token);
    }

    public function test_invalid_revoked_and_inactive_qrs_do_not_create_sessions(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $old = $table->public_token;
        $table->update(['public_token' => 'rotated-test-token']);
        foreach (['unknown-qr', $old] as $qr) {
            $this->postJson("/api/v1/public/tables/{$qr}/enter")->assertNotFound()->assertJsonPath('error.code', 'PUBLIC_TABLE_NOT_FOUND');
        }
        $table->update(['status' => 'inactive']);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertNotFound();
        $table->update(['status' => 'active']);
        $restaurant->update(['status' => 'inactive']);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertNotFound();
        $this->assertDatabaseCount('table_sessions', 0);
    }

    public function test_arbitrary_ids_and_foreign_visit_cannot_select_another_table(): void
    {
        [, , $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $a = $this->createTable($restaurantA);
        $b = $this->createTable($restaurantB);
        $token = $this->postJson("/api/v1/public/tables/{$a->public_token}/enter", ['restaurant_id' => $restaurantB->id, 'table_id' => $b->id])->assertOk()->json('data.session.feedback.token');
        $this->assertDatabaseHas('table_sessions', ['table_id' => $a->id, 'restaurant_id' => $restaurantA->id]);
        $this->postJson("/api/v1/public/tables/{$b->public_token}/enter", [], ['X-Table-Visit' => $token])->assertConflict()->assertJsonPath('error.code', 'PUBLIC_VISIT_INVALID');
        $this->getJson("/api/v1/public/tables/{$b->public_token}", ['X-Table-Visit' => $token])->assertConflict();
        $this->assertDatabaseCount('table_sessions', 1);
    }

    public function test_closed_visit_never_reopens_or_follows_the_next_session(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $url = "/api/v1/public/tables/{$table->public_token}";
        $token = $this->postJson("$url/enter")->assertOk()->json('data.session.feedback.token');
        $old = TableSession::query()->sole();
        $old->update(['status' => 'closed', 'closed_at' => now()]);
        $headers = ['X-Table-Visit' => $token];
        $this->postJson("$url/enter", [], $headers)->assertOk()->assertJsonPath('data.session.active', false);
        $this->assertDatabaseCount('table_sessions', 1);
        $newToken = $this->postJson("$url/enter")->assertOk()->json('data.session.feedback.token');
        $this->assertNotSame($token, $newToken);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.session.active', false)->assertJsonPath('data.session.feedback.token', $token);
        $this->postJson("$url/enter", [], $headers)->assertOk()->assertJsonPath('data.session.active', false);
        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $this->postJson("$url/orders", $payload, $headers)->assertConflict()->assertJsonPath('error.code', 'TABLE_SESSION_NOT_ACTIVE');
        $this->postJson("$url/requests/call-waiter", [], $headers)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('table_sessions', 2);
        $this->assertSame('closed', $old->fresh()->status);
    }

    public function test_paid_visit_keeps_post_visit_identity_and_paid_order_error(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $url = "/api/v1/public/tables/{$table->public_token}";
        $token = $this->postJson("$url/enter")->assertOk()->json('data.session.feedback.token');
        $old = TableSession::query()->sole();
        $old->update(['payment_status' => 'paid', 'paid_at' => now()]);
        $this->postJson("$url/enter")->assertConflict()->assertJsonPath('error.code', 'TABLE_SESSION_ALREADY_PAID');
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.session.feedback.token');
        $this->postJson("$url/enter", [], ['X-Table-Visit' => $token])->assertOk()->assertJsonPath('data.session.feedback.eligible', true);
        $old->update(['status' => 'closed', 'closed_at' => now()]);
        $this->postJson("$url/enter")->assertOk();
        $this->postJson("$url/orders", ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]], ['X-Table-Visit' => $token])->assertConflict()->assertJsonPath('error.code', 'TABLE_SESSION_ALREADY_PAID');
        $this->getJson($url, ['X-Table-Visit' => $token])->assertOk()->assertJsonPath('data.session.feedback.eligible', true)->assertJsonPath('data.session.feedback.token', $token);
        $this->assertSame(0, $old->orders()->count());
    }

    public function test_order_requires_visit_header_and_remains_waiting_for_waiter_approval(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $url = "/api/v1/public/tables/{$table->public_token}";
        $token = $this->postJson("$url/enter")->assertOk()->json('data.session.feedback.token');
        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $this->postJson("$url/orders", $payload)->assertConflict()->assertJsonPath('error.code', 'PUBLIC_VISIT_INVALID');
        $this->postJson("$url/requests/call-waiter")->assertConflict()->assertJsonPath('error.code', 'PUBLIC_VISIT_INVALID');
        $headers = ['X-Table-Visit' => $token, 'Idempotency-Key' => 'entry-order-test'];
        $this->postJson("$url/orders", $payload, $headers)->assertCreated()->assertJsonPath('data.status', 'waiting_approval');
        $this->postJson("$url/orders", $payload, $headers)->assertOk();
        $order = Order::query()->sole();
        $this->assertSame('customer_qr', $order->origin);
        $this->assertNull($order->approved_at);
        $this->assertSame(TableSession::query()->sole()->id, $order->table_session_id);
    }

    public function test_new_session_never_inherits_old_orders_or_idempotency_keys(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $url = "/api/v1/public/tables/{$table->public_token}";
        $token = $this->postJson("$url/enter")->json('data.session.feedback.token');
        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['X-Table-Visit' => $token, 'Idempotency-Key' => 'same-key'];
        $this->postJson("$url/orders", $payload, $headers)->assertCreated();
        $old = TableSession::query()->sole();
        $old->update(['status' => 'closed', 'closed_at' => now()]);
        $new = $this->postJson("$url/enter")->json('data.session.feedback.token');
        $this->postJson("$url/orders", $payload, ['X-Table-Visit' => $new, 'Idempotency-Key' => 'same-key'])->assertCreated();
        $this->assertSame(1, $old->orders()->count());
        $this->assertSame(2, Order::query()->distinct()->count('table_session_id'));
        // A delayed old retry can only recover the old order, never create in the new visit.
        $this->postJson("$url/orders", $payload, $headers)->assertOk()->assertJsonPath('data.id', $old->orders()->sole()->id);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_entry_rate_limit_preserves_a_single_session(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertOk();
        }
        $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertTooManyRequests();
        $this->assertDatabaseCount('table_sessions', 1);
    }

    public function test_rollback_emits_no_opening_and_removes_audit_and_activity(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        Event::fake([TableSessionOpened::class]);
        DB::beginTransaction();
        app(OpenTableAction::class)->execute($table, null, 0, reuseActive: true);
        Event::assertNotDispatched(TableSessionOpened::class);
        DB::rollBack();
        Event::assertNotDispatched(TableSessionOpened::class);
        $this->assertDatabaseCount('table_sessions', 0);
        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_OPENED)->count());
        $this->assertSame(0, RestaurantActivityEvent::query()->where('type', RestaurantActivityType::TABLE_SESSION_OPENED)->count());
    }
}
