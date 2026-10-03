<?php

namespace Tests\Feature\Kitchen;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 7.1A — GET /kitchen/dashboard: live kitchen queue summary, exact
 * "today" timings, top products by quantity and the recent accepted/ready
 * lists; empty states; permission/tenant gates; flat query count.
 */
class KitchenDashboardTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    /** 12:00 UTC = 14:00 Europe/Madrid — well inside the local day. */
    private const NOON = '2026-10-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->travelTo(Carbon::parse(self::NOON, 'UTC'));
    }

    private function dashboard(User $user, Restaurant $restaurant): array
    {
        return $this->actingAs($user, 'web')
            ->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")
            ->assertOk()
            ->json('data');
    }

    private function order(Table $table, User $actor, RestaurantProduct $rp, int $quantity = 1): Order
    {
        return $this->createWaiterOrder($table, $actor, [['restaurant_product_id' => $rp->id, 'quantity' => $quantity]]);
    }

    private function step(Order $order, string $method, User $actor, int $afterSeconds): Order
    {
        $this->travel($afterSeconds)->seconds();

        return app(TransitionOrderStatusAction::class)->{$method}($order, $actor);
    }

    public function test_empty_restaurant_returns_semantic_empties(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $data = $this->dashboard($owner, $restaurant);

        $this->assertSame(['confirmed' => 0, 'accepted' => 0, 'preparing' => 0, 'ready' => 0], $data['queue']['counts_by_status']);
        $this->assertSame(0, $data['queue']['active_orders']);
        $this->assertNull($data['queue']['oldest_active_order_age_seconds']);
        $this->assertNull($data['queue']['longest_ready_wait_seconds']);
        $this->assertSame([
            'accept' => ['average_seconds' => null, 'orders' => 0],
            'preparation' => ['average_seconds' => null, 'orders' => 0],
            'ready_to_served' => ['average_seconds' => null, 'orders' => 0],
        ], $data['timings']);
        $this->assertSame([], $data['top_products']);
        $this->assertSame([], $data['recent_accepted']);
        $this->assertSame([], $data['recent_ready']);
        $this->assertSame($restaurant->id, $data['restaurant']['id']);
    }

    public function test_queue_counts_only_kitchen_statuses_with_exact_ages(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        // Not kitchen work yet — must not count.
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->travel(60)->seconds();

        $oldest = $this->order($table, $owner, $rp);                 // created at +60
        $this->order($table, $owner, $rp);                           // stays confirmed
        $accepted = $this->step($this->order($table, $owner, $rp), 'accept', $owner, 0);
        $ready = $this->step($this->step($this->step($oldest, 'accept', $owner, 10), 'startPreparing', $owner, 10), 'markReady', $owner, 100);
        $this->travel(40)->seconds();

        $queue = $this->dashboard($owner, $restaurant)['queue'];

        $this->assertSame(['confirmed' => 1, 'accepted' => 1, 'preparing' => 0, 'ready' => 1], $queue['counts_by_status']);
        $this->assertSame(3, $queue['active_orders']);
        $this->assertSame(160, $queue['oldest_active_order_age_seconds'], 'now − created_at of the oldest kitchen order (the waiting_approval one is excluded).');
        $this->assertSame(40, $queue['longest_ready_wait_seconds'], 'now − ready_at of the oldest still-ready order.');
        $this->assertSame(Order::STATUS_READY, $ready->status);
        $this->assertSame(Order::STATUS_ACCEPTED, $accepted->status);
    }

    public function test_timings_use_exact_lifecycle_timestamps(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        // Staff order: confirmed at creation. accept 60s, preparation 300s, ready→served 90s.
        $a = $this->order($table, $owner, $rp);
        $a = $this->step($a, 'accept', $owner, 60);
        $a = $this->step($a, 'startPreparing', $owner, 20);
        $a = $this->step($a, 'markReady', $owner, 300);
        $this->step($a, 'serve', $owner, 90);

        // Customer order waits 600s for approval — NOT kitchen time —
        // then is accepted 30s after approval; preparation 100s; still ready.
        $b = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->travel(600)->seconds();
        $b = app(ApproveOrderAction::class)->execute($b, $owner);
        $b = $this->step($b, 'accept', $owner, 30);
        $b = $this->step($b, 'startPreparing', $owner, 5);
        $this->step($b, 'markReady', $owner, 100);

        $timings = $this->dashboard($owner, $restaurant)['timings'];

        $this->assertSame(['average_seconds' => 45, 'orders' => 2], $timings['accept']);
        $this->assertSame(['average_seconds' => 200, 'orders' => 2], $timings['preparation']);
        $this->assertSame(['average_seconds' => 90, 'orders' => 1], $timings['ready_to_served']);
    }

    public function test_timings_and_lists_only_cover_the_restaurants_local_today(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        // 21:30 UTC yesterday = 23:30 Madrid yesterday.
        $this->travelTo(Carbon::parse('2026-09-30 21:30:00', 'UTC'));
        $old = $this->order($table, $owner, $rp, 7);
        $old = $this->step($old, 'accept', $owner, 60);
        $old = $this->step($old, 'startPreparing', $owner, 60);
        $this->step($old, 'markReady', $owner, 60);

        $this->travelTo(Carbon::parse(self::NOON, 'UTC'));
        $data = $this->dashboard($owner, $restaurant);

        $this->assertSame(0, $data['timings']['accept']['orders']);
        $this->assertSame(0, $data['timings']['preparation']['orders']);
        $this->assertSame([], $data['top_products']);
        $this->assertSame([], $data['recent_accepted']);
        $this->assertSame([], $data['recent_ready']);
        // ...while the live queue still shows it, since it is still ready.
        $this->assertSame(1, $data['queue']['counts_by_status']['ready']);
    }

    public function test_top_products_rank_by_quantity_over_billable_orders_only_without_revenue(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $croquetas = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));
        $tortilla = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->order($table, $owner, $croquetas, 3);
        $this->order($table, $owner, $croquetas, 2);
        $this->order($table, $owner, $tortilla, 4);
        // Excluded: waiting_approval and rejected (cancelled).
        $this->createCustomerOrder($table, [['restaurant_product_id' => $tortilla->id, 'quantity' => 9]]);
        $rejected = $this->createCustomerOrder($table, [['restaurant_product_id' => $tortilla->id, 'quantity' => 9]]);
        app(RejectOrderAction::class)->execute($rejected, $owner);

        $top = $this->dashboard($owner, $restaurant)['top_products'];

        $this->assertSame([5, 4], array_column($top, 'quantity'));
        $this->assertSame([$croquetas->product_id, $tortilla->product_id], array_column($top, 'product_id'));
        $this->assertSame(['product_id', 'name', 'quantity'], array_keys($top[0]));
    }

    public function test_recent_lists_are_short_newest_first_and_carry_items_and_actors(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $cook = $this->createStaff($organization, $restaurant, 'kitchen', 'K1', name: 'Pablo Torres');
        $table = $this->createTable($restaurant, 'Mesa 07');
        $this->openSession($table, $owner);

        $orders = [];
        foreach (range(1, 6) as $i) {
            $orders[] = $this->step($this->order($table, $owner, $rp, $i), 'accept', $cook, 10);
        }
        $readyOne = $this->step($this->step($orders[0], 'startPreparing', $cook, 5), 'markReady', $cook, 5);
        $servedOne = $this->step($this->step($this->step($orders[1], 'startPreparing', $cook, 5), 'markReady', $cook, 5), 'serve', $owner, 30);

        $data = $this->dashboard($owner, $restaurant);

        $this->assertCount(5, $data['recent_accepted']);
        $this->assertSame(array_map(fn (Order $o) => $o->id, array_reverse(array_slice($orders, 1))), array_column(array_column($data['recent_accepted'], 'order'), 'id'));
        $first = $data['recent_accepted'][0];
        $this->assertSame(['order', 'table', 'status', 'accepted_at', 'accepted_by', 'items'], array_keys($first));
        $this->assertSame(['id' => $orders[5]->id, 'reference' => "#{$orders[5]->id}"], $first['order']);
        $this->assertSame(['id' => $table->id, 'name' => 'Mesa 07'], $first['table']);
        $this->assertSame(['id' => $cook->id, 'name' => 'Pablo Torres'], $first['accepted_by']);
        $this->assertSame([['name' => $orders[5]->items->first()->product_name_snapshot, 'quantity' => 6]], $first['items']);

        $this->assertSame([$servedOne->id, $readyOne->id], array_column(array_column($data['recent_ready'], 'order'), 'id'));
        [$served, $ready] = $data['recent_ready'];
        $this->assertSame(['order', 'table', 'status', 'ready_at', 'ready_by', 'items', 'served_at'], array_keys($served));
        $this->assertSame(Order::STATUS_SERVED, $served['status']);
        $this->assertNotNull($served['served_at']);
        $this->assertSame(Order::STATUS_READY, $ready['status']);
        $this->assertNull($ready['served_at'], 'Still waiting for a waiter.');
        $this->assertSame('Pablo Torres', $ready['ready_by']['name']);
        $this->assertStringNotContainsString('total', json_encode($data['recent_ready']));
    }

    public function test_kitchen_role_can_read_it(): void
    {
        [$organization, , $restaurant] = $this->createTenant();

        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K1');
        $this->actingAs($kitchen, 'web')->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")->assertOk();
    }

    public function test_waiter_can_read_it(): void
    {
        [$organization, , $restaurant] = $this->createTenant();

        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $this->actingAs($waiter, 'web')->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")->assertOk();
    }

    public function test_cashier_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();

        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C1');
        $this->actingAs($cashier, 'web')->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")->assertForbidden();
    }

    public function test_other_organization_restaurant_is_not_found(): void
    {
        [, $owner] = $this->createTenant();
        [, , $foreign] = $this->createTenant();

        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen/dashboard?restaurant_id={$foreign->id}")->assertNotFound();
    }

    public function test_restaurant_outside_the_kitchen_users_scope_is_not_found(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $sibling = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $kitchen = $this->createStaff($organization, $sibling, 'kitchen', 'K1');

        $this->actingAs($kitchen, 'web')->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")->assertNotFound();
    }

    public function test_restaurant_id_is_required(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/dashboard')->assertStatus(422)->assertJsonValidationErrors('restaurant_id');
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")->assertUnauthorized();
    }

    public function test_query_count_does_not_grow_with_volume(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $cook = $this->createStaff($organization, $restaurant, 'kitchen', 'K1');

        $seed = function (int $count) use ($table, $owner, $rp, $cook) {
            foreach (range(1, $count) as $i) {
                $order = $this->step($this->order($table, $owner, $rp), 'accept', $cook, 1);
                $this->step($this->step($order, 'startPreparing', $cook, 1), 'markReady', $cook, 1);
            }
        };

        $measure = function () use ($owner, $restaurant): int {
            $this->actingAs($owner, 'web');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/kitchen/dashboard?restaurant_id={$restaurant->id}")->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $seed(1);
        $small = $measure();
        $seed(12);
        $large = $measure();

        $this->assertSame($small, $large);
    }
}
