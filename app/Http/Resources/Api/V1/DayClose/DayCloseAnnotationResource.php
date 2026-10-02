<?php

namespace App\Http\Resources\Api\V1\DayClose;

use App\Models\RestaurantDayCloseAnnotation;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantDayCloseAnnotation
 */
class DayCloseAnnotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_by' => ['id' => $this->created_by_user_id, 'name' => $this->created_by_name_snapshot],
            'created_at' => DayCloseFormat::instant($this->created_at),
        ];
    }
}
