<?php

namespace App\Actions\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppDeliveryException;
use App\Models\AuditLog;
use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseDelivery;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\WhatsApp\DayCloseDeliveryService;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manual WhatsApp resend of a Cierre Diario (CARTA 9.1E). The POST itself
 * is the confirmation (the confirm dialog is frontend's job). Always a NEW
 * `manual_resend` delivery for the CURRENT active recipient (snapshotted),
 * never a reuse of an old one; the job runs after commit.
 *
 * Guards (domain errors, in this order): global Meta config, restaurant
 * switch, active recipient, valid consent, no delivery of this close still
 * pending (409 DELIVERY_ALREADY_PENDING — two in-flight sends to the same
 * person would just be duplicates).
 *
 * Idempotent by (close, Idempotency-Key): the same key by the same user
 * returns the same delivery; the same key by another user is 409.
 */
final class ResendDayCloseWhatsAppAction
{
    public function __construct(
        private readonly WhatsAppConfig $config,
        private readonly DayCloseDeliveryService $deliveries,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{delivery: RestaurantDayCloseDelivery, replayed: bool}
     */
    public function execute(RestaurantDayClose $dayClose, User $actor, string $idempotencyKey): array
    {
        $payloadHash = hash('sha256', 'manual_resend|'.$actor->id);

        try {
            return DB::transaction(function () use ($dayClose, $actor, $idempotencyKey, $payloadHash) {
                // Serializes resends of one close (and the pending check).
                RestaurantDayClose::query()->whereKey($dayClose->id)->lockForUpdate()->first();

                $existing = $this->findByKey($dayClose, $idempotencyKey);
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $payloadHash);
                }

                if (! $this->config->readyToSend()) {
                    throw WhatsAppDeliveryException::notAvailable();
                }

                if (! $dayClose->restaurant->settings->daily_close_whatsapp_enabled) {
                    throw WhatsAppDeliveryException::disabled();
                }

                $recipient = DayCloseDeliveryService::activeRecipient($dayClose->restaurant_id);

                if ($recipient === null) {
                    throw WhatsAppDeliveryException::recipientRequired();
                }

                if (! $recipient->hasValidConsent()) {
                    throw WhatsAppDeliveryException::consentRequired();
                }

                if (RestaurantDayCloseDelivery::query()->where('restaurant_day_close_id', $dayClose->id)->where('status', RestaurantDayCloseDelivery::STATUS_PENDING)->exists()) {
                    throw WhatsAppDeliveryException::alreadyPending();
                }

                $delivery = $this->deliveries->createPending($dayClose, RestaurantDayCloseDelivery::KIND_MANUAL_RESEND, $recipient, $actor, $idempotencyKey, $payloadHash);

                $this->auditLogger->log(
                    organizationId: $dayClose->organization_id,
                    restaurantId: $dayClose->restaurant_id,
                    actorType: AuditLog::ACTOR_USER,
                    actor: $actor,
                    event: AuditLog::EVENT_DAY_CLOSE_WHATSAPP_RESEND_REQUESTED,
                    resourceType: AuditLog::RESOURCE_DAY_CLOSE,
                    resourceId: $dayClose->id,
                    metadata: [
                        'delivery_id' => $delivery->id,
                        'business_date' => $dayClose->business_date->format('Y-m-d'),
                        'recipient_phone_masked' => $delivery->recipient_phone_masked,
                    ],
                );

                return ['delivery' => $delivery, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByKey($dayClose, $idempotencyKey);

            if ($existing === null) {
                throw $e;
            }

            return $this->replayOrConflict($existing, $payloadHash);
        }
    }

    private function findByKey(RestaurantDayClose $dayClose, string $key): ?RestaurantDayCloseDelivery
    {
        return RestaurantDayCloseDelivery::query()->where('restaurant_day_close_id', $dayClose->id)->where('idempotency_key', $key)->first();
    }

    /**
     * @return array{delivery: RestaurantDayCloseDelivery, replayed: bool}
     */
    private function replayOrConflict(RestaurantDayCloseDelivery $existing, string $payloadHash): array
    {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw WhatsAppDeliveryException::idempotencyKeyReused();
        }

        return ['delivery' => $existing, 'replayed' => true];
    }
}
