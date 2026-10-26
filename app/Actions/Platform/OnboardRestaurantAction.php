<?php

namespace App\Actions\Platform;

use App\Actions\Invitations\CreateInvitationAction;
use App\Exceptions\Invitations\InvitationException;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\PlatformAuditLogger;
use App\Support\Invitations\InvitationMailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual onboarding by a platform admin (POST /platform/restaurants).
 *
 * Two shapes, both one transaction:
 *   - new organization: Organization (plan/subscription stay at the schema
 *     defaults — free/active, no billing) + Restaurant + default settings +
 *     a pending OWNER invitation for the given e-mail;
 *   - existing organization: Restaurant + default settings only — every
 *     org-wide owner already reaches it (RestaurantScope), so no invitation
 *     and no grant is created.
 *
 * Never creates products, tables, staff, an operational day, a
 * subscription, or a membership for the acting admin. The e-mail is queued
 * only after commit (InvitationMailer); a delivery failure leaves the
 * onboarding complete and recoverable by resend.
 *
 * A retried request with the same Idempotency-Key and payload replays the
 * first result (restaurants.onboarding_idempotency_key, unique); the same
 * key with a different payload is IDEMPOTENCY_KEY_REUSED.
 */
class OnboardRestaurantAction
{
    public function __construct(
        private readonly CreateInvitationAction $createInvitation,
        private readonly PlatformAuditLogger $auditLogger,
        private readonly InvitationMailer $mailer,
    ) {}

    /**
     * @param  array{organization_id?: int|null, organization?: array{name: string, slug: string}|null, restaurant: array{name: string, slug: string, timezone?: string|null, default_locale?: string|null}, owner?: array{email: string, name?: string|null}|null}  $data
     * @return array{organization: Organization, restaurant: Restaurant, invitation: Invitation|null, organization_created: bool, replayed: bool}
     */
    public function execute(User $admin, array $data, string $idempotencyKey): array
    {
        $payloadHash = hash('sha256', json_encode($data));

        try {
            $result = DB::transaction(fn () => $this->onboard($admin, $data, $idempotencyKey, $payloadHash));
        } catch (UniqueConstraintViolationException $e) {
            // Same key raced in from a double click: the winner committed.
            $existing = Restaurant::query()->where('onboarding_idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $this->replay($existing, $payloadHash, $data);
            }

            // Otherwise a slug was taken between validation and insert.
            throw ValidationException::withMessages(['restaurant.slug' => 'The slug has already been taken.']);
        }

        if (! $result['replayed'] && $result['invitation'] !== null) {
            $this->mailer->queue($result['invitation']);
            $result['invitation']->refresh();
        }

        return $result;
    }

    /**
     * @return array{organization: Organization, restaurant: Restaurant, invitation: Invitation|null, organization_created: bool, replayed: bool}
     */
    private function onboard(User $admin, array $data, string $idempotencyKey, string $payloadHash): array
    {
        $existing = Restaurant::query()->where('onboarding_idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $payloadHash, $data);
        }

        $organizationCreated = empty($data['organization_id']);

        if ($organizationCreated) {
            $organization = Organization::query()->create([
                'name' => $data['organization']['name'],
                'slug' => $data['organization']['slug'],
                'status' => Organization::STATUS_ACTIVE,
            ]);
        } else {
            $organization = Organization::query()->lockForUpdate()->findOrFail($data['organization_id']);

            if ($organization->status === Organization::STATUS_SUSPENDED) {
                throw InvitationException::organizationSuspended();
            }
        }

        $restaurant = $organization->restaurants()->create([
            'name' => $data['restaurant']['name'],
            'slug' => $data['restaurant']['slug'],
            'status' => Restaurant::STATUS_ACTIVE,
        ]);
        $restaurant->forceFill([
            'onboarding_idempotency_key' => $idempotencyKey,
            'onboarding_payload_hash' => $payloadHash,
        ])->save();

        $settings = RestaurantSettings::createDefaultsFor($restaurant);
        $overrides = array_filter([
            'timezone' => $data['restaurant']['timezone'] ?? null,
            'default_locale' => $data['restaurant']['default_locale'] ?? null,
        ]);

        if ($overrides !== []) {
            $settings->update($overrides);
        }

        $invitation = null;

        if ($organizationCreated) {
            $invitation = $this->createInvitation->execute(
                organization: $organization,
                type: Invitation::TYPE_OWNER,
                email: $data['owner']['email'],
                name: $data['owner']['name'] ?? null,
                role: Role::query()->where('slug', 'owner')->firstOrFail(),
                assignments: null,
                locale: $settings->default_locale,
                inviter: $admin,
                invitedByType: Invitation::INVITED_BY_PLATFORM_ADMIN,
            );
        }

        $this->auditLogger->log(
            actor: $admin,
            event: AuditLog::EVENT_PLATFORM_RESTAURANT_ONBOARDED,
            resourceType: AuditLog::RESOURCE_RESTAURANT,
            resourceId: $restaurant->id,
            organizationId: $organization->id,
            restaurantId: $restaurant->id,
            reason: 'onboarding',
            metadata: [
                'organization_created' => $organizationCreated,
                'owner_invitation_id' => $invitation?->id,
            ],
        );

        return [
            'organization' => $organization,
            'restaurant' => $restaurant,
            'invitation' => $invitation,
            'organization_created' => $organizationCreated,
            'replayed' => false,
        ];
    }

    /**
     * @return array{organization: Organization, restaurant: Restaurant, invitation: Invitation|null, organization_created: bool, replayed: bool}
     */
    private function replay(Restaurant $restaurant, string $payloadHash, array $data): array
    {
        if (! hash_equals((string) $restaurant->onboarding_payload_hash, $payloadHash)) {
            throw InvitationException::idempotencyKeyReused();
        }

        $organization = Organization::query()->findOrFail($restaurant->organization_id);
        // Same payload as the first request (hash checked above), so it
        // tells which shape that request had.
        $organizationCreated = empty($data['organization_id']);
        $invitation = $organizationCreated
            ? Invitation::query()->where('organization_id', $organization->id)->where('type', Invitation::TYPE_OWNER)->latest('id')->first()
            : null;

        return [
            'organization' => $organization,
            'restaurant' => $restaurant,
            'invitation' => $invitation,
            'organization_created' => $organizationCreated,
            'replayed' => true,
        ];
    }
}
