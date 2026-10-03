<?php

namespace App\Http\Resources\Api\V1\WhatsApp;

use App\Models\RestaurantDayCloseDelivery;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Cierre Diario WhatsApp delivery (CARTA 9.1E). Never the full phone
 * number, the provider message id or any credential.
 *
 * @mixin RestaurantDayCloseDelivery
 */
class DayCloseDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'channel' => $this->channel,
            'status' => $this->status,
            'recipient' => $this->recipient_name_snapshot !== null || $this->recipient_phone_masked !== null ? [
                'name' => $this->recipient_name_snapshot,
                'phone_masked' => $this->recipient_phone_masked,
            ] : null,
            'template' => ['name' => $this->template_name, 'language' => $this->template_language],
            'attempts' => $this->attempts,
            'requested_by' => $this->requested_by_user_id !== null || $this->requested_by_name_snapshot !== null
                ? ['id' => $this->requested_by_user_id, 'name' => $this->requested_by_name_snapshot]
                : null,
            'failure' => $this->failure_code !== null ? ['code' => $this->failure_code, 'reason' => $this->failure_reason] : null,
            'queued_at' => DayCloseFormat::instant($this->queued_at),
            'accepted_at' => DayCloseFormat::instant($this->accepted_at),
            'sent_at' => DayCloseFormat::instant($this->sent_at),
            'delivered_at' => DayCloseFormat::instant($this->delivered_at),
            'read_at' => DayCloseFormat::instant($this->read_at),
            'failed_at' => DayCloseFormat::instant($this->failed_at),
            'created_at' => DayCloseFormat::instant($this->created_at),
        ];
    }
}
