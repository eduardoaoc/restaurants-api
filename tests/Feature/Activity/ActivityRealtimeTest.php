<?php

namespace Tests\Feature\Activity;

use App\Events\Realtime\RestaurantActivityCreated;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 6.1A — `restaurant.activity.created`: one broadcast per persisted
 * event, only after commit, on restaurant.{id}.activity only, carrying the
 * exact REST item; and that channel's view_activity-gated authorization.
 */
class ActivityRealtimeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    private function as(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web');
    }

    public function test_broadcast_payload_is_the_persisted_rest_item_on_the_restaurant_activity_channel_only(): void
    {
        Event::fake([RestaurantActivityCreated::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $other = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $table = $this->createTable($restaurant, 'Mesa 3');
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 2]]);
        $this->advanceOrderTo($order, Order::STATUS_READY, $owner);

        $restItems = collect($this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/activity")->assertOk()->json('data.activity'))->keyBy('id');

        Event::assertDispatchedTimes(RestaurantActivityCreated::class, RestaurantActivityEvent::query()->count());
        Event::assertDispatched(RestaurantActivityCreated::class, function (RestaurantActivityCreated $event) use ($restItems, $restaurant, $other) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
            $payload = $event->broadcastWith();

            $this->assertSame(["private-restaurant.{$restaurant->id}.activity"], $channels);
            $this->assertNotContains("private-restaurant.{$other->id}.activity", $channels);
            $this->assertSame('restaurant.activity.created', $event->broadcastAs());
            $this->assertSame($restaurant->id, $payload['restaurant_id']);
            $this->assertEquals($restItems[$payload['activity']['id']], $payload['activity'], 'The socket item must be the REST item.');
            $this->assertSame($restItems[$payload['activity']['id']]['occurred_at'], $payload['activity']['occurred_at']);
            $this->assertSame($restItems[$payload['activity']['id']]['tone'], $payload['activity']['tone']);
            $this->assertContains($payload['activity']['tone'], ['neutral', 'positive', 'warning', 'critical']);

            return true;
        });
    }

    public function test_a_rolled_back_operation_never_broadcasts_an_activity(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $broadcasts = 0;
        Event::listen(RestaurantActivityCreated::class, function () use (&$broadcasts) {
            $broadcasts++;
        });

        try {
            DB::transaction(function () use ($table, $owner, $rp) {
                $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $broadcasts);

        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->assertSame(1, $broadcasts);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function channelCases(): array
    {
        return [
            'owner / activity' => ['owner', 'activity', 200],
            'manager / activity' => ['manager', 'activity', 200],
            'waiter / activity' => ['waiter', 'activity', 403],
            'kitchen / activity' => ['kitchen', 'activity', 403],
            'cashier / activity' => ['cashier', 'activity', 403],
            'manager of a sibling restaurant / activity' => ['sibling_manager', 'activity', 403],
            'owner of another organization / activity' => ['foreign_owner', 'activity', 403],
            // The operational channel itself is unchanged: waiters keep it.
            'waiter / operational channel' => ['waiter', 'operational', 200],
        ];
    }

    /**
     * One user per test on purpose: Sanctum's request guard caches the
     * first authenticated user for the whole test (same constraint as
     * ChannelAuthorizationTest, which this mirrors).
     */
    #[DataProvider('channelCases')]
    public function test_activity_channel_authorization_requires_view_activity_and_reachability(string $who, string $channel, int $expectedStatus): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        // See ChannelAuthorizationTest: channel closures are registered on
        // the broadcaster instance active at boot ('null' in phpunit.xml).
        require base_path('routes/channels.php');
        $this->withHeader('Origin', 'http://localhost:5173');

        [$organization, $owner, $restaurant] = $this->createTenant();

        $user = match ($who) {
            'owner' => $owner,
            'foreign_owner' => $this->createTenant()[1],
            'sibling_manager' => $this->createStaff($organization, Restaurant::factory()->create(['organization_id' => $organization->id]), 'manager', 'M2'),
            default => $this->createStaff($organization, $restaurant, $who, 'S1'),
        };

        $channelName = $channel === 'activity'
            ? "private-restaurant.{$restaurant->id}.activity"
            : "private-restaurant.{$restaurant->id}";

        $this->actingAs($user, 'web')
            ->postJson('/broadcasting/auth', ['channel_name' => $channelName, 'socket_id' => '1234.5678'])
            ->assertStatus($expectedStatus);
    }
}
