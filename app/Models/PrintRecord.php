<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Records that a print document was requested/generated — kitchen ticket
 * or bill receipt. This is an audit trail, not a printer confirmation: it
 * means "this document was generated for printing", never that a physical
 * printer actually produced it (there is no device feedback in this MVP).
 */
#[Fillable([
    'organization_id', 'restaurant_id', 'document_type',
    'order_id', 'table_session_id', 'requested_by_user_id', 'generated_at',
])]
class PrintRecord extends Model
{
    public const DOCUMENT_TYPE_KITCHEN_TICKET = 'kitchen_ticket';

    public const DOCUMENT_TYPE_BILL_RECEIPT = 'bill_receipt';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Guards the domain invariant that a print record's organization/
     * restaurant/order/session references are always mutually coherent —
     * same principle already used by Order/TableRequest/PaymentRecord.
     */
    protected static function booted(): void
    {
        static::saving(function (self $printRecord) {
            $restaurantOrganizationId = Restaurant::query()->whereKey($printRecord->restaurant_id)->value('organization_id');

            if ($restaurantOrganizationId !== $printRecord->organization_id) {
                throw new InvalidArgumentException('The restaurant does not belong to the given organization.');
            }

            if ($printRecord->order_id !== null) {
                $orderRestaurantId = Order::query()->whereKey($printRecord->order_id)->value('restaurant_id');

                if ($orderRestaurantId !== $printRecord->restaurant_id) {
                    throw new InvalidArgumentException('The order does not belong to the given restaurant.');
                }
            }

            if ($printRecord->table_session_id !== null) {
                $sessionRestaurantId = TableSession::query()->whereKey($printRecord->table_session_id)->value('restaurant_id');

                if ($sessionRestaurantId !== $printRecord->restaurant_id) {
                    throw new InvalidArgumentException('The table session does not belong to the given restaurant.');
                }
            }
        });
    }
}
