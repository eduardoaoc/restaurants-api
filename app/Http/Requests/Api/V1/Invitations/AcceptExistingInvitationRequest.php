<?php

namespace App\Http\Requests\Api\V1\Invitations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepting with an EXISTING account: the password of the account that owns
 * the invitation's e-mail re-authenticates the person. Nothing about that
 * account (name, password, other memberships) can be changed here.
 */
class AcceptExistingInvitationRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:4096'],
        ];
    }
}
