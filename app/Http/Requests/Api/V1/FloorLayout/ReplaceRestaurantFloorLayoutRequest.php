<?php

namespace App\Http\Requests\Api\V1\FloorLayout;

use App\Models\RestaurantFloorLayout;
use App\Models\Restaurant;
use App\Models\Table;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the complete 3D floor layout document (version 1). Every bound
 * is explicit so the stored JSON can never be arbitrary or unbounded; only
 * validated keys are ever persisted (unknown keys are dropped by
 * validated()).
 *
 * Validated here: version, floor size/grid, per-table id/number/shape/seats/
 * position/rotation, table count, unique ids and numbers, and that each
 * table's CENTRE lies on the floor.
 * NOT validated here (frontend-only for now): table footprint vs. floor edge
 * margin and table-to-table collisions — those depend on chair/tabletop
 * geometry that lives in the editor, with no shared domain strategy yet.
 */
class ReplaceRestaurantFloorLayoutRequest extends FormRequest
{
    public const MAX_TABLES = 200;

    public const MAX_SEATS = 20;

    /** Authorization is handled by the controller via RestaurantPolicy::manageFloorPlan. */
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
            'version' => ['required', 'integer', Rule::in([1])],
            'floor' => ['required', 'array'],
            'floor.width' => ['required', 'numeric', 'between:4,100'],
            'floor.depth' => ['required', 'numeric', 'between:4,100'],
            'floor.gridStep' => ['required', 'numeric', 'between:0.05,1'],
            'entrance' => ['sometimes', 'nullable', 'array:x,z'],
            'entrance.x' => ['required_with:entrance', 'numeric'],
            'entrance.z' => ['required_with:entrance', 'numeric'],
            'tables' => ['present', 'array', 'max:'.self::MAX_TABLES],
            'tables.*.id' => ['required', 'uuid', 'distinct'],
            'tables.*.number' => ['required', 'integer', 'between:1,9999', 'distinct'],
            'tables.*.shape' => ['required', Rule::in(RestaurantFloorLayout::SHAPES)],
            'tables.*.seats' => ['required', 'integer', 'between:1,'.self::MAX_SEATS],
            'tables.*.position' => ['required', 'array'],
            'tables.*.position.x' => ['required', 'numeric', 'between:-50,50'],
            'tables.*.position.z' => ['required', 'numeric', 'between:-50,50'],
            'tables.*.rotation' => ['required', 'numeric', 'min:0', 'lt:360'],
            'tables.*.backendTableId' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'version.in' => 'Unsupported floor layout version.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $halfWidth = (float) $this->input('floor.width') / 2;
            $halfDepth = (float) $this->input('floor.depth') / 2;
            $entrance = $this->input('entrance');

            if (is_array($entrance)
                && (abs((float) $entrance['x']) > $halfWidth || abs((float) $entrance['z']) > $halfDepth)) {
                $validator->errors()->add('entrance', 'The entrance must be inside the floor.');
            }

            $mappedIds = [];

            foreach ((array) $this->input('tables', []) as $index => $table) {
                if (abs((float) $table['position']['x']) > $halfWidth || abs((float) $table['position']['z']) > $halfDepth) {
                    $validator->errors()->add("tables.{$index}.position", 'The table must be inside the floor.');
                }
                $backendTableId = $table['backendTableId'] ?? null;
                if ($backendTableId === null) {
                    continue;
                }
                if (in_array((int) $backendTableId, $mappedIds, true)) {
                    $validator->errors()->add("tables.{$index}.backendTableId", 'A backend table may only be assigned once.');
                }
                $mappedIds[] = (int) $backendTableId;
            }

            if ($mappedIds !== []) {
                $restaurantId = (int) $this->route('restaurant');
                $organizationId = app(TenantContext::class)->getOrganizationId();
                $restaurant = $organizationId === null
                    ? null
                    : Restaurant::query()->whereKey($restaurantId)->where('organization_id', $organizationId)->first();

                // Do not let pre-controller validation distinguish resources
                // in another tenant or outside the actor's restaurant scope.
                if (! $restaurant || ! RestaurantScope::canAccessRestaurant($this->user(), $restaurant)) {
                    return;
                }

                $validIds = Table::query()->where('restaurant_id', $restaurantId)->whereIn('id', $mappedIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
                foreach ((array) $this->input('tables', []) as $index => $table) {
                    $backendTableId = $table['backendTableId'] ?? null;
                    if ($backendTableId !== null && ! in_array((int) $backendTableId, $validIds, true)) {
                        $validator->errors()->add("tables.{$index}.backendTableId", 'The backend table must belong to this restaurant.');
                    }
                }
            }
        });
    }
}
