<?php

namespace App\Http\Requests\Api\V1\Activity;

use App\Support\Activity\RestaurantActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for GET /restaurants/{restaurant}/activity. `from`/`to` are ISO
 * 8601 instants (half-open: from <= occurred_at < to) — the client
 * converts its local day boundaries, so no timezone is guessed here.
 * `cursor` is the opaque value returned as meta.next_cursor/prev_cursor.
 */
class IndexRestaurantActivityRequest extends FormRequest
{
    public const MAX_PER_PAGE = 100;

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
            'category' => ['sometimes', 'string', Rule::in(RestaurantActivityType::CATEGORIES)],
            'type' => ['sometimes', 'string', Rule::in(RestaurantActivityType::all())],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }
}
