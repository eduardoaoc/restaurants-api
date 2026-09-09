<?php

namespace App\Http\Requests\Api\V1\Table;

use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via TablePolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'integer'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'zone_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('zones', 'id')->where('restaurant_id', (int) $this->route('restaurant')),
            ],
            'layout_x' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'layout_y' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'layout_rotation' => ['sometimes', 'integer', 'between:0,359'],
            'layout_shape' => ['sometimes', Rule::in(Table::SHAPES)],
            'layout_width' => ['sometimes', 'numeric', 'between:10,1000'],
            'layout_height' => ['sometimes', 'numeric', 'between:10,1000'],
        ];
    }
}
