<?php

namespace App\Http\Requests\Api\V1\Invitations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The token always travels in the JSON body — never in a URL, so it cannot
 * end up in access logs, browser history or a Referer header.
 */
class PreviewInvitationRequest extends FormRequest
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
        ];
    }
}
