<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual cash drawer movement (CARTA 9.1A): a pay-in ("entrada") or a
 * pay-out ("retirada") of cash that is not a customer payment. Append-only
 * — never updated nor deleted; a mistake is corrected with an opposite
 * movement. Belongs to the Cierre Diario whose period contains
 * recorded_at.
 */
#[Fillable([
    'restaurant_id', 'type', 'amount', 'reason',
    'recorded_by_user_id', 'recorded_by_name_snapshot', 'recorded_at',
    'idempotency_key', 'payload_hash',
])]
class RestaurantCashMovement extends Model
{
    public const TYPE_PAY_IN = 'pay_in';

    public const TYPE_PAY_OUT = 'pay_out';

    /**
     * @var array<int, string>
     */
    public const TYPES = [self::TYPE_PAY_IN, self::TYPE_PAY_OUT];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
