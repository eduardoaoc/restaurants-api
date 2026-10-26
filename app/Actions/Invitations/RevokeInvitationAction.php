<?php

namespace App\Actions\Invitations;

use App\Exceptions\Invitations\InvitationException;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Invitations\InvitationTokens;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a pending (or pending-but-expired) invitation. Takes the same row
 * lock acceptance takes, so a revoke and an accept racing each other always
 * resolve to exactly one of the two outcomes.
 */
class RevokeInvitationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Invitation $invitation, User $actor, string $actorType): Invitation
    {
        return DB::transaction(function () use ($invitation, $actor, $actorType) {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $locked->isPending()) {
                throw InvitationException::notPending();
            }

            $locked->update([
                'status' => Invitation::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'token_hash' => InvitationTokens::placeholderHash(),
            ]);

            $restaurantIds = $locked->restaurantIds();

            $this->auditLogger->log(
                organizationId: $locked->organization_id,
                restaurantId: count($restaurantIds) === 1 ? $restaurantIds[0] : null,
                actorType: $actorType,
                actor: $actor,
                event: AuditLog::EVENT_INVITATION_REVOKED,
                resourceType: AuditLog::RESOURCE_INVITATION,
                resourceId: $locked->id,
                metadata: ['type' => $locked->type],
            );

            return $locked;
        });
    }
}
