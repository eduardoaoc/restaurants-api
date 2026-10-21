<?php

namespace Tests\Feature\Public;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Actions\Tables\TransferTableSessionAction;
use App\Models\RestaurantActivityEvent;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicOrderTrackingTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    public function test_shared_visit_tracks_qr_statuses_without_staff_orders_or_sensitive_fields(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $payload = [['restaurant_product_id' => $rp->id, 'quantity' => 2, 'note' => 'Private note']];
        $staff = $this->createWaiterOrder($table, $owner, $payload);
        $qr = $this->createCustomerOrder($table, $payload, ['customer_name' => 'Private name', 'note' => 'Private order note']);
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $headers = ['X-Table-Visit' => $session->feedback_token];
        $this->getJson($url, $headers)->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.status', 'waiting_approval');
        $qr = app(ApproveOrderAction::class)->execute($qr, $owner);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.orders.0.status', 'confirmed');
        foreach (['accepted' => 'accept', 'preparing' => 'startPreparing', 'ready' => 'markReady', 'served' => 'serve'] as $status => $method) {
            $qr = app(TransitionOrderStatusAction::class)->{$method}($qr, $owner);
            $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.orders.0.status', $status);
        }
        $rejected = $this->createCustomerOrder($table, $payload);
        app(RejectOrderAction::class)->execute($rejected, $owner);
        $response = $this->getJson($url, $headers)->assertOk()->assertJsonCount(2, 'data.orders')->assertJsonPath('data.orders.0.status', 'cancelled');
        $json = $response->json('data.orders');
        $this->assertNotContains($staff->id, array_column($json, 'id'));
        foreach (['customer_name', 'customer_note', 'created_by_user_id', 'table_session_id', 'feedback_token', 'note', 'payments'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', json_encode($json));
        }
        $this->assertStringNotContainsString('Private', json_encode($json));
    }

    public function test_tracking_is_paginated_and_get_does_not_assign_open_or_mutate(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]])->id;
        }
        $before = $session->fresh()->getAttributes();
        $events = RestaurantActivityEvent::count();
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $headers = ['X-Table-Visit' => $session->feedback_token];
        $first = $this->getJson($url, $headers)->assertOk()->assertJsonCount(20, 'data.orders')->assertHeader('Cache-Control', 'no-store, private');
        $second = $this->getJson($url.'?before_id='.$first->json('meta.next_before_id'), $headers)->assertOk()->assertJsonCount(3, 'data.orders')->assertJsonPath('meta.next_before_id', null);
        $this->assertSame(array_reverse($ids), array_column(array_merge($first->json('data.orders'), $second->json('data.orders')), 'id'));
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertSame($events, RestaurantActivityEvent::count());
        $this->assertDatabaseCount('table_sessions', 1);
    }

    public function test_invalid_or_mismatched_visit_ids_cannot_discover_other_sessions(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $other = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $otherSession = $this->openSession($other, $owner);
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $this->getJson($url)->assertConflict()->assertJsonPath('error.code', 'PUBLIC_VISIT_INVALID');
        foreach (['wrong', $otherSession->feedback_token] as $token) {
            $this->getJson($url, ['X-Table-Visit' => $token])->assertConflict();
        }
        [, $foreignOwner, $foreignRestaurant] = $this->createTenant();
        $foreign = $this->createTable($foreignRestaurant);
        $foreignSession = $this->openSession($foreign, $foreignOwner);
        $this->getJson($url, ['X-Table-Visit' => $foreignSession->feedback_token])->assertConflict();
        $this->getJson($url."?table_session_id={$foreignSession->id}&restaurant_id={$foreignRestaurant->id}&table_id={$foreign->id}&order_id=999", ['X-Table-Visit' => $session->feedback_token])
            ->assertOk()->assertJsonCount(0, 'data.orders');
        $this->getJson('/api/v1/public/tables/unknown/orders', ['X-Table-Visit' => $session->feedback_token])->assertNotFound();
        $freshTable = $this->createTable($restaurant);
        $this->getJson("/api/v1/public/tables/{$freshTable->public_token}/orders")->assertConflict();
        $this->assertSame(3, TableSession::count());
    }

    public function test_transferred_visit_keeps_tracking_after_reload_at_original_qr(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $source = $this->createTable($restaurant);
        $target = $this->createTable($restaurant);
        $session = $this->openSession($source, $owner);
        $order = $this->createCustomerOrder($source, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(TransferTableSessionAction::class)->execute($session, $target, $owner);
        $new = $this->openSession($source, $owner);
        $newOrder = $this->createCustomerOrder($source, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $headers = ['X-Table-Visit' => $session->feedback_token];
        $url = "/api/v1/public/tables/{$source->public_token}";
        $this->getJson($url.'/orders', $headers)->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $order->id);
        $this->postJson($url.'/enter', [], $headers)->assertOk()->assertJsonPath('data.table.id', $target->id)->assertJsonPath('data.session.feedback.token', $session->feedback_token);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.table.id', $target->id);
        $this->assertSame($new->id, $newOrder->fresh()->table_session_id);
        $this->assertDatabaseCount('table_sessions', 2);
    }

    public function test_old_visit_remains_original_after_payment_close_and_new_occupation(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $old = $this->openSession($table, $owner);
        $qr = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($qr, 'served', $owner);
        $this->closeSessionWithFullPayment($old, $owner);
        $new = $this->openSession($table, $owner);
        $newQr = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $url = "/api/v1/public/tables/{$table->public_token}";
        $headers = ['X-Table-Visit' => $old->feedback_token];
        $this->getJson($url.'/orders', $headers)->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $qr->id);
        $this->getJson($url.'/orders', ['X-Table-Visit' => $new->feedback_token])->assertOk()->assertJsonPath('data.orders.0.id', $newQr->id);
        $this->postJson($url.'/orders', ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]], $headers)->assertConflict()->assertJsonPath('error.code', 'TABLE_SESSION_ALREADY_PAID');
        $this->getJson('/api/v1/public/visits/'.$old->feedback_token)->assertOk();
    }
}
