<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesUniqueTranslationLocales
{
    /**
     * Add an error to `translations` when the same locale appears more than once.
     */
    protected function ensureTranslationLocalesAreUnique(Validator $validator): void
    {
        $locales = collect($this->input('translations', []))->pluck('locale')->filter();

        if ($locales->count() !== $locales->unique()->count()) {
            $validator->errors()->add('translations', 'Each locale may only appear once.');
        }
    }
}
