<?php

namespace App\Http\Requests\Api\V1\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /kitchen/dashboard — the dashboard is per restaurant (its "today"
 * is that restaurant's local day), so restaurant_id is required here,
 * unlike the multi-restaurant queue (GET /kitchen/orders). Its scope is
 * resolved by KitchenController (404 outside it), not here.
 */
class KitchenDashboardRequest extends FormRequest
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
            'restaurant_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
