<?php

namespace Tests\Feature\Activity;

use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\RestaurantActivityRead;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 6.1A — one feed per restaurant, one read cursor per (restaurant,
 * user): unread_count, mark-as-read semantics, per-user and per-restaurant
 * independence, and no cross-tenant event ids.
 */
class ActivityReadStateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

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

    private function seedEvent(Restaurant $restaurant, User $actor): RestaurantActivityEvent
    {
        return app(RestaurantActivityRecorder::class)->record(
            restaurantId: $restaurant->id,
            type: RestaurantActivityType::TABLE_SESSION_OPENED,
            actor: ActivityActor::staff($actor),
            metadata: ['guest_count' => 2],
        );
    }

    private function unread(User $user, Restaurant $restaurant): int
    {
        return $this->as($user)
            ->getJson("/api/v1/restaurants/{$restaurant->id}/activity/unread-count")
            ->assertOk()
            ->json('data.unread_count');
    }

    private function markRead(User $user, Restaurant $restaurant, array $body = []): array
    {
        return $this->as($user)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/activity/read", $body)
            ->assertOk()
            ->json('data');
    }

    public function test_two_users_have_independent_cursors_on_the_same_restaurant(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M1');
        $this->seedEvent($restaurant, $owner);
        $this->seedEvent($restaurant, $owner);
        $latest = $this->seedEvent($restaurant, $owner);

        // Initially: everything is unread for both.
        $this->assertSame(3, $this->unread($owner, $restaurant));
        $this->assertSame(3, $this->unread($manager, $restaurant));

        // Owner marks read: only the owner's count drops.
        $this->assertSame(['last_read_event_id' => $latest->id, 'unread_count' => 0], $this->markRead($owner, $restaurant));
        $this->assertSame(0, $this->unread($owner, $restaurant));
        $this->assertSame(3, $this->unread($manager, $restaurant));

        // A new event: +1 for both.
        $this->seedEvent($restaurant, $owner);
        $this->assertSame(1, $this->unread($owner, $restaurant));
        $this->assertSame(4, $this->unread($manager, $restaurant));

        $this->assertSame(1, RestaurantActivityRead::query()->count(), 'One cursor row, never one row per event.');
    }

    public function test_the_feed_meta_carries_the_same_read_state(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $first = $this->seedEvent($restaurant, $owner);
        $this->seedEvent($restaurant, $owner);
        $this->markRead($owner, $restaurant, ['last_event_id' => $first->id]);

        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/activity?category=menu")
            ->assertOk()
            ->assertJsonCount(0, 'data.activity')
            ->assertJsonPath('meta.last_read_event_id', $first->id)
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_marking_up_to_a_visible_event_leaves_newer_ones_unread(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $visible = $this->seedEvent($restaurant, $owner);
        $this->seedEvent($restaurant, $owner);
        $this->seedEvent($restaurant, $owner);

        $this->assertSame(['last_read_event_id' => $visible->id, 'unread_count' => 2], $this->markRead($owner, $restaurant, ['last_event_id' => $visible->id]));
    }

    public function test_the_cursor_never_moves_backward(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $old = $this->seedEvent($restaurant, $owner);
        $new = $this->seedEvent($restaurant, $owner);

        $this->markRead($owner, $restaurant);
        $state = $this->markRead($owner, $restaurant, ['last_event_id' => $old->id]);

        $this->assertSame(['last_read_event_id' => $new->id, 'unread_count' => 0], $state);
    }

    public function test_an_empty_feed_is_a_harmless_no_op(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->assertSame(['last_read_event_id' => 0, 'unread_count' => 0], $this->markRead($owner, $restaurant));
        $this->assertSame(0, RestaurantActivityRead::query()->count());
    }

    public function test_cursors_are_per_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->seedEvent($restaurantA, $owner);
        $this->seedEvent($restaurantB, $owner);
        $this->seedEvent($restaurantB, $owner);

        $this->markRead($owner, $restaurantA);

        $this->assertSame(0, $this->unread($owner, $restaurantA));
        $this->assertSame(2, $this->unread($owner, $restaurantB));
    }

    public function test_an_event_id_of_another_restaurant_is_rejected(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        [, $foreignOwner, $foreignRestaurant] = $this->createTenant();
        $this->seedEvent($restaurantA, $owner);
        $eventB = $this->seedEvent($restaurantB, $owner);
        $foreignEvent = $this->seedEvent($foreignRestaurant, $foreignOwner);

        foreach ([$eventB->id, $foreignEvent->id, 999999] as $id) {
            $this->as($owner)
                ->postJson("/api/v1/restaurants/{$restaurantA->id}/activity/read", ['last_event_id' => $id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('last_event_id');
        }

        $this->assertSame(0, RestaurantActivityRead::query()->count());
    }

    public function test_marking_read_is_not_an_activity_event_itself(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->seedEvent($restaurant, $owner);

        $this->markRead($owner, $restaurant);

        $this->assertSame(1, RestaurantActivityEvent::query()->count());
    }
}
