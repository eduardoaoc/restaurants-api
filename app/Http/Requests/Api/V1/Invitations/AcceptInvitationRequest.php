<?php

namespace App\Http\Requests\Api\V1\Invitations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepting with a NEW account: the person chooses their own name and
 * password (same minimum as the Staff API). No e-mail field — the account
 * e-mail is always the invitation's.
 */
class AcceptInvitationRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
