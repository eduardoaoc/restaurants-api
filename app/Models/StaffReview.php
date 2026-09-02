<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * An internal, administrative review of an operational staff member's
 * service — a subjective human judgment (rating + optional comment),
 * deliberately kept separate from the objective operational metrics
 * computed by StaffPerformanceService. Append-only: no PATCH/DELETE.
 */
#[Fillable(['organization_id', 'restaurant_id', 'staff_user_id', 'reviewer_user_id', 'rating', 'comment'])]
class StaffReview extends Model
{
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
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * Guards the domain invariant that a review's restaurant belongs to
     * the given organization — same principle already used by Order/
     * TableRequest/PaymentRecord/PrintRecord.
     */
    protected static function booted(): void
    {
        static::saving(function (self $review) {
            $restaurantOrganizationId = Restaurant::query()->whereKey($review->restaurant_id)->value('organization_id');

            if ($restaurantOrganizationId !== $review->organization_id) {
                throw new InvalidArgumentException('The restaurant does not belong to the given organization.');
            }
        });
    }
}
