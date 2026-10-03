<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * An internal Manager/Owner -> assigned-waiter escalation on an active
 * TableSession ("call responsible" — Bloco 4). Deliberately separate from
 * TableRequest, which is strictly customer-originated — see the migration.
 */
#[Fillable([
    'restaurant_id', 'table_session_id', 'waiter_user_id', 'called_by_user_id',
    'status', 'acknowledged_by_user_id', 'acknowledged_at',
])]
class WaiterCall extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACKNOWLEDGED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<TableSession, $this>
     */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /**
     * The waiter being called — a snapshot of the session's assigned
     * waiter at the moment the call was placed.
     *
     * @return BelongsTo<User, $this>
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Guards the domain invariant that a call's restaurant/session are
     * always mutually coherent — same principle already used by
     * Order/TableRequest/PaymentRecord.
     */
    protected static function booted(): void
    {
        static::saving(function (self $call) {
            $sessionRestaurantId = TableSession::query()->whereKey($call->table_session_id)->value('restaurant_id');

            if ($sessionRestaurantId !== $call->restaurant_id) {
                throw new InvalidArgumentException('The table session does not belong to the given restaurant.');
            }
        });
    }
}
