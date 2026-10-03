<?php

namespace App\Http\Requests\Api\V1\FloorPlan;

use App\Models\Table;
use App\Models\Zone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk floor-plan layout save. Every cross-restaurant check
 * (a foreign table id, a zone_id belonging to a different restaurant) runs
 * here, BEFORE the controller/Action ever touches the database — so an
 * invalid payload fails as a whole with a 422 and zero rows are written,
 * satisfying the "atomic, all-or-nothing" requirement without needing the
 * Action itself to re-validate ownership.
 */
class UpdateFloorPlanLayoutRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via RestaurantPolicy::manageFloorPlan.
     */
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
            'tables' => ['required', 'array', 'min:1'],
            'tables.*.id' => ['required', 'integer', 'distinct'],
            'tables.*.zone_id' => ['sometimes', 'nullable', 'integer'],
            'tables.*.layout_x' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'tables.*.layout_y' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'tables.*.layout_rotation' => ['sometimes', 'integer', 'between:0,359'],
            'tables.*.layout_shape' => ['sometimes', Rule::in(Table::SHAPES)],
            'tables.*.layout_width' => ['sometimes', 'numeric', 'between:10,1000'],
            'tables.*.layout_height' => ['sometimes', 'numeric', 'between:10,1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $restaurantId = (int) $this->route('restaurant');
            $tables = (array) $this->input('tables', []);

            $tableIds = collect($tables)->pluck('id')->filter()->unique()->values();
            $validTableCount = Table::query()
                ->where('restaurant_id', $restaurantId)
                ->whereIn('id', $tableIds)
                ->count();

            if ($validTableCount !== $tableIds->count()) {
                $validator->errors()->add('tables', 'One or more tables do not belong to this restaurant.');

                return;
            }

            $zoneIds = collect($tables)
                ->pluck('zone_id')
                ->filter(fn ($zoneId) => $zoneId !== null)
                ->unique()
                ->values();

            if ($zoneIds->isEmpty()) {
                return;
            }

            $validZoneCount = Zone::query()
                ->where('restaurant_id', $restaurantId)
                ->whereIn('id', $zoneIds)
                ->count();

            if ($validZoneCount !== $zoneIds->count()) {
                $validator->errors()->add('tables', 'One or more zones do not belong to this restaurant.');
            }
        });
    }
}
