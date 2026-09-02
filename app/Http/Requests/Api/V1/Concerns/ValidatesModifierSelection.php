<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesModifierSelection
{
    /**
     * Enforce the two selection invariants for a modifier group:
     * max_select must be >= min_select, and required=true implies min_select >= 1.
     */
    protected function ensureSelectionIsConsistent(Validator $validator, int $minSelect, int $maxSelect, bool $required): void
    {
        if ($maxSelect < $minSelect) {
            $validator->errors()->add('max_select', 'The max select must be greater than or equal to min select.');
        }

        if ($required && $minSelect < 1) {
            $validator->errors()->add('min_select', 'The min select must be at least 1 when the group is required.');
        }
    }
}
