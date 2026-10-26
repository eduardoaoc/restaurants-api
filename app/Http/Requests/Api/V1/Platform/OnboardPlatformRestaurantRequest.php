<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /platform/restaurants — only what onboarding needs.
 *
 * Exactly one of `organization` (new organization) or `organization_id`
 * (additional restaurant in an existing one). `owner` is required for a new
 * organization and prohibited for an existing one: there the org-wide
 * owners already reach the new restaurant and nothing is granted.
 *
 * Currency is not accepted: EUR is the only supported currency and comes
 * from RestaurantSettings::createDefaultsFor(). No role, permission,
 * restaurant id, plan or status can be sent — the payload cannot inject
 * grants or pick another tenant's data.
 */
class OnboardPlatformRestaurantRequest extends FormRequest
{
    private const SLUG_REGEX = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Authorization is handled by PlatformOnboardingController.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);

        $owner = $this->input('owner');

        if (is_array($owner) && is_string($owner['email'] ?? null)) {
            $owner['email'] = strtolower(trim($owner['email']));
            $this->merge(['owner' => $owner]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->input('organization_id');

        // A retry of an already-onboarded key must reach the Action, which
        // replays it (same payload) or answers IDEMPOTENCY_KEY_REUSED —
        // never a misleading "slug already taken" for its own first attempt.
        $key = $this->input('idempotency_key');
        $isRetry = is_string($key) && Restaurant::query()->where('onboarding_idempotency_key', $key)->exists();
        $unique = fn ($rule) => $isRetry ? null : $rule;

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],

            'organization_id' => ['nullable', 'integer', 'required_without:organization', 'prohibits:organization,owner', Rule::exists('organizations', 'id')],
            'organization' => ['nullable', 'array', 'required_without:organization_id'],
            'organization.name' => ['required_with:organization', 'string', 'max:255'],
            'organization.slug' => array_filter(['required_with:organization', 'string', 'max:255', self::SLUG_REGEX, $unique(Rule::unique('organizations', 'slug'))]),

            'restaurant' => ['required', 'array'],
            'restaurant.name' => ['required', 'string', 'max:255'],
            'restaurant.slug' => array_filter([
                'required', 'string', 'max:255', self::SLUG_REGEX,
                $unique(Rule::unique('restaurants', 'slug')->where('organization_id', is_numeric($organizationId) ? (int) $organizationId : 0)),
            ]),
            'restaurant.timezone' => ['nullable', 'timezone'],
            'restaurant.default_locale' => ['nullable', 'string', Rule::in(RestaurantSettings::SUPPORTED_LOCALES)],

            'owner' => ['nullable', 'array', 'required_with:organization'],
            'owner.email' => ['required_with:owner', 'email', 'max:255'],
            'owner.name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['idempotency_key.required' => 'The Idempotency-Key header is required.'];
    }

    /**
     * The normalized onboarding payload (no header, no unknown keys).
     *
     * @return array<string, mixed>
     */
    public function onboardingData(): array
    {
        $validated = $this->validated();
        $pick = fn (?array $values, array $keys) => $values === null ? null : array_filter(
            array_intersect_key($values, array_flip($keys)),
            fn ($value) => $value !== null && $value !== '',
        );

        // Explicit keys only: an `array` rule would otherwise carry any
        // extra nested key the client sent.
        return array_filter([
            'organization_id' => isset($validated['organization_id']) ? (int) $validated['organization_id'] : null,
            'organization' => $pick($validated['organization'] ?? null, ['name', 'slug']),
            'restaurant' => $pick($validated['restaurant'], ['name', 'slug', 'timezone', 'default_locale']),
            'owner' => $pick($validated['owner'] ?? null, ['email', 'name']),
        ], fn ($value) => $value !== null);
    }
}
