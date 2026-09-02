<?php

namespace App\Http\Requests\Api\V1\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of a public table-request creation (call_waiter or
 * request_bill — the type comes from which endpoint was hit, never from
 * the payload). Domain rules (active session, duplicate-open check) are
 * CreatePublicTableRequestAction's job, not this Request's.
 */
class StorePublicTableRequestRequest extends FormRequest
{
    /**
     * Public endpoint: no authenticated user is required.
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
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
