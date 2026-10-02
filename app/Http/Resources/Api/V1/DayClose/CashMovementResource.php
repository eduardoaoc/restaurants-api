<?php

namespace App\Http\Resources\Api\V1\DayClose;

use App\Models\RestaurantCashMovement;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantCashMovement
 */
class CashMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'recorded_by' => ['id' => $this->recorded_by_user_id, 'name' => $this->recorded_by_name_snapshot],
            'recorded_at' => DayCloseFormat::instant($this->recorded_at),
        ];
    }
}
