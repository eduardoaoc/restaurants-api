<?php

namespace App\Http\Requests\Api\V1\WhatsApp;

use App\Models\Restaurant;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of PUT /restaurants/{restaurant}/day-close-whatsapp-settings
 * (CARTA 9.1E). The phone must be strict E.164 ("+34612345678"). The
 * linked user (optional) must belong to the restaurant's organization.
 * Business rules (consent for a new number, enabling) live in
 * UpdateDayCloseWhatsAppSettingsAction.
 */
class UpdateDayCloseWhatsAppSettingsRequest extends FormRequest
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
        $organizationId = $this->route('restaurant') ? Restaurant::query()->whereKey((int) $this->route('restaurant'))->value('organization_id') : null;

        return [
            'enabled' => ['required', 'boolean'],
            'recipient' => ['present', 'nullable', 'array'],
            'recipient.name' => ['required_with:recipient', 'string', 'max:100'],
            'recipient.phone' => ['nullable', 'string', 'regex:'.PhoneNumber::E164_PATTERN],
            'recipient.user_id' => ['nullable', 'integer', Rule::exists('organization_users', 'user_id')->where('organization_id', $organizationId)],
            'recipient.consent_confirmed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['recipient.phone.regex' => 'The phone must be in international E.164 format, e.g. +34612345678.'];
    }
}
