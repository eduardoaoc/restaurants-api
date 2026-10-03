<?php

namespace App\Support\WhatsApp;

use App\Jobs\SendDayCloseWhatsApp;
use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseDelivery;
use App\Models\RestaurantReportRecipient;
use App\Models\User;

/**
 * Creates Cierre Diario WhatsApp deliveries (CARTA 9.1E). Pure database
 * work, meant to run INSIDE the caller's transaction (the close itself,
 * or a manual resend); the job is dispatched afterCommit(), so a rolled
 * back close never sends anything and no HTTP call ever happens inside a
 * transaction.
 *
 * Automatic delivery (one per close at most — DB partial unique):
 *   - restaurant setting disabled  -> nothing at all (no row): status
 *     "disabled" for the UI;
 *   - enabled but global Meta config incomplete, or no active recipient,
 *     or no valid consent -> a `skipped` row with a failure_code, so the
 *     problem is visible in the close's delivery history — the close
 *     itself never fails;
 *   - otherwise -> `pending` row + job.
 */
final class DayCloseDeliveryService
{
    public function __construct(private readonly WhatsAppConfig $config) {}

    public function planAutomatic(RestaurantDayClose $dayClose): ?RestaurantDayCloseDelivery
    {
        $restaurant = $dayClose->restaurant;

        if (! $restaurant->settings->daily_close_whatsapp_enabled) {
            return null;
        }

        $recipient = self::activeRecipient($restaurant->id);
        $skipCode = match (true) {
            ! $this->config->readyToSend() => 'configuration_incomplete',
            $recipient === null => 'recipient_missing',
            ! $recipient->hasValidConsent() => 'consent_missing',
            default => null,
        };

        if ($skipCode !== null) {
            return RestaurantDayCloseDelivery::query()->create([
                ...$this->baseAttributes($dayClose, RestaurantDayCloseDelivery::KIND_AUTOMATIC, $recipient),
                'status' => RestaurantDayCloseDelivery::STATUS_SKIPPED,
                'failure_code' => $skipCode,
                'failure_reason' => match ($skipCode) {
                    'configuration_incomplete' => 'WhatsApp is not configured on this AFORO installation.',
                    'recipient_missing' => 'No active WhatsApp recipient for this restaurant.',
                    'consent_missing' => 'The WhatsApp recipient has no valid consent.',
                },
                'failed_at' => now(),
            ]);
        }

        return $this->createPending($dayClose, RestaurantDayCloseDelivery::KIND_AUTOMATIC, $recipient, null, null, null);
    }

    public function createPending(
        RestaurantDayClose $dayClose,
        string $kind,
        RestaurantReportRecipient $recipient,
        ?User $requestedBy,
        ?string $idempotencyKey,
        ?string $payloadHash,
    ): RestaurantDayCloseDelivery {
        $delivery = RestaurantDayCloseDelivery::query()->create([
            ...$this->baseAttributes($dayClose, $kind, $recipient),
            'status' => RestaurantDayCloseDelivery::STATUS_PENDING,
            'requested_by_user_id' => $requestedBy?->id,
            'requested_by_name_snapshot' => $requestedBy?->name,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'queued_at' => now(),
        ]);

        SendDayCloseWhatsApp::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    public static function activeRecipient(int $restaurantId): ?RestaurantReportRecipient
    {
        return RestaurantReportRecipient::query()
            ->where('restaurant_id', $restaurantId)
            ->where('channel', RestaurantReportRecipient::CHANNEL_WHATSAPP)
            ->where('active', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(RestaurantDayClose $dayClose, string $kind, ?RestaurantReportRecipient $recipient): array
    {
        return [
            'restaurant_day_close_id' => $dayClose->id,
            'restaurant_id' => $dayClose->restaurant_id,
            'channel' => RestaurantReportRecipient::CHANNEL_WHATSAPP,
            'kind' => $kind,
            'recipient_id' => $recipient?->id,
            'recipient_name_snapshot' => $recipient?->name,
            'recipient_phone_e164' => $recipient?->phone_e164,
            'recipient_phone_masked' => $recipient?->phone_masked,
            'recipient_phone_hash' => $recipient?->phone_hash,
            'template_name' => $this->config->templateName() ?: null,
            'template_language' => $this->config->templateLanguage() ?: null,
        ];
    }
}
