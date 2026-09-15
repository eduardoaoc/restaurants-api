<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A customer's post-visit feedback for one TableSession — deliberately its
 * own domain, separate from StaffReview (an internal manager -> staff
 * review; see database/migrations/2026_09_09_000001_create_staff_reviews_table.php).
 * Different visibility (owner/manager detail, waiter aggregate-only, see
 * CustomerFeedbackPolicy), different authorship (an anonymous customer,
 * not a staff reviewer), different purpose (post-visit satisfaction, not
 * staff performance management). Never merge the two.
 *
 * waiter_id is a snapshot of TableSession::assigned_waiter_user_id taken
 * at submission time (see SubmitPublicFeedbackAction) — a plain column,
 * never recomputed, so a later waiter reassignment on the session can
 * never rewrite an already-submitted feedback's attribution.
 */
#[Fillable([
    'organization_id', 'restaurant_id', 'table_session_id', 'waiter_id',
    'first_name', 'last_name',
    'wait_time_rating', 'food_rating', 'service_rating', 'overall_rating',
    'experience_comment', 'improvement_comment', 'contact',
    'submitted_at',
])]
class CustomerFeedback extends Model
{
    /**
     * "feedback" is uncountable to Laravel's pluralizer, so the default
     * inferred table name would be the singular "customer_feedback" — the
     * migration explicitly creates "customer_feedbacks" instead (plural,
     * matching this project's convention for every other table), so this
     * must be set explicitly rather than relying on inference.
     */
    protected $table = 'customer_feedbacks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wait_time_rating' => 'integer',
            'food_rating' => 'integer',
            'service_rating' => 'integer',
            'overall_rating' => 'integer',
            'submitted_at' => 'datetime',
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
     * @return BelongsTo<TableSession, $this>
     */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /**
     * The waiter this feedback was attributed to at submission time, if
     * any. See the class docblock — this is a historical snapshot, not a
     * live pointer to the session's current assignment.
     *
     * @return BelongsTo<User, $this>
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /**
     * Guards the domain invariant that organization/restaurant/session are
     * always mutually coherent — same check already used by
     * Order/TableSession/TableRequest.
     */
    protected static function booted(): void
    {
        static::saving(function (self $feedback) {
            $session = TableSession::query()->whereKey($feedback->table_session_id)->first();

            if (! $session || $session->restaurant_id !== $feedback->restaurant_id) {
                throw new InvalidArgumentException('The table session does not belong to the given restaurant.');
            }

            if ($session->restaurant->organization_id !== $feedback->organization_id) {
                throw new InvalidArgumentException('The restaurant does not belong to the given organization.');
            }
        });
    }
}
