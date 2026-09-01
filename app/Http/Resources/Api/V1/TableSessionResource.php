<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TableSession
 */
class TableSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_id' => $this->table_id,
            'restaurant_id' => $this->restaurant_id,
            'guest_count' => $this->guest_count,
            'status' => $this->status,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'opened_by_user_id' => $this->opened_by_user_id,
            'closed_by_user_id' => $this->closed_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
