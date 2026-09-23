<?php

namespace App\Http\Requests\Api\V1\Concerns;

trait ValidatesNutritionValues
{
    /**
     * Nutrition is entirely optional and always per-serving (never per
     * 100g) for this MVP. Every value is independently nullable — a
     * partial nutrition object (e.g. calories only) is valid. The maximums
     * only guard against absurd input, not real-world extremes.
     *
     * @return array<string, mixed>
     */
    protected function nutritionRules(): array
    {
        return [
            'nutrition' => ['sometimes', 'nullable', 'array'],
            'nutrition.calories_kcal' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'nutrition.protein_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'nutrition.carbohydrates_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'nutrition.fat_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'nutrition.salt_g' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
