<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPlatformRestaurantsRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:255'],
            'organization_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in([
                Restaurant::STATUS_ACTIVE,
                Restaurant::STATUS_INACTIVE,
                Restaurant::STATUS_SUSPENDED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
