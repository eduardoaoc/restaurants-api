<?php

namespace App\Support\Activity;

use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\RestaurantActivityRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-user read cursor over a restaurant's single activity feed (CARTA
 * 6.1A): "unread" = every event of that restaurant with an id greater
 * than the user's last_read_event_id (0 when they never marked anything).
 * One cursor row per (restaurant, user) — no per-event read rows.
 */
class RestaurantActivityReadState
{
    public function lastReadEventId(Restaurant $restaurant, User $user): int
    {
        return (int) RestaurantActivityRead::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('user_id', $user->id)
            ->value('last_read_event_id');
    }

    public function unreadCount(Restaurant $restaurant, int $lastReadEventId): int
    {
        return RestaurantActivityEvent::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('id', '>', $lastReadEventId)
            ->count();
    }

    public function latestEventId(Restaurant $restaurant): int
    {
        return (int) RestaurantActivityEvent::query()->where('restaurant_id', $restaurant->id)->max('id');
    }

    /**
     * Moves the user's cursor forward to $upToEventId — never backward: a
     * stale client (older tab, delayed request) can't resurrect events
     * already read elsewhere. A single atomic upsert, so two concurrent
     * calls can't race each other into a lower value either.
     */
    public function markRead(Restaurant $restaurant, User $user, int $upToEventId): int
    {
        $now = now();

        RestaurantActivityRead::query()->upsert(
            [[
                'restaurant_id' => $restaurant->id,
                'user_id' => $user->id,
                'last_read_event_id' => $upToEventId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['restaurant_id', 'user_id'],
            [
                'last_read_event_id' => DB::raw('GREATEST(restaurant_activity_reads.last_read_event_id, excluded.last_read_event_id)'),
                'read_at' => $now,
                'updated_at' => $now,
            ],
        );

        return $this->lastReadEventId($restaurant, $user);
    }
}
