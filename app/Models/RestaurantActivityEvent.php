<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a restaurant's operational activity feed (CARTA 6.1A) —
 * something that already happened during service ("order #1842 ready",
 * "bill requested at Mesa 12"). Created exclusively through
 * App\Support\Activity\RestaurantActivityRecorder, from inside the same
 * DB transaction as the domain mutation it describes.
 *
 * Append-only and immutable: no application code updates or deletes a
 * row, and there is no API route that could. Retention is a future
 * decision; nothing purges this table today.
 *
 * Rendered from its own snapshots (actor/table name, order reference) —
 * never from joins, so a later rename never rewrites history and the feed
 * needs no eager loading at all.
 */
#[Fillable([
    'restaurant_id', 'type', 'category', 'occurred_at',
    'actor_type', 'actor_user_id', 'actor_name_snapshot',
    'table_id', 'table_name_snapshot', 'table_session_id',
    'order_id', 'order_reference', 'table_request_id',
    'metadata', 'created_at',
])]
class RestaurantActivityEvent extends Model
{
    /**
     * Immutable, no updated_at — see the migration.
     */
    public $timestamps = false;

    public const ACTOR_STAFF = 'staff';

    /**
     * An anonymous QR guest. Never carries an id or a name — public
     * orders/requests have no real customer identity to snapshot.
     */
    public const ACTOR_CUSTOMER = 'customer';

    /**
     * @var array<int, string>
     */
    public const ACTOR_TYPES = [self::ACTOR_STAFF, self::ACTOR_CUSTOMER];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
