<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One definitive Cierre Diario of a restaurant (CARTA 9.1A): the immutable
 * snapshot of [period_started_at, period_ended_at). Created only by
 * CloseRestaurantDayAction and never updated — the model refuses it, so a
 * future code path can't silently rewrite a past close. Post-close
 * information goes into annotations().
 *
 * Money columns are decimal strings ("1842.50"); `report` is the full
 * versioned snapshot (see DayCloseReportSchema) hashed in report_sha256.
 */
#[Fillable([
    'public_id', 'organization_id', 'restaurant_id',
    'business_date', 'business_date_from', 'period_started_at', 'period_ended_at', 'timezone', 'currency',
    'closed_by_user_id', 'closed_by_name_snapshot', 'closed_at',
    'total_received', 'cash_received', 'card_received', 'other_received',
    'payments_count', 'sessions_with_payments', 'average_ticket',
    'opening_float', 'cash_pay_ins', 'cash_pay_outs', 'expected_cash', 'counted_cash', 'cash_difference',
    'cash_left_for_next_day', 'cash_difference_note',
    'orders_registered', 'orders_valid', 'orders_served', 'orders_rejected',
    'sessions_opened', 'sessions_closed', 'guests',
    'feedback_count', 'feedback_avg_overall', 'critical_feedback_count', 'low_dimension_feedback_count',
    'delays_count', 'unavailable_products_count',
    'notes', 'has_incidents',
    'report', 'report_schema_version', 'report_sha256',
    'idempotency_key', 'payload_hash',
])]
class RestaurantDayClose extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'business_date_from' => 'date:Y-m-d',
            'period_started_at' => 'datetime',
            'period_ended_at' => 'datetime',
            'closed_at' => 'datetime',
            'total_received' => 'decimal:2',
            'cash_received' => 'decimal:2',
            'card_received' => 'decimal:2',
            'other_received' => 'decimal:2',
            'average_ticket' => 'decimal:2',
            'opening_float' => 'decimal:2',
            'cash_pay_ins' => 'decimal:2',
            'cash_pay_outs' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'cash_left_for_next_day' => 'decimal:2',
            'feedback_avg_overall' => 'decimal:2',
            'has_incidents' => 'boolean',
            'report' => 'array',
            'payments_count' => 'integer',
            'sessions_with_payments' => 'integer',
            'orders_registered' => 'integer',
            'orders_valid' => 'integer',
            'orders_served' => 'integer',
            'orders_rejected' => 'integer',
            'sessions_opened' => 'integer',
            'sessions_closed' => 'integer',
            'guests' => 'integer',
            'feedback_count' => 'integer',
            'critical_feedback_count' => 'integer',
            'low_dimension_feedback_count' => 'integer',
            'delays_count' => 'integer',
            'unavailable_products_count' => 'integer',
            'report_schema_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A RestaurantDayClose is immutable — add an annotation instead.');
        });

        static::deleting(function () {
            throw new LogicException('A RestaurantDayClose is never deleted.');
        });
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return HasMany<RestaurantDayCloseDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(RestaurantDayCloseDelivery::class)->orderBy('id');
    }

    /**
     * The most recent WhatsApp delivery (CARTA 9.1E) — eager-loadable for
     * lists without N+1.
     *
     * @return HasOne<RestaurantDayCloseDelivery, $this>
     */
    public function latestDelivery(): HasOne
    {
        return $this->hasOne(RestaurantDayCloseDelivery::class)->latestOfMany();
    }

    /**
     * @return HasMany<RestaurantDayCloseAnnotation, $this>
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(RestaurantDayCloseAnnotation::class)->orderBy('id');
    }
}
