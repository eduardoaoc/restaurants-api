<?php

namespace App\Http\Requests\Api\V1\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a string made only of whitespace — Laravel's own `required` rule
 * accepts " " as non-empty, which is not good enough for a description
 * meant to be shown to diners.
 */
class NotBlank implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) === '') {
            $fail('The :attribute must not be blank.');
        }
    }
}
