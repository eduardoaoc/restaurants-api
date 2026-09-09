<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\TableSessionOpened;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — an event raised for Restaurant A must only ever target
 * `restaurant.{A}` — never a global channel, never Restaurant B's channel
 * (item 46), even when both restaurants belong to the same Organization.
 */
class RealtimeIsolationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_opening_a_session_at_restaurant_a_only_targets_restaurant_as_channel(): void
    {
        Event::fake([TableSessionOpened::class]);

        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableA = $this->createTable($restaurantA);

        $this->openSession($tableA, $owner);

        Event::assertDispatched(TableSessionOpened::class, function (TableSessionOpened $event) use ($restaurantA, $restaurantB) {
            $channelNames = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->restaurantId === $restaurantA->id
                && in_array("private-restaurant.{$restaurantA->id}", $channelNames, true)
                && ! in_array("private-restaurant.{$restaurantB->id}", $channelNames, true)
                && count($channelNames) === 1;
        });
    }
}
