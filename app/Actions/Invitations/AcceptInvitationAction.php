<?php

namespace App\Actions\Invitations;

use App\Actions\Staff\CreateStaffAction;
use App\Exceptions\Invitations\InvitationException;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use App\Support\Invitations\InvitationTokens;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The only place an invitation turns into access.
 *
 * Everything happens in one transaction that starts by locking the
 * invitation row: two simultaneous accepts (or an accept racing a revoke or
 * a resend) serialize on that lock, and the loser sees the final state.
 *
 * Identity rules:
 *   - The account is ALWAYS the one whose e-mail is the invitation's; the
 *     client never supplies an e-mail.
 *   - New account (MODE_NEW): only when no user has that e-mail. The person
 *     chooses name + password; the e-mail counts as verified (they proved
 *     the mailbox by opening the link).
 *   - Existing account (MODE_EXISTING): the password of that account is
 *     required; name, password and every other membership stay untouched.
 *   - A session of a different user is refused, never silently replaced.
 *
 * Grants created: owner → organization_users + org-wide owner user_role
 * (restaurant_id null, exactly how owners are modelled — see
 * RestaurantScope); staff → CreateStaffAction::attach(), the same rows the
 * Staff API creates.
 */
class AcceptInvitationAction
{
    public const MODE_NEW = 'new';

    public const MODE_EXISTING = 'existing';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CreateStaffAction $createStaffAction,
    ) {}

    /**
     * @param  array{name?: string|null, password: string}  $input
     * @return array{user: User, invitation: Invitation}
     */
    public function execute(string $token, string $mode, array $input, ?User $sessionUser): array
    {
        $found = InvitationTokens::find($token) ?? throw InvitationException::notFound();

        try {
            return DB::transaction(fn () => $this->accept($found->id, $token, $mode, $input, $sessionUser));
        } catch (UniqueConstraintViolationException) {
            // Two different invitations for the same brand-new e-mail
            // accepted at the same instant: users.email lets only one
            // account exist — the other person must sign in with it. Any
            // other unique race here is a membership/code collision.
            $accountNowExists = User::query()->whereRaw('lower(email) = ?', [$found->email])->exists();

            throw $mode === self::MODE_NEW && $accountNowExists
                ? InvitationException::accountExists()
                : InvitationException::assignmentConflict();
        }
    }

    /**
     * @param  array{name?: string|null, password: string}  $input
     * @return array{user: User, invitation: Invitation}
     */
    private function accept(int $invitationId, string $token, string $mode, array $input, ?User $sessionUser): array
    {
        $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitationId);

        if (! hash_equals($invitation->token_hash, InvitationTokens::hash($token))) {
            // Accepted, revoked or re-minted (resend) while this request
            // waited for the lock.
            throw match ($invitation->status) {
                Invitation::STATUS_ACCEPTED => InvitationException::alreadyAccepted(),
                Invitation::STATUS_REVOKED => InvitationException::revoked(),
                default => InvitationException::notFound(),
            };
        }

        $this->assertUsable($invitation);

        $organization = Organization::query()->findOrFail($invitation->organization_id);

        if ($organization->status === Organization::STATUS_SUSPENDED) {
            throw InvitationException::organizationUnavailable();
        }

        $existing = User::query()->whereRaw('lower(email) = ?', [$invitation->email])->first();

        if ($sessionUser !== null && ($existing === null || $sessionUser->id !== $existing->id)) {
            throw InvitationException::accountMismatch();
        }

        $user = $mode === self::MODE_NEW
            ? $this->createAccount($invitation, $existing, $input)
            : $this->verifyAccount($existing, $input['password']);

        if ($user->isPlatformAdmin()) {
            throw InvitationException::notAllowedForAccount();
        }

        if (OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->exists()) {
            throw InvitationException::membershipConflict();
        }

        $role = Role::query()->findOrFail($invitation->role_id);
        $restaurantIds = $invitation->type === Invitation::TYPE_OWNER
            ? $this->grantOwner($organization, $user, $role)
            : $this->grantStaff($organization, $user, $role, $invitation->restaurant_assignments ?? []);

        $invitation->update([
            'status' => Invitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
            'token_hash' => InvitationTokens::placeholderHash(),
        ]);

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: count($restaurantIds) === 1 ? $restaurantIds[0] : null,
            actorType: AuditLog::ACTOR_USER,
            actor: $user,
            event: AuditLog::EVENT_INVITATION_ACCEPTED,
            resourceType: AuditLog::RESOURCE_INVITATION,
            resourceId: $invitation->id,
            // actor = the person accepting (their own users.id); the staff
            // user_id is also recorded explicitly so "who acted" and "who was
            // affected" never get confused.
            metadata: ['type' => $invitation->type, 'role' => $role->slug, 'restaurant_ids' => $restaurantIds, 'account' => $mode, 'user_id' => $user->id],
        );

        return ['user' => $user, 'invitation' => $invitation];
    }

    public function assertUsable(Invitation $invitation): void
    {
        if ($invitation->status === Invitation::STATUS_ACCEPTED) {
            throw InvitationException::alreadyAccepted();
        }

        if ($invitation->status === Invitation::STATUS_REVOKED) {
            throw InvitationException::revoked();
        }

        if ($invitation->isExpired()) {
            throw InvitationException::expired();
        }
    }

    /**
     * @param  array{name?: string|null, password: string}  $input
     */
    private function createAccount(Invitation $invitation, ?User $existing, array $input): User
    {
        if ($existing !== null) {
            throw InvitationException::accountExists();
        }

        $user = User::query()->create([
            'name' => $input['name'] ?? $invitation->name ?? $invitation->email,
            'email' => $invitation->email,
            'password' => $input['password'],
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function verifyAccount(?User $existing, string $password): User
    {
        if ($existing === null) {
            throw InvitationException::accountRequired();
        }

        if (! Hash::check($password, $existing->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        if ($existing->isSuspended()) {
            throw InvitationException::accountSuspended();
        }

        return $existing;
    }

    /**
     * @return array<int, int>
     */
    private function grantOwner(Organization $organization, User $user, Role $role): array
    {
        $organization->users()->attach($user->id);

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => null,
        ]);

        return [];
    }

    /**
     * Re-checks what may have changed since the invitation was sent: every
     * restaurant must still belong to the organization. The employee code
     * (sub_id) is generated by CreateStaffAction::attach() — a legacy code
     * carried by an old pending invitation is kept only if still free.
     *
     * @param  array<int, array{restaurant_id: int, sub_id?: string|null}>  $assignments
     * @return array<int, int>
     */
    private function grantStaff(Organization $organization, User $user, Role $role, array $assignments): array
    {
        foreach ($assignments as $assignment) {
            $belongs = $organization->restaurants()->whereKey($assignment['restaurant_id'])->exists();

            if (! $belongs) {
                throw InvitationException::assignmentConflict();
            }
        }

        return $this->createStaffAction->attach($organization, $user, $role->slug, $assignments);
    }
}
