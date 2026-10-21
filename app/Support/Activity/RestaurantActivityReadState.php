<?php

namespace App\Support\Activity;

use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\RestaurantActivityRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    public function visibleQuery(Restaurant $restaurant, User $user, bool $assignmentsOnly = false): Builder
    {
        $query = RestaurantActivityEvent::query()->with('tableSession')->where('restaurant_id', $restaurant->id);
        if ($assignmentsOnly) {
            $canAssign = $user->hasRestaurantPermission('assign_waiters', $restaurant);
            $query->where(function ($query) use ($user, $canAssign) {
                $query->where(fn ($query) => $query->where('type', RestaurantActivityType::TABLE_WAITER_ASSIGNED)
                    ->where('metadata->recipient_user_id', $user->id));
                if ($canAssign) {
                    $query->orWhere('type', RestaurantActivityType::TABLE_ASSIGNMENT_PENDING);
                }
            });
        }

        return $query;
    }

    public function unreadCount(Restaurant $restaurant, int $lastReadEventId, ?User $user = null, bool $assignmentsOnly = false): int
    {
        return ($user ? $this->visibleQuery($restaurant, $user, $assignmentsOnly) : RestaurantActivityEvent::query()->where('restaurant_id', $restaurant->id))
            ->where('id', '>', $lastReadEventId)
            ->count();
    }

    public function latestEventId(Restaurant $restaurant, ?User $user = null, bool $assignmentsOnly = false): int
    {
        return (int) ($user ? $this->visibleQuery($restaurant, $user, $assignmentsOnly) : RestaurantActivityEvent::query()->where('restaurant_id', $restaurant->id))->max('id');
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
