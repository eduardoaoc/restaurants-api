<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single operational presence of a User at a Restaurant (Bloco 3) —
 * "was/is this staff member active here". NOT a timesheet/payroll record:
 * no breaks, no editable historical times, no legal-hours logic. Active is
 * derived, never a persisted `status` column — see isActive() and the
 * migration.
 */
#[Fillable([
    'restaurant_id', 'user_id', 'started_at', 'ended_at', 'started_by_user_id', 'ended_by_user_id',
])]
class StaffShift extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    /**
     * The slug of the operational role this user holds specifically at
     * this shift's Restaurant (roles are assigned per-restaurant — see
     * CreateStaffAction/UpdateStaffAction from Bloco 18). Used only for
     * StaffShiftResource display; eligibility to hold a shift at all is
     * StaffShiftEligibility's job, never this method's.
     *
     * Deliberately a plain query, not an Eloquent relation: the (user_id,
     * restaurant_id) pair can't be expressed as a standard hasOne
     * constraint that survives eager-loading across a batch of models with
     * different restaurant_ids (whereColumn requires an actual join, and a
     * closure-bound `where` is only evaluated once against an empty
     * template model — see the Bloco 3 report). Runs one query per shift;
     * acceptable for the small, single-restaurant, paginated lists this
     * feeds.
     */
    public function operationalRoleSlug(): ?string
    {
        return Role::query()
            ->join('user_roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $this->user_id)
            ->where('user_roles.restaurant_id', $this->restaurant_id)
            ->value('roles.slug');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
