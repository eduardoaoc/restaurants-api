<?php

namespace Tests\Feature\Activity;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 6.1A — GET /restaurants/{restaurant}/activity: newest-first cursor
 * feed, filters, the snapshot-only payload, permission + tenant gates and
 * a flat query count.
 */
class ActivityTimelineTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * Sanctum's request guard caches the first authenticated user for the
     * whole test — forget it so each request really runs as $user.
     */
    private function as(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function url(Restaurant $restaurant, array $query = []): string
    {
        return "/api/v1/restaurants/{$restaurant->id}/activity".($query ? '?'.http_build_query($query) : '');
    }

    /**
     * A table-session-opened event at a given instant, straight through
     * the recorder (the Actions themselves are covered by
     * ActivityRecordingTest).
     */
    private function seedEvent(Restaurant $restaurant, User $actor, string $type = RestaurantActivityType::TABLE_SESSION_OPENED, ?Carbon $at = null): RestaurantActivityEvent
    {
        $metadata = match ($type) {
            RestaurantActivityType::TABLE_SESSION_OPENED => ['guest_count' => 2],
            RestaurantActivityType::TABLE_SESSION_CLOSED => ['total' => '10.00'],
            default => [],
        };

        return app(RestaurantActivityRecorder::class)->record(
            restaurantId: $restaurant->id,
            type: $type,
            actor: ActivityActor::staff($actor),
            metadata: $metadata,
            occurredAt: $at,
        );
    }

    public function test_real_flow_is_returned_newest_first_with_snapshot_payload(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $owner->update(['name' => 'Carlos García']);
        $table = $this->createTable($restaurant, 'Mesa 12');
        $session = $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 2]]);
        $this->advanceOrderTo($order, Order::STATUS_READY, $owner);

        $response = $this->as($owner)->getJson($this->url($restaurant))->assertOk();

        $this->assertSame([
            RestaurantActivityType::ORDER_READY,
            RestaurantActivityType::ORDER_PREPARING,
            RestaurantActivityType::ORDER_ACCEPTED,
            RestaurantActivityType::ORDER_CREATED,
            RestaurantActivityType::TABLE_SESSION_OPENED,
        ], array_column($response->json('data.activity'), 'type'));

        $ready = $response->json('data.activity.0');
        $this->assertSame(['id', 'type', 'category', 'tone', 'occurred_at', 'actor', 'table', 'table_session_id', 'order', 'table_request_id', 'metadata'], array_keys($ready));
        $this->assertSame('orders', $ready['category']);
        $this->assertSame('positive', $ready['tone']);
        $this->assertSame(['type' => 'staff', 'id' => $owner->id, 'name' => 'Carlos García'], $ready['actor']);
        $this->assertSame(['id' => $table->id, 'name' => 'Mesa 12'], $ready['table']);
        $this->assertSame($session->id, $ready['table_session_id']);
        $this->assertSame(['id' => $order->id, 'reference' => "#{$order->id}"], $ready['order']);
        $this->assertNull($ready['table_request_id']);
        $this->assertNull($ready['metadata']);
        $this->assertSame($order->fresh()->ready_at->toISOString(), Carbon::parse($ready['occurred_at'])->toISOString());

        $created = $response->json('data.activity.3');
        $this->assertSame(['type' => 'customer', 'id' => null, 'name' => null], $created['actor']);
        $this->assertSame('neutral', $created['tone']);
        $this->assertEquals(['origin' => 'customer_qr', 'initial_status' => $order->status, 'item_count' => 2, 'total' => (string) $order->total], $created['metadata']);

        $opened = $response->json('data.activity.4');
        $this->assertSame(['guest_count' => 2], $opened['metadata']);
        $this->assertNull($opened['order']);
    }

    public function test_cursor_pagination_walks_the_whole_feed_without_gaps_or_duplicates(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $ids = collect(range(1, 7))->map(fn () => $this->seedEvent($restaurant, $owner)->id)->reverse()->values()->all();

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['per_page' => 3] + ($cursor ? ['cursor' => $cursor] : []);
            $response = $this->as($owner)->getJson($this->url($restaurant, $query))->assertOk();
            $seen = array_merge($seen, array_column($response->json('data.activity'), 'id'));
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertSame($ids, $seen);
        $this->assertSame(3, $response->json('meta.per_page'));
    }

    public function test_events_arriving_between_pages_do_not_shift_older_pages(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $older = collect(range(1, 4))->map(fn () => $this->seedEvent($restaurant, $owner)->id)->all();

        $first = $this->as($owner)->getJson($this->url($restaurant, ['per_page' => 2]))->assertOk();
        $this->seedEvent($restaurant, $owner);
        $this->seedEvent($restaurant, $owner);

        $second = $this->as($owner)
            ->getJson($this->url($restaurant, ['per_page' => 2, 'cursor' => $first->json('meta.next_cursor')]))
            ->assertOk();

        $this->assertSame([$older[1], $older[0]], array_column($second->json('data.activity'), 'id'));
    }

    public function test_default_page_size_is_25_and_max_is_enforced(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        foreach (range(1, 30) as $i) {
            $this->seedEvent($restaurant, $owner);
        }

        $response = $this->as($owner)->getJson($this->url($restaurant))->assertOk();
        $this->assertCount(25, $response->json('data.activity'));
        $this->assertNotNull($response->json('meta.next_cursor'));

        $this->as($owner)->getJson($this->url($restaurant, ['per_page' => 101]))->assertStatus(422);
        $this->as($owner)->getJson($this->url($restaurant, ['per_page' => 0]))->assertStatus(422);
    }

    public function test_category_filter(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->seedEvent($restaurant, $owner, RestaurantActivityType::TABLE_SESSION_OPENED);
        $this->seedEvent($restaurant, $owner, RestaurantActivityType::ORDER_READY);
        $this->seedEvent($restaurant, $owner, RestaurantActivityType::ORDER_SERVED);

        $response = $this->as($owner)->getJson($this->url($restaurant, ['category' => 'orders']))->assertOk();

        $this->assertSame([RestaurantActivityType::ORDER_SERVED, RestaurantActivityType::ORDER_READY], array_column($response->json('data.activity'), 'type'));
        $this->as($owner)->getJson($this->url($restaurant, ['category' => 'alerts']))->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_type_filter(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->seedEvent($restaurant, $owner, RestaurantActivityType::ORDER_READY);
        $served = $this->seedEvent($restaurant, $owner, RestaurantActivityType::ORDER_SERVED);

        $response = $this->as($owner)->getJson($this->url($restaurant, ['type' => 'order.served']))->assertOk();

        $this->assertSame([$served->id], array_column($response->json('data.activity'), 'id'));
        $this->as($owner)->getJson($this->url($restaurant, ['type' => 'order.cancelled']))->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_period_filter_is_half_open_on_occurred_at(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->seedEvent($restaurant, $owner, at: Carbon::parse('2026-09-30T21:59:59Z'));
        $inside = $this->seedEvent($restaurant, $owner, at: Carbon::parse('2026-09-30T22:00:00Z'));
        $this->seedEvent($restaurant, $owner, at: Carbon::parse('2026-10-01T22:00:00Z'));

        // A Madrid local day (UTC+2) expressed as instants by the client.
        $response = $this->as($owner)
            ->getJson($this->url($restaurant, ['from' => '2026-10-01T00:00:00+02:00', 'to' => '2026-10-02T00:00:00+02:00']))
            ->assertOk();

        $this->assertSame([$inside->id], array_column($response->json('data.activity'), 'id'));

        $this->as($owner)
            ->getJson($this->url($restaurant, ['from' => '2026-10-02T00:00:00Z', 'to' => '2026-10-01T00:00:00Z']))
            ->assertStatus(422)->assertJsonValidationErrors('to');
        $this->as($owner)->getJson($this->url($restaurant, ['from' => 'yesterday-ish']))->assertStatus(422);
    }

    public function test_manager_of_the_restaurant_can_read_it(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M1');
        $this->seedEvent($restaurant, $owner);

        $this->as($manager)->getJson($this->url($restaurant))->assertOk()->assertJsonCount(1, 'data.activity');
    }

    public function test_waiter_kitchen_and_cashier_are_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();

        foreach (['waiter', 'kitchen', 'cashier'] as $i => $role) {
            $staff = $this->createStaff($organization, $restaurant, $role, "S{$i}");

            $this->as($staff)->getJson($this->url($restaurant))->assertForbidden();
            $this->as($staff)->getJson($this->url($restaurant).'/unread-count')->assertForbidden();
            $this->as($staff)->postJson($this->url($restaurant).'/read')->assertForbidden();
        }
    }

    public function test_tenant_isolation_across_organizations_and_restaurant_scope(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $siblingA = Restaurant::factory()->create(['organization_id' => $organizationA->id]);
        $managerOfSibling = $this->createStaff($organizationA, $siblingA, 'manager', 'M9');

        $eventA = $this->seedEvent($restaurantA, $ownerA);
        $this->seedEvent($restaurantB, $ownerB);

        $response = $this->as($ownerA)->getJson($this->url($restaurantA))->assertOk();
        $this->assertSame([$eventA->id], array_column($response->json('data.activity'), 'id'));

        // Another organization's restaurant — never reachable.
        $this->as($ownerA)->getJson($this->url($restaurantB))->assertNotFound();
        $this->as($ownerA)->getJson($this->url($restaurantB).'/unread-count')->assertNotFound();
        $this->as($ownerA)->postJson($this->url($restaurantB).'/read')->assertNotFound();

        // Same organization, outside the manager's RestaurantScope.
        $this->as($managerOfSibling)->getJson($this->url($restaurantA))->assertNotFound();
        $this->as($managerOfSibling)->getJson($this->url($siblingA))->assertOk()->assertJsonCount(0, 'data.activity');
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->getJson($this->url($restaurant))->assertUnauthorized();
    }

    public function test_query_count_is_flat_regardless_of_page_size(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();

        foreach (range(1, 5) as $i) {
            $table = $this->createTable($restaurant);
            $this->openSession($table, $owner);
            $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
            $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        }

        $this->assertSame(30, RestaurantActivityEvent::query()->count());

        $countFor = function (int $perPage) use ($owner, $restaurant): int {
            $this->as($owner);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url($restaurant, ['per_page' => $perPage]))->assertOk()->assertJsonCount($perPage, 'data.activity');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $countFor(2);
        $large = $countFor(30);

        $this->assertSame($small, $large, 'The feed query count must not grow with the number of events rendered.');
    }
}
