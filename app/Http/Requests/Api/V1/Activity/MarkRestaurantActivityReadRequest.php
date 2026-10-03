<?php

namespace App\Http\Requests\Api\V1\Activity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /restaurants/{restaurant}/activity/read. `last_event_id` is the
 * newest event the client has actually shown; omitted, it means "the
 * restaurant's latest event right now". Shape only here — that the id is
 * an event of THIS restaurant is checked by the controller, after the
 * restaurant itself has been resolved and authorized (so an unauthorized
 * caller gets 404/403, never a 422 that probes another tenant's ids).
 */
class MarkRestaurantActivityReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_event_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
