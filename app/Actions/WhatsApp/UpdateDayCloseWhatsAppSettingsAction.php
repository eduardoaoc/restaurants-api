<?php

namespace App\Actions\WhatsApp;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantReportRecipient;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\WhatsApp\DayCloseDeliveryService;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configures the Cierre Diario WhatsApp delivery of one restaurant
 * (CARTA 9.1E): the on/off switch and its single active recipient.
 *
 * Rules:
 *   - a NEW phone number (first recipient, or a changed number) always
 *     needs consent_confirmed=true and becomes a NEW recipient row; the
 *     previous one is deactivated and its consent revoked — consent
 *     never migrates to another number;
 *   - same number: name/linked user are updated in place; consent can be
 *     recorded if it was missing;
 *   - recipient=null disables the current recipient (consent revoked);
 *   - enabled=true requires an active recipient with valid consent.
 *
 * Audit: whatsapp_recipient.created/updated/disabled,
 * whatsapp_consent.recorded/revoked, and restaurant.settings_updated for
 * daily_close_whatsapp_enabled. Phone numbers appear ONLY masked.
 */
final class UpdateDayCloseWhatsAppSettingsAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{enabled: bool, recipient: ?array{name: string, phone?: ?string, user_id?: ?int, consent_confirmed?: bool}}  $data
     */
    public function execute(Restaurant $restaurant, User $actor, array $data): ?RestaurantReportRecipient
    {
        return DB::transaction(function () use ($restaurant, $actor, $data) {
            $settings = $restaurant->settings()->lockForUpdate()->firstOrFail();
            $current = DayCloseDeliveryService::activeRecipient($restaurant->id);
            $input = $data['recipient'];

            if ($input === null) {
                if ($current !== null) {
                    $this->disable($restaurant, $actor, $current);
                }
                $recipient = null;
            } else {
                $recipient = $this->upsert($restaurant, $actor, $current, $input);
            }

            $enabled = (bool) $data['enabled'];

            if ($enabled && ($recipient === null || ! $recipient->isUsable())) {
                throw ValidationException::withMessages(['enabled' => 'WhatsApp delivery can only be enabled with an active recipient whose consent has been confirmed.']);
            }

            if ($settings->daily_close_whatsapp_enabled !== $enabled) {
                $old = $settings->daily_close_whatsapp_enabled;
                $settings->update(['daily_close_whatsapp_enabled' => $enabled]);
                $this->audit($restaurant, $actor, AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED, AuditLog::RESOURCE_RESTAURANT, $restaurant->id, changes: [
                    'daily_close_whatsapp_enabled' => ['old' => $old, 'new' => $enabled],
                ]);
            }

            return $recipient;
        });
    }

    /**
     * @param  array{name: string, phone?: ?string, user_id?: ?int, consent_confirmed?: bool}  $input
     */
    private function upsert(Restaurant $restaurant, User $actor, ?RestaurantReportRecipient $current, array $input): RestaurantReportRecipient
    {
        $phone = $input['phone'] ?? null;
        $consent = (bool) ($input['consent_confirmed'] ?? false);
        $samePhone = $current !== null && ($phone === null || hash_equals($current->phone_hash, PhoneNumber::hash($phone)));

        if ($current === null && $phone === null) {
            throw ValidationException::withMessages(['recipient.phone' => 'A phone number is required for a new recipient.']);
        }

        if ($samePhone) {
            $changes = [];
            foreach (['name' => $input['name'], 'user_id' => $input['user_id'] ?? null] as $field => $value) {
                if ($current->{$field} !== $value) {
                    $changes[$field] = ['old' => $current->{$field}, 'new' => $value];
                }
            }

            $current->update(['name' => $input['name'], 'user_id' => $input['user_id'] ?? null]);

            if ($changes !== []) {
                $this->audit($restaurant, $actor, AuditLog::EVENT_WHATSAPP_RECIPIENT_UPDATED, AuditLog::RESOURCE_REPORT_RECIPIENT, $current->id, changes: $changes, metadata: ['phone_masked' => $current->phone_masked]);
            }

            if ($consent && ! $current->hasValidConsent()) {
                $this->recordConsent($restaurant, $actor, $current);
            }

            return $current->refresh();
        }

        // A new number: it needs its own explicit consent.
        if (! $consent) {
            throw ValidationException::withMessages(['recipient.consent_confirmed' => 'A new WhatsApp number requires confirming the recipient\'s consent.']);
        }

        if ($current !== null) {
            $this->disable($restaurant, $actor, $current);
        }

        $recipient = RestaurantReportRecipient::query()->create([
            'organization_id' => $restaurant->organization_id,
            'restaurant_id' => $restaurant->id,
            'user_id' => $input['user_id'] ?? null,
            'channel' => RestaurantReportRecipient::CHANNEL_WHATSAPP,
            'name' => $input['name'],
            'phone_e164' => $phone,
            'phone_hash' => PhoneNumber::hash($phone),
            'phone_masked' => PhoneNumber::mask($phone),
            'active' => true,
        ]);

        $this->audit($restaurant, $actor, AuditLog::EVENT_WHATSAPP_RECIPIENT_CREATED, AuditLog::RESOURCE_REPORT_RECIPIENT, $recipient->id, metadata: [
            'name' => $recipient->name,
            'phone_masked' => $recipient->phone_masked,
            'user_id' => $recipient->user_id,
        ]);
        $this->recordConsent($restaurant, $actor, $recipient);

        return $recipient->refresh();
    }

    private function recordConsent(Restaurant $restaurant, User $actor, RestaurantReportRecipient $recipient): void
    {
        $recipient->update([
            'consent_given_at' => now(),
            'consent_recorded_by_user_id' => $actor->id,
            'consent_method' => RestaurantReportRecipient::CONSENT_METHOD_DECLARED_BY_ADMIN,
            'consent_text_version' => RestaurantReportRecipient::CONSENT_TEXT_VERSION,
            'consent_revoked_at' => null,
        ]);

        $this->audit($restaurant, $actor, AuditLog::EVENT_WHATSAPP_CONSENT_RECORDED, AuditLog::RESOURCE_REPORT_RECIPIENT, $recipient->id, metadata: [
            'phone_masked' => $recipient->phone_masked,
            'consent_method' => RestaurantReportRecipient::CONSENT_METHOD_DECLARED_BY_ADMIN,
            'consent_text_version' => RestaurantReportRecipient::CONSENT_TEXT_VERSION,
        ]);
    }

    private function disable(Restaurant $restaurant, User $actor, RestaurantReportRecipient $recipient): void
    {
        $hadConsent = $recipient->hasValidConsent();
        $recipient->update(['active' => false, 'consent_revoked_at' => $hadConsent ? now() : $recipient->consent_revoked_at]);

        $this->audit($restaurant, $actor, AuditLog::EVENT_WHATSAPP_RECIPIENT_DISABLED, AuditLog::RESOURCE_REPORT_RECIPIENT, $recipient->id, metadata: ['phone_masked' => $recipient->phone_masked]);

        if ($hadConsent) {
            $this->audit($restaurant, $actor, AuditLog::EVENT_WHATSAPP_CONSENT_REVOKED, AuditLog::RESOURCE_REPORT_RECIPIENT, $recipient->id, metadata: ['phone_masked' => $recipient->phone_masked]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(Restaurant $restaurant, User $actor, string $event, string $resourceType, int $resourceId, ?array $changes = null, ?array $metadata = null): void
    {
        $this->auditLogger->log(
            organizationId: $restaurant->organization_id,
            restaurantId: $restaurant->id,
            actorType: AuditLog::ACTOR_USER,
            actor: $actor,
            event: $event,
            resourceType: $resourceType,
            resourceId: $resourceId,
            changes: $changes,
            metadata: $metadata,
        );
    }
}
