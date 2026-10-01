<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RestaurantActivityEvent;
use App\Support\Activity\RestaurantActivityType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one wire shape of an activity event (CARTA 6.1A) — used verbatim by
 * GET /restaurants/{restaurant}/activity AND as the `activity` payload of
 * the `restaurant.activity.created` broadcast (see
 * RestaurantActivityCreated), so a client never reconciles two contracts.
 *
 * Built only from the row's own snapshots: no relation is ever loaded.
 * table/order are null when the event is not about one.
 *
 * @mixin RestaurantActivityEvent
 */
class RestaurantActivityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            // Derived from the type at read time (never stored) — see
            // RestaurantActivityType::TONE_BY_TYPE.
            'tone' => RestaurantActivityType::tone($this->type),
            'occurred_at' => $this->occurred_at,
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_user_id,
                'name' => $this->actor_name_snapshot,
            ],
            'table' => $this->table_id === null ? null : [
                'id' => $this->table_id,
                'name' => $this->table_name_snapshot,
            ],
            'table_session_id' => $this->table_session_id,
            'order' => $this->order_id === null ? null : [
                'id' => $this->order_id,
                'reference' => $this->order_reference,
            ],
            'table_request_id' => $this->table_request_id,
            'metadata' => $this->metadata === null ? null : (object) $this->metadata,
        ];
    }
}
