<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\InvalidModifierSelectionException;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\RestaurantProduct;
use Illuminate\Support\Collection;

/**
 * Revalidates a client-submitted modifier_option_ids selection from
 * scratch against the database, exactly as BuildPublicMenuAction does for
 * the read-only public menu (Bloco 9) — never trusting what the client saw
 * on a previous GET.
 */
class ModifierSelectionValidator
{
    /**
     * @param  array<int, int>  $modifierOptionIds
     * @return Collection<int, array{group: ModifierGroup, option: ModifierOption}>
     */
    public function validate(RestaurantProduct $restaurantProduct, array $modifierOptionIds): Collection
    {
        if (count($modifierOptionIds) !== count(array_unique($modifierOptionIds))) {
            throw new InvalidModifierSelectionException('A modifier option was selected more than once.');
        }

        $groups = $restaurantProduct->modifierGroups()
            ->where('status', 'active')
            ->with([
                'translations',
                'options' => fn ($query) => $query->where('status', 'active')->where('available', true),
                'options.translations',
            ])
            ->get();

        $publicOptionIds = $groups->flatMap(fn (ModifierGroup $group) => $group->options->pluck('id'))->all();

        foreach ($modifierOptionIds as $optionId) {
            if (! in_array($optionId, $publicOptionIds, true)) {
                throw new InvalidModifierSelectionException('An invalid modifier option was selected.');
            }
        }

        $selections = collect();

        foreach ($groups as $group) {
            $selectedOptions = $group->options->whereIn('id', $modifierOptionIds)->values();
            $count = $selectedOptions->count();

            if ($count < $group->min_select || $count > $group->max_select) {
                throw new InvalidModifierSelectionException(
                    "The modifier group '{$group->internal_name}' requires between {$group->min_select} and {$group->max_select} selection(s)."
                );
            }

            foreach ($selectedOptions as $option) {
                $selections->push(['group' => $group, 'option' => $option]);
            }
        }

        return $selections;
    }
}
