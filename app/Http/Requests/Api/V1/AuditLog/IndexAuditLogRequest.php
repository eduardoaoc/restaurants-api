<?php

namespace App\Http\Requests\Api\V1\AuditLog;

use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the audit log list query filters' shape only. restaurant_id's
 * actual scope (organization membership, RestaurantScope) is a domain/
 * authorization concern handled by AuditLogController (404 there, not
 * 422 here). from/to's together-or-neither and range rules are handled by
 * AuditLogPeriodResolver for the same reason (422 INVALID_AUDIT_PERIOD,
 * not a validation error) — here they're only checked for date shape.
 */
class IndexAuditLogRequest extends FormRequest
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
            'restaurant_id' => ['sometimes', 'integer', 'min:1'],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'event' => ['sometimes', 'string', Rule::in(AuditLog::EVENTS)],
            'resource_type' => ['sometimes', 'string', Rule::in(AuditLog::RESOURCE_TYPES)],
            'resource_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
