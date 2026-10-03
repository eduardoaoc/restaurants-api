<?php

namespace Tests\Feature\Kitchen;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\TransferTableSessionAction;
use App\Events\Realtime\OrderReadyForWaiter;
use App\Events\Realtime\OrderStatusChanged;
use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\Order;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 7.1A — `order.ready.attention` on private-user.{waiterId}: exactly
 * one per real preparing -> ready, only to the session's responsible
 * waiter (TableSession.assigned_waiter_user_id), never guessed, never
 * after a rollback; plus the user channel's own authorization.
 */
class OrderReadyWaiterNotificationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * An order on $table driven to `preparing`.
     */
    private function preparingOrder(Table $table, User $actor, RestaurantProduct $rp): Order
    {
        $order = $this->createWaiterOrder($table, $actor, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return $this->advanceOrderTo($order, Order::STATUS_PREPARING, $actor);
    }

    private function assign(TableSession $session, User $waiter, User $actor): void
    {
        app(AssignWaiterAction::class)->execute($session, $waiter, $actor);
    }

    private function markReady(Order $order, User $actor): Order
    {
        return app(TransitionOrderStatusAction::class)->markReady($order, $actor);
    }

    public function test_ready_sends_exactly_one_directed_event_to_the_assigned_waiter_with_a_minimal_payload(): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant, 'Mesa 07');
        $session = $this->openSession($table, $owner);
        $this->assign($session, $waiter, $owner);
        $order = $this->preparingOrder($table, $owner, $rp);

        $ready = $this->markReady($order, $owner);

        Event::assertDispatchedTimes(OrderReadyForWaiter::class, 1);
        Event::assertDispatched(OrderReadyForWaiter::class, function (OrderReadyForWaiter $event) use ($waiter, $restaurant, $order, $table, $session, $ready) {
            $this->assertSame(["private-user.{$waiter->id}"], array_map(fn ($c) => $c->name, $event->broadcastOn()));
            $this->assertSame('order.ready.attention', $event->broadcastAs());

            $payload = $event->broadcastWith();
            unset($payload['event_id'], $payload['occurred_at']);
            $this->assertSame([
                'schema_version' => 1,
                'restaurant_id' => $restaurant->id,
                'order' => ['id' => $order->id, 'reference' => "#{$order->id}"],
                'table' => ['id' => $table->id, 'name' => 'Mesa 07'],
                'table_session_id' => $session->id,
                'ready_at' => $ready->ready_at->toISOString(),
            ], $payload);

            return true;
        });
    }

    public function test_only_the_waiter_of_that_table_is_addressed(): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'WA');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'WB');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $this->assign($this->openSession($tableA, $owner), $waiterA, $owner);
        $this->assign($this->openSession($tableB, $owner), $waiterB, $owner);

        $this->markReady($this->preparingOrder($tableA, $owner, $rp), $owner);

        Event::assertDispatchedTimes(OrderReadyForWaiter::class, 1);
        Event::assertNotDispatched(OrderReadyForWaiter::class, fn (OrderReadyForWaiter $e) => $e->waiterUserId === $waiterB->id);
        Event::assertDispatched(OrderReadyForWaiter::class, fn (OrderReadyForWaiter $e) => $e->waiterUserId === $waiterA->id);
    }

    public function test_a_waiter_of_another_restaurant_never_receives_it(): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurantA, $rp] = $this->createTenantWithRestaurantProduct();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'WA');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'WB');
        [, $foreignOwner, $foreignRestaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurantA);
        $this->assign($this->openSession($tableA, $owner), $waiterA, $owner);
        $this->openSession($this->createTable($foreignRestaurant), $foreignOwner);

        $this->markReady($this->preparingOrder($tableA, $owner, $rp), $owner);

        Event::assertDispatched(OrderReadyForWaiter::class, function (OrderReadyForWaiter $e) use ($waiterA, $restaurantA) {
            return $e->waiterUserId === $waiterA->id && $e->restaurantId === $restaurantA->id;
        });
        Event::assertNotDispatched(OrderReadyForWaiter::class, fn (OrderReadyForWaiter $e) => $e->waiterUserId !== $waiterA->id);
        $this->assertNotSame($waiterB->id, $waiterA->id);
    }

    public function test_without_an_assigned_waiter_nobody_is_invented_and_restaurant_wide_signals_remain(): void
    {
        Event::fake([OrderReadyForWaiter::class, OrderStatusChanged::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->preparingOrder($table, $owner, $rp);

        $this->markReady($order, $owner);

        Event::assertNotDispatched(OrderReadyForWaiter::class);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => $e->orderId === $order->id && $e->status === Order::STATUS_READY);

        $alerts = $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")->assertOk()->json('data.alerts');
        $this->assertContains("order-ready-{$order->id}", array_column($alerts, 'id'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ineligibleWaiters(): array
    {
        return [
            'suspended account' => ['suspended'],
            'inactive membership in the organization' => ['inactive_membership'],
            'no longer a member of the restaurant' => ['left_restaurant'],
        ];
    }

    #[DataProvider('ineligibleWaiters')]
    public function test_an_assigned_waiter_who_can_no_longer_receive_it_gets_nothing(string $case): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $this->assign($this->openSession($table, $owner), $waiter, $owner);
        $order = $this->preparingOrder($table, $owner, $rp);

        match ($case) {
            'suspended' => $waiter->update(['status' => User::STATUS_SUSPENDED]),
            'inactive_membership' => $organization->users()->updateExistingPivot($waiter->id, ['status' => OrganizationUser::STATUS_INACTIVE]),
            'left_restaurant' => $restaurant->users()->detach($waiter->id),
        };

        $this->markReady($order, $owner);

        Event::assertNotDispatched(OrderReadyForWaiter::class);
    }

    public function test_the_recipient_follows_the_session_through_transfer_and_reassignment(): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $first = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $second = $this->createStaff($organization, $restaurant, 'waiter', 'W2');
        $table = $this->createTable($restaurant, 'Mesa 03');
        $target = $this->createTable($restaurant, 'Terraza 1');
        $session = $this->openSession($table, $owner);
        $this->assign($session, $first, $owner);
        $orderOne = $this->preparingOrder($table, $owner, $rp);
        $orderTwo = $this->preparingOrder($table, $owner, $rp);

        app(TransferTableSessionAction::class)->execute($session->refresh(), $target, $owner);
        $this->markReady($orderOne->refresh(), $owner);

        $this->assign($session->refresh(), $second, $owner);
        $this->markReady($orderTwo->refresh(), $owner);

        Event::assertDispatchedTimes(OrderReadyForWaiter::class, 2);
        Event::assertDispatched(OrderReadyForWaiter::class, fn (OrderReadyForWaiter $e) => $e->orderId === $orderOne->id && $e->waiterUserId === $first->id && $e->tableName === 'Terraza 1');
        Event::assertDispatched(OrderReadyForWaiter::class, fn (OrderReadyForWaiter $e) => $e->orderId === $orderTwo->id && $e->waiterUserId === $second->id);
    }

    public function test_only_the_ready_transition_is_directed(): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $this->assign($this->openSession($table, $owner), $waiter, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        Event::assertDispatchedTimes(OrderReadyForWaiter::class, 1);
    }

    public function test_repeating_the_transition_never_duplicates_the_event(): void
    {
        Event::fake([OrderReadyForWaiter::class]);

        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $this->assign($this->openSession($table, $owner), $waiter, $owner);
        $order = $this->preparingOrder($table, $owner, $rp);

        $this->markReady($order, $owner);

        try {
            $this->markReady($order, $owner);
            $this->fail('A second ready transition should conflict.');
        } catch (OrderStateConflictException) {
            // expected
        }

        Event::assertDispatchedTimes(OrderReadyForWaiter::class, 1);
    }

    public function test_a_rolled_back_ready_transition_never_reaches_the_waiter(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $this->assign($this->openSession($table, $owner), $waiter, $owner);
        $order = $this->preparingOrder($table, $owner, $rp);

        $sent = 0;
        Event::listen(OrderReadyForWaiter::class, function () use (&$sent) {
            $sent++;
        });

        try {
            DB::transaction(function () use ($order, $owner) {
                $this->markReady($order, $owner);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $sent);
        $this->assertSame(Order::STATUS_PREPARING, $order->refresh()->status);

        $this->markReady($order, $owner);
        $this->assertSame(1, $sent);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function userChannelCases(): array
    {
        return [
            'own channel' => ['self', 200],
            'another waiter\'s channel' => ['other', 403],
        ];
    }

    /**
     * One user per test (Sanctum guard caching — see
     * ChannelAuthorizationTest).
     */
    #[DataProvider('userChannelCases')]
    public function test_user_channel_only_authorizes_its_own_user(string $whose, int $expectedStatus): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        require base_path('routes/channels.php');
        $this->withHeader('Origin', 'http://localhost:5173');

        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1');
        $colleague = $this->createStaff($organization, $restaurant, 'waiter', 'W2');
        $channelOwner = $whose === 'self' ? $waiter : $colleague;

        $this->actingAs($waiter, 'web')
            ->postJson('/broadcasting/auth', ['channel_name' => "private-user.{$channelOwner->id}", 'socket_id' => '1234.5678'])
            ->assertStatus($expectedStatus);
    }

    public function test_guest_cannot_authorize_a_user_channel(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/broadcasting/auth', ['channel_name' => 'private-user.1', 'socket_id' => '1234.5678'])
            ->assertUnauthorized();
    }
}
