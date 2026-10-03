<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's read cursor on one restaurant's activity feed (CARTA 6.1A):
 * every RestaurantActivityEvent of that restaurant with
 * id > last_read_event_id is unread for this user. One row per
 * (restaurant, user), never one per event — see the migration.
 */
#[Fillable(['restaurant_id', 'user_id', 'last_read_event_id', 'read_at'])]
class RestaurantActivityRead extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_event_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }
}
