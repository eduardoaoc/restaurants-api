<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One operational day of a restaurant: opened explicitly with a declared
 * opening float (OpenDailyOperationAction) and finished by the existing
 * Cierre Diario (CloseRestaurantDayAction links day_close_id). It stays the
 * same operation across midnight until it is closed — never opened or
 * closed by the clock.
 */
#[Fillable([
    'public_id', 'organization_id', 'restaurant_id',
    'business_date', 'timezone', 'currency', 'status',
    'opened_by_user_id', 'opened_by_name_snapshot', 'opened_at',
    'opening_float', 'suggested_opening_float', 'suggested_opening_float_source',
    'closed_by_user_id', 'closed_by_name_snapshot', 'closed_at', 'day_close_id',
    'idempotency_key', 'payload_hash',
])]
class RestaurantDailyOperation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'suggested_opening_float' => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<RestaurantDayClose, $this>
     */
    public function dayClose(): BelongsTo
    {
        return $this->belongsTo(RestaurantDayClose::class);
    }

    /**
     * @return HasMany<TableSession, $this>
     */
    public function tableSessions(): HasMany
    {
        return $this->hasMany(TableSession::class, 'daily_operation_id');
    }
}
