<?php

namespace App\Events\Realtime;

use App\Http\Resources\Api\V1\RestaurantActivityEventResource;
use App\Models\RestaurantActivityEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Dispatched by RestaurantActivityRecorder right after persisting an
 * activity event (CARTA 6.1A). After-commit like every RealtimeEvent, so
 * a broadcast always has a committed row behind it with the same id.
 *
 * `activity` is exactly the REST item of GET .../activity (resolved and
 * JSON-normalized here, at dispatch time, so the queued job carries a
 * plain array — never a serialized Model).
 *
 * Its own channel, restaurant.{id}.activity, rather than restaurant.{id}:
 * the feed is gated by view_activity (owner/manager), while every member
 * of the restaurant may join restaurant.{id} (see routes/channels.php).
 */
class RestaurantActivityCreated extends RealtimeEvent
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $activity;

    public function __construct(RestaurantActivityEvent $event)
    {
        parent::__construct($event->restaurant_id);

        $this->activity = json_decode(json_encode((new RestaurantActivityEventResource($event))->resolve()), true);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("restaurant.{$this->restaurantId}.activity")];
    }

    public function broadcastAs(): string
    {
        return 'restaurant.activity.created';
    }

    protected function payload(): array
    {
        return ['activity' => $this->activity];
    }
}
