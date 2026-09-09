<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPlatformAuditLogRequest extends FormRequest
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
            'event' => ['sometimes', 'string', Rule::in(AuditLog::EVENTS)],
            'resource_type' => ['sometimes', 'string', Rule::in(AuditLog::RESOURCE_TYPES)],
            'resource_id' => ['sometimes', 'integer', 'min:1'],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
