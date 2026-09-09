<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformRestaurantStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in([
                Restaurant::STATUS_ACTIVE,
                Restaurant::STATUS_SUSPENDED,
            ])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
