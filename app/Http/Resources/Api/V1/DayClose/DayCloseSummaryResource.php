<?php

namespace App\Http\Resources\Api\V1\DayClose;

use App\Models\RestaurantDayClose;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A history row of a Cierre Diario (CARTA 9.1A) — persisted columns only,
 * never the report. Nothing here is recomputed from live data.
 *
 * @mixin RestaurantDayClose
 */
class DayCloseSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'restaurant_id' => $this->restaurant_id,
            'business_date' => $this->business_date->format('Y-m-d'),
            'business_date_from' => $this->business_date_from->format('Y-m-d'),
            'period_started_at' => DayCloseFormat::instant($this->period_started_at),
            'period_ended_at' => DayCloseFormat::instant($this->period_ended_at),
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'total_received' => $this->total_received,
            'orders_valid' => $this->orders_valid,
            'guests' => $this->guests,
            'cash_difference' => $this->cash_difference,
            'critical_feedback_count' => $this->critical_feedback_count,
            'delays_count' => $this->delays_count,
            'unavailable_products_count' => $this->unavailable_products_count,
            'has_incidents' => $this->has_incidents,
            'closed_by' => ['id' => $this->closed_by_user_id, 'name' => $this->closed_by_name_snapshot],
            'closed_at' => DayCloseFormat::instant($this->closed_at),
        ];
    }
}
