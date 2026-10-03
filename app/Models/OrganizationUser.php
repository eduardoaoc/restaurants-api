<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's operational membership in ONE organization — the tenant's OWN
 * authority to grant/revoke access to itself, entirely separate from
 * User::status (a GLOBAL, platform-only suspension flag; see
 * EnsureUserIsActive and PlatformUserController).
 *
 * status here (active/inactive) is what an owner/manager controls via
 * PATCH /api/v1/staff/{user} (Passo 2.8B-FIX). It is strictly weaker than
 * platform suspension: it can only ever gate access to THIS organization,
 * never the user's account globally, and it can never be used to revert a
 * platform-level suspension (users.status is never written by the Staff
 * API — see StaffController/UpdateStaffAction). A user inactive here keeps
 * their history (orders, shifts, reviews, performance, roles, restaurant
 * assignments) untouched and may still be fully active in another
 * organization.
 */
#[Fillable(['organization_id', 'user_id', 'status'])]
class OrganizationUser extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
