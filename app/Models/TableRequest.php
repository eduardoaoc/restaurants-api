<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'restaurant_id', 'table_id', 'table_session_id', 'type', 'status',
    'created_by_user_id',
    'acknowledged_by_user_id', 'acknowledged_at',
    'completed_by_user_id', 'completed_at',
    'cancelled_by_user_id', 'cancelled_at',
    'note',
])]
class TableRequest extends Model
{
    public const TYPE_CALL_WAITER = 'call_waiter';

    public const TYPE_REQUEST_BILL = 'request_bill';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    public const TYPES = [self::TYPE_CALL_WAITER, self::TYPE_REQUEST_BILL];

    /**
     * @var array<int, string>
     */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACKNOWLEDGED, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /**
     * Statuses that still count as "open" for the one-open-request-per-type
     * guard (see the partial unique index on this table).
     *
     * @return array<int, string>
     */
    public static function openStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_ACKNOWLEDGED];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /**
     * @return BelongsTo<TableSession, $this>
     */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * Guards the domain invariant that a request's restaurant/table/session
     * are always mutually coherent — same check already used by
     * Order/TableSession/CategoryProduct/RestaurantProduct.
     */
    protected static function booted(): void
    {
        static::saving(function (self $tableRequest) {
            $tableRestaurantId = Table::query()->whereKey($tableRequest->table_id)->value('restaurant_id');

            if ($tableRestaurantId !== $tableRequest->restaurant_id) {
                throw new InvalidArgumentException('The table does not belong to the given restaurant.');
            }

            $session = TableSession::query()->whereKey($tableRequest->table_session_id)->first();

            if (! $session || $session->table_id !== $tableRequest->table_id || $session->restaurant_id !== $tableRequest->restaurant_id) {
                throw new InvalidArgumentException('The table session does not belong to the given table/restaurant.');
            }
        });
    }
}
