<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Table
 */
class TableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeSession = $this->activeSession;

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->name,
            'number' => $this->number,
            'public_token' => $this->public_token,
            'status' => $this->status,
            'has_active_session' => $activeSession !== null,
            'active_session' => $activeSession ? [
                'id' => $activeSession->id,
                'status' => $activeSession->status,
                'guest_count' => $activeSession->guest_count,
                'opened_at' => $activeSession->opened_at,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
