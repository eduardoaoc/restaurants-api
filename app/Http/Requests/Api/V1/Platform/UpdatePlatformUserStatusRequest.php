<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization is handled by the controller via the platform.users.manage
 * Gate (plus the self-suspend guard, which needs the authenticated user
 * and isn't expressible as a static validation rule).
 */
class UpdatePlatformUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * reason is mandatory on every platform status mutation — including
     * reactivation, not only suspension — for a uniform audit trail (see
     * PlatformAuditLogger).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(User::STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
