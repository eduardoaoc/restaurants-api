<?php

namespace App\Http\Resources\Api\V1\DayClose;

use App\Models\RestaurantDayClose;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full persisted Cierre Diario (CARTA 9.1A): every column, the
 * immutable `report` exactly as stored, its hash, and the post-close
 * annotations (separate — they never alter the report). Never queries
 * orders/payments to rebuild anything.
 *
 * @mixin RestaurantDayClose
 */
class DayCloseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new DayCloseSummaryResource($this->resource))->toArray($request),
            'cash_received' => $this->cash_received,
            'card_received' => $this->card_received,
            'other_received' => $this->other_received,
            'payments_count' => $this->payments_count,
            'sessions_with_payments' => $this->sessions_with_payments,
            'average_ticket' => $this->average_ticket,
            'opening_float' => $this->opening_float,
            'cash_pay_ins' => $this->cash_pay_ins,
            'cash_pay_outs' => $this->cash_pay_outs,
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'cash_left_for_next_day' => $this->cash_left_for_next_day,
            'cash_difference_note' => $this->cash_difference_note,
            'orders_registered' => $this->orders_registered,
            'orders_served' => $this->orders_served,
            'orders_rejected' => $this->orders_rejected,
            'sessions_opened' => $this->sessions_opened,
            'sessions_closed' => $this->sessions_closed,
            'feedback_count' => $this->feedback_count,
            'feedback_avg_overall' => $this->feedback_avg_overall,
            'low_dimension_feedback_count' => $this->low_dimension_feedback_count,
            'notes' => $this->notes,
            'report_schema_version' => $this->report_schema_version,
            'report_sha256' => $this->report_sha256,
            'report' => $this->report,
            'annotations' => DayCloseAnnotationResource::collection($this->annotations),
            'created_at' => DayCloseFormat::instant($this->created_at),
        ];
    }
}
