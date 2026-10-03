<?php

namespace App\Http\Requests\Api\V1\Zone;

use App\Models\Zone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateZoneRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via ZonePolicy.
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
            'name' => ['sometimes', 'string', 'max:255'],
            'floor_id' => ['sometimes', 'integer'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A moved zone (floor_id sent) must land on a floor of the SAME
     * restaurant as the zone itself — resolved here from the route's zone,
     * since (unlike StoreZoneRequest) there is no {restaurant} route
     * parameter to validate against directly.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('floor_id') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $zone = Zone::query()->find((int) $this->route('zone'));

            if (! $zone) {
                return;
            }

            $floorExists = $zone->restaurant->floors()
                ->whereKey($this->input('floor_id'))
                ->exists();

            if (! $floorExists) {
                $validator->errors()->add('floor_id', 'The selected floor does not belong to this restaurant.');
            }
        });
    }
}
