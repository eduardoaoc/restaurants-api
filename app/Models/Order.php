<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'restaurant_id', 'table_id', 'table_session_id', 'origin',
    'created_by_user_id', 'approved_by_user_id', 'cancelled_by_user_id',
    'customer_name', 'status', 'subtotal', 'modifiers_total', 'total',
    'customer_note', 'approved_at', 'cancelled_at',
    'idempotency_key', 'idempotency_payload_hash',
    'accepted_by_user_id', 'accepted_at',
    'preparing_by_user_id', 'preparing_at',
    'ready_by_user_id', 'ready_at',
    'served_by_user_id', 'served_at',
])]
class Order extends Model
{
    public const ORIGIN_CUSTOMER_QR = 'customer_qr';

    public const ORIGIN_WAITER = 'waiter';

    public const ORIGIN_MANAGER = 'manager';

    public const ORIGIN_CASHIER = 'cashier';

    public const STATUS_WAITING_APPROVAL = 'waiting_approval';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_SERVED = 'served';

    /**
     * The statuses shown on the Kitchen Display System: a confirmed order
     * not yet handed to the customer. waiting_approval/cancelled/served are
     * deliberately excluded — see KitchenController.
     *
     * @return array<int, string>
     */
    public static function kitchenQueueStatuses(): array
    {
        return [self::STATUS_CONFIRMED, self::STATUS_ACCEPTED, self::STATUS_PREPARING, self::STATUS_READY];
    }

    /**
     * Statuses that count towards a table session's bill. waiting_approval
     * is excluded (not yet accepted by the restaurant — nothing to charge
     * for) and cancelled is excluded (never happened, financially).
     *
     * @return array<int, string>
     */
    public static function billableStatuses(): array
    {
        return [self::STATUS_CONFIRMED, self::STATUS_ACCEPTED, self::STATUS_PREPARING, self::STATUS_READY, self::STATUS_SERVED];
    }

    /**
     * Statuses that represent unfinished kitchen/approval work — a session
     * cannot close while any order is still in one of these, even if the
     * order itself isn't billable yet (waiting_approval).
     *
     * @return array<int, string>
     */
    public static function openStatuses(): array
    {
        return [self::STATUS_WAITING_APPROVAL, self::STATUS_CONFIRMED, self::STATUS_ACCEPTED, self::STATUS_PREPARING, self::STATUS_READY];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'modifiers_total' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
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
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparingBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparing_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function readyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by_user_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isActionableCustomerOrder(): bool
    {
        return $this->origin === self::ORIGIN_CUSTOMER_QR && $this->status === self::STATUS_WAITING_APPROVAL;
    }

    /**
     * Guards the domain invariant that an order's restaurant/table/session
     * are always mutually coherent, mirroring the checks already enforced
     * by TableSession/CategoryProduct/RestaurantProduct in earlier blocks.
     */
    protected static function booted(): void
    {
        static::saving(function (self $order) {
            $tableRestaurantId = Table::query()->whereKey($order->table_id)->value('restaurant_id');

            if ($tableRestaurantId !== $order->restaurant_id) {
                throw new InvalidArgumentException('The table does not belong to the given restaurant.');
            }

            $session = TableSession::query()->whereKey($order->table_session_id)->first();

            if (! $session || $session->table_id !== $order->table_id || $session->restaurant_id !== $order->restaurant_id) {
                throw new InvalidArgumentException('The table session does not belong to the given table/restaurant.');
            }
        });
    }
}
