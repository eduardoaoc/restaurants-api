<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'restaurant_id', 'table_id', 'opened_by_user_id', 'closed_by_user_id', 'guest_count',
    'status', 'opened_at', 'closed_at', 'payment_status', 'paid_at',
])]
class TableSession extends Model
{
    public const PAYMENT_STATUS_UNPAID = 'unpaid';

    public const PAYMENT_STATUS_PAID = 'paid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'paid_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status !== 'closed';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_PAID;
    }

    /**
     * The orders placed during this session. A session can accumulate many
     * orders; a new session (after this one closes) starts with none.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The call_waiter/request_bill requests placed during this session.
     *
     * @return HasMany<TableRequest, $this>
     */
    public function tableRequests(): HasMany
    {
        return $this->hasMany(TableRequest::class);
    }

    /**
     * The manual payment records collected against this session's bill.
     *
     * @return HasMany<PaymentRecord, $this>
     */
    public function paymentRecords(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $session) {
            $tableRestaurantId = Table::query()->whereKey($session->table_id)->value('restaurant_id');

            if ($tableRestaurantId !== $session->restaurant_id) {
                throw new InvalidArgumentException('The table does not belong to the given restaurant.');
            }
        });
    }
}
