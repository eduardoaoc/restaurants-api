<?php

namespace App\Http\Requests\Api\V1\Rules;

use App\Support\Restaurants\GoogleReviewUrl as GoogleReviewUrlPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation adapter for GoogleReviewUrl — the allowlist itself lives
 * only there.
 */
class GoogleReviewUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! GoogleReviewUrlPolicy::isValid($value)) {
            $fail('The :attribute must be an HTTPS Google review/share link in a format currently supported by AFORO (g.page, search.google.com/local, maps.app.goo.gl or google.com/maps).');
        }
    }
}
