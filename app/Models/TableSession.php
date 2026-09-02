<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['restaurant_id', 'table_id', 'opened_by_user_id', 'closed_by_user_id', 'guest_count', 'status', 'opened_at', 'closed_at'])]
class TableSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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
