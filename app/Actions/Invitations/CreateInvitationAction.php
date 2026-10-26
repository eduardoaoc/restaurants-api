<?php

namespace App\Actions\Invitations;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Invitations\InvitationTokens;

/**
 * Persists a pending invitation (first send already counted as queued).
 * Runs inside the caller's transaction; the caller queues the e-mail with
 * InvitationMailer only after commit. Nothing is granted here.
 *
 * The (organization_id, email) partial unique index rejects a second
 * pending invitation for the same person — callers translate that
 * violation into INVITATION_ALREADY_PENDING.
 */
class CreateInvitationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, array{restaurant_id: int, sub_id: string}>|null  $assignments
     */
    public function execute(
        Organization $organization,
        string $type,
        string $email,
        ?string $name,
        Role $role,
        ?array $assignments,
        string $locale,
        User $inviter,
        string $invitedByType,
    ): Invitation {
        $token = InvitationTokens::generate();

        $invitation = Invitation::query()->create([
            'organization_id' => $organization->id,
            'type' => $type,
            'email' => $email,
            'name' => $name,
            'role_id' => $role->id,
            'restaurant_assignments' => $assignments,
            'locale' => $locale,
            'token_hash' => InvitationTokens::hash($token),
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addHours((int) config('invitations.expires_hours')),
            'delivery_status' => Invitation::DELIVERY_QUEUED,
            'delivery_sequence' => 1,
            'send_count' => 1,
            'last_queued_at' => now(),
            'invited_by_type' => $invitedByType,
            'invited_by_user_id' => $inviter->id,
        ]);

        $restaurantIds = $invitation->restaurantIds();

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: count($restaurantIds) === 1 ? $restaurantIds[0] : null,
            actorType: $invitedByType === Invitation::INVITED_BY_PLATFORM_ADMIN ? AuditLog::ACTOR_PLATFORM_ADMIN : AuditLog::ACTOR_USER,
            actor: $inviter,
            event: AuditLog::EVENT_INVITATION_CREATED,
            resourceType: AuditLog::RESOURCE_INVITATION,
            resourceId: $invitation->id,
            metadata: ['type' => $type, 'role' => $role->slug, 'restaurant_ids' => $restaurantIds],
        );

        return $invitation->withIssuedToken($token);
    }
}
