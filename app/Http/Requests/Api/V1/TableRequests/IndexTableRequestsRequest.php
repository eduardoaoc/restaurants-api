<?php

namespace App\Http\Requests\Api\V1\TableRequests;

use App\Models\TableRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the table-requests list query filters. restaurant_id's actual
 * scope (does it belong to the active organization, is it in the acting
 * user's RestaurantScope) is a domain/authorization concern handled by
 * TableRequestController, not here — this only validates shape.
 */
class IndexTableRequestsRequest extends FormRequest
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
            'restaurant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(TableRequest::STATUSES)],
            'type' => ['sometimes', Rule::in(TableRequest::TYPES)],
        ];
    }
}
