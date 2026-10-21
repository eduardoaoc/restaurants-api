<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\InvalidOrderItemException;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Support\Locale\LocaleResolver;
use App\Support\Money\Money;

/**
 * Revalidates every submitted order item against the current catalog state
 * and builds the fully-priced, snapshot-ready item specs for it — shared
 * by both the public (customer_qr) and staff (waiter) creation paths so
 * pricing/validation rules never diverge between them.
 *
 * All money math happens in integer cents (see Money) and is only
 * formatted back into decimal strings at the end, per item.
 */
class BuildOrderItemsAction
{
    public function __construct(private readonly ModifierSelectionValidator $modifierSelectionValidator) {}

    /**
     * @param  array<int, array{restaurant_product_id: int, quantity: int, note?: ?string, modifier_option_ids?: array<int, int>}>  $items
     * @return array{itemSpecs: array<int, array<string, mixed>>, subtotalCents: int, modifiersTotalCents: int}
     */
    public function execute(Restaurant $restaurant, array $items, string $locale): array
    {
        $itemSpecs = [];
        $subtotalCents = 0;
        $modifiersTotalCents = 0;
        $restaurantDefaultLocale = $restaurant->settings?->default_locale;

        // Lock the complete set in stable order, including unavailable rows.
        // OrderCreationService owns the transaction (9101 -> 9102 -> session -> products).
        // A concurrent availability/price change is decided before or after this order,
        // and every item is revalidated before any order/item is persisted.
        $products = RestaurantProduct::query()
            ->whereIn('id', array_column($items, 'restaurant_product_id'))
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('id')->lockForUpdate()->with('product.translations')->get()->keyBy('id');

        foreach ($items as $rawItem) {
            $restaurantProduct = $products->get($rawItem['restaurant_product_id']);

            if (! $restaurantProduct || ! $restaurantProduct->available || ! $restaurantProduct->product || $restaurantProduct->product->status !== 'active') {
                throw new InvalidOrderItemException('One of the selected products is not available.');
            }

            $translation = LocaleResolver::pickTranslation($restaurantProduct->product->translations, $locale, $restaurantDefaultLocale);

            if (! $translation) {
                throw new InvalidOrderItemException('One of the selected products has no publishable name.');
            }

            $quantity = (int) $rawItem['quantity'];
            $modifierOptionIds = array_values($rawItem['modifier_option_ids'] ?? []);

            $selections = $this->modifierSelectionValidator->validate($restaurantProduct, $modifierOptionIds);

            $modifiersUnitTotalCents = 0;
            $modifierSpecs = [];

            foreach ($selections as $selection) {
                $group = $selection['group'];
                $option = $selection['option'];

                $groupTranslation = LocaleResolver::pickTranslation($group->translations, $locale, $restaurantDefaultLocale);
                $optionTranslation = LocaleResolver::pickTranslation($option->translations, $locale, $restaurantDefaultLocale);

                if (! $groupTranslation || ! $optionTranslation) {
                    throw new InvalidOrderItemException('One of the selected modifiers has no publishable name.');
                }

                $priceDeltaCents = Money::decimalToCents((string) $option->price_delta);
                $modifiersUnitTotalCents += $priceDeltaCents;

                $modifierSpecs[] = [
                    'modifier_group_id' => $group->id,
                    'modifier_option_id' => $option->id,
                    'modifier_group_name_snapshot' => $groupTranslation->name,
                    'modifier_option_name_snapshot' => $optionTranslation->name,
                    'price_delta_snapshot' => Money::centsToDecimal($priceDeltaCents),
                ];
            }

            $unitPriceCents = Money::decimalToCents((string) $restaurantProduct->price);
            $unitTotalCents = $unitPriceCents + $modifiersUnitTotalCents;
            $lineTotalCents = $unitTotalCents * $quantity;

            $subtotalCents += $unitPriceCents * $quantity;
            $modifiersTotalCents += $modifiersUnitTotalCents * $quantity;

            $itemSpecs[] = [
                'restaurant_product_id' => $restaurantProduct->id,
                'product_id' => $restaurantProduct->product_id,
                'product_name_snapshot' => $translation->name,
                'product_description_snapshot' => $translation->description,
                'unit_price_snapshot' => Money::centsToDecimal($unitPriceCents),
                'quantity' => $quantity,
                'modifiers_unit_total_snapshot' => Money::centsToDecimal($modifiersUnitTotalCents),
                'unit_total_snapshot' => Money::centsToDecimal($unitTotalCents),
                'line_total_snapshot' => Money::centsToDecimal($lineTotalCents),
                'customer_note' => $rawItem['note'] ?? null,
                'modifiers' => $modifierSpecs,
            ];
        }

        return [
            'itemSpecs' => $itemSpecs,
            'subtotalCents' => $subtotalCents,
            'modifiersTotalCents' => $modifiersTotalCents,
        ];
    }
}
