<?php

namespace App\Support\WhatsApp;

use App\Models\RestaurantDayCloseDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies one Meta status webhook to its delivery (CARTA 9.1E), found by
 * provider_message_id only (never by phone). Safe against duplicated and
 * out-of-order webhooks (Meta documents both):
 *
 *   - sent/delivered/read only move FORWARD (accepted -> sent ->
 *     delivered -> read). A late lower status never regresses the
 *     status; it only fills its own *_at if still empty (e.g. a
 *     `delivered` arriving after `read`).
 *   - failed applies only while the message was not yet delivered
 *     (pending/accepted/sent); a `failed` after delivered/read is ignored.
 *   - failed/skipped are terminal; `played` and unknown statuses are
 *     ignored.
 *   - a duplicate event changes nothing (same rank, timestamp already set).
 *
 * *_at use the provider's event timestamp. No audit/activity per event:
 * the delivery row is the history.
 */
final class DeliveryStatusUpdater
{
    private const TIMESTAMP_COLUMNS = [
        RestaurantDayCloseDelivery::STATUS_SENT => 'sent_at',
        RestaurantDayCloseDelivery::STATUS_DELIVERED => 'delivered_at',
        RestaurantDayCloseDelivery::STATUS_READ => 'read_at',
        RestaurantDayCloseDelivery::STATUS_FAILED => 'failed_at',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return bool whether a delivery matched
     */
    public function apply(string $providerMessageId, string $status, ?string $unixTimestamp, array $errors = []): bool
    {
        if (! isset(self::TIMESTAMP_COLUMNS[$status])) {
            return RestaurantDayCloseDelivery::query()->where('provider_message_id', $providerMessageId)->exists();
        }

        return DB::transaction(function () use ($providerMessageId, $status, $unixTimestamp, $errors) {
            $delivery = RestaurantDayCloseDelivery::query()->where('provider_message_id', $providerMessageId)->lockForUpdate()->first();

            if ($delivery === null) {
                return false;
            }

            $current = $delivery->status;

            if (in_array($current, [RestaurantDayCloseDelivery::STATUS_FAILED, RestaurantDayCloseDelivery::STATUS_SKIPPED], true)) {
                return true;
            }

            $at = ctype_digit((string) $unixTimestamp) ? CarbonImmutable::createFromTimestampUTC((int) $unixTimestamp) : CarbonImmutable::now();
            $column = self::TIMESTAMP_COLUMNS[$status];
            $changes = [];

            if ($status === RestaurantDayCloseDelivery::STATUS_FAILED) {
                if (RestaurantDayCloseDelivery::PROGRESSION[$current] >= RestaurantDayCloseDelivery::PROGRESSION[RestaurantDayCloseDelivery::STATUS_DELIVERED]) {
                    return true;
                }

                $error = $errors[0] ?? [];
                $changes = [
                    'status' => RestaurantDayCloseDelivery::STATUS_FAILED,
                    'failed_at' => $at,
                    'failure_code' => mb_substr((string) ($error['code'] ?? 'failed'), 0, 64),
                    'failure_reason' => mb_substr(PhoneNumber::redact((string) ($error['error_data']['details'] ?? $error['title'] ?? $error['message'] ?? 'Delivery failed.')), 0, 255),
                ];
            } else {
                if ($delivery->{$column} === null) {
                    $changes[$column] = $at;
                }

                if (RestaurantDayCloseDelivery::PROGRESSION[$status] > RestaurantDayCloseDelivery::PROGRESSION[$current]) {
                    $changes['status'] = $status;
                }
            }

            if ($changes !== []) {
                $delivery->update($changes);
            }

            return true;
        });
    }
}
