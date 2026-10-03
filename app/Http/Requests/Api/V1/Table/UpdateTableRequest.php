<?php

namespace App\Http\Requests\Api\V1\Table;

use App\Models\Table;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', Rule::in(['active', 'blocked', 'inactive'])],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'zone_id' => ['sometimes', 'nullable', 'integer'],
            'layout_x' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'layout_y' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'layout_rotation' => ['sometimes', 'integer', 'between:0,359'],
            'layout_shape' => ['sometimes', Rule::in(Table::SHAPES)],
            'layout_width' => ['sometimes', 'numeric', 'between:10,1000'],
            'layout_height' => ['sometimes', 'numeric', 'between:10,1000'],
        ];
    }

    /**
     * A zone_id sent here must belong to the SAME restaurant as the table
     * being updated — resolved from the route's table, since (unlike
     * StoreTableRequest) there is no {restaurant} route parameter here.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('zone_id') || $this->input('zone_id') === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $table = Table::query()->find((int) $this->route('table'));

            if (! $table) {
                return;
            }

            $zoneExists = $table->restaurant->zones()
                ->whereKey($this->input('zone_id'))
                ->exists();

            if (! $zoneExists) {
                $validator->errors()->add('zone_id', 'The selected zone does not belong to this restaurant.');
            }
        });
    }
}
