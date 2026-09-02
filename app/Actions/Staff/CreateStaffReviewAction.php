<?php

namespace App\Actions\Staff;

use App\Exceptions\Staff\CannotReviewSelfException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\StaffReview;
use App\Models\User;
use App\Support\Audit\AuditLogger;

/**
 * Creates a StaffReview. organization_id/restaurant_id are always derived
 * server-side from the target staff member's own restaurant — never from
 * client input — so a malicious/mistaken restaurant_id in the request body
 * can have no effect (the request payload only ever carries rating/comment).
 */
class CreateStaffReviewAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Organization $organization, Restaurant $staffRestaurant, User $staff, User $reviewer, int $rating, ?string $comment): StaffReview
    {
        if ($reviewer->id === $staff->id) {
            throw new CannotReviewSelfException;
        }

        $review = StaffReview::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $staffRestaurant->id,
            'staff_user_id' => $staff->id,
            'reviewer_user_id' => $reviewer->id,
            'rating' => $rating,
            'comment' => $comment,
        ]);

        // Deliberately excludes `comment` — it may contain sensitive
        // freeform text and already lives on staff_reviews itself.
        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $staffRestaurant->id,
            actorType: AuditLog::ACTOR_USER,
            actor: $reviewer,
            event: AuditLog::EVENT_STAFF_REVIEW_CREATED,
            resourceType: AuditLog::RESOURCE_STAFF_REVIEW,
            resourceId: $review->id,
            metadata: ['staff_user_id' => $staff->id, 'rating' => $rating],
        );

        return $review;
    }
}
