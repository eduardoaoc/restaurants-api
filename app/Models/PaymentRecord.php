<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A manual, operational record of a payment against a table session's bill
 * — cash/card/other collected by staff. Deliberately not named "Payment":
 * this is not a gateway transaction, has no auth/capture/refund lifecycle,
 * and must not be confused with one if a real payment gateway is
 * integrated in a future block.
 */
#[Fillable([
    'restaurant_id', 'table_id', 'table_session_id',
    'method', 'amount', 'currency', 'reference', 'note',
    'idempotency_key', 'payload_hash',
    'recorded_by_user_id', 'recorded_at',
])]
class PaymentRecord extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_OTHER = 'other';

    /**
     * @var array<int, string>
     */
    public const METHODS = [self::METHOD_CASH, self::METHOD_CARD, self::METHOD_OTHER];

    public const CURRENCY_EUR = 'EUR';

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
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Guards the domain invariant that a payment's restaurant/table/session
     * are always mutually coherent — same check already used by
     * Order/TableRequest/TableSession.
     */
    protected static function booted(): void
    {
        static::saving(function (self $payment) {
            $tableRestaurantId = Table::query()->whereKey($payment->table_id)->value('restaurant_id');

            if ($tableRestaurantId !== $payment->restaurant_id) {
                throw new InvalidArgumentException('The table does not belong to the given restaurant.');
            }

            $session = TableSession::query()->whereKey($payment->table_session_id)->first();

            if (! $session || $session->table_id !== $payment->table_id || $session->restaurant_id !== $payment->restaurant_id) {
                throw new InvalidArgumentException('The table session does not belong to the given table/restaurant.');
            }
        });
    }
}
