<?php

namespace App\Actions\Public;

use App\Http\Resources\Api\V1\Public\PublicCategoryResource;
use App\Http\Resources\Api\V1\Public\PublicMenuResource;
use App\Http\Resources\Api\V1\Public\PublicModifierGroupResource;
use App\Http\Resources\Api\V1\Public\PublicModifierOptionResource;
use App\Http\Resources\Api\V1\Public\PublicProductResource;
use App\Models\Category;
use App\Models\CategoryProduct;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Restaurant;
use App\Support\Locale\LocaleResolver;

/**
 * Builds the public menu tree for a restaurant, applying every public
 * visibility rule (status/available filters, translation availability,
 * empty-branch pruning) and resolving translations for the given locale.
 *
 * Loads the full menu -> categories -> products -> modifiers structure in
 * one eager-loaded query set to avoid N+1s.
 */
class BuildPublicMenuAction
{
    public function execute(Restaurant $restaurant, string $locale): ?PublicMenuResource
    {
        $menu = Menu::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'active')
            ->with($this->eagerLoads())
            ->first();

        if (! $menu) {
            return null;
        }

        $defaultLocale = $restaurant->settings?->default_locale;

        $categories = $menu->categories
            ->map(fn (Category $category) => $this->buildCategory($category, $locale, $defaultLocale))
            ->filter()
            ->values()
            ->all();

        return new PublicMenuResource($menu, $categories);
    }

    private function buildCategory(Category $category, string $locale, ?string $defaultLocale): ?PublicCategoryResource
    {
        $translation = LocaleResolver::pickTranslation($category->translations, $locale, $defaultLocale);

        if (! $translation) {
            return null;
        }

        $products = $category->categoryProducts
            ->map(fn (CategoryProduct $categoryProduct) => $this->buildProduct($categoryProduct, $locale, $defaultLocale))
            ->filter()
            ->values()
            ->all();

        if ($products === []) {
            return null;
        }

        return new PublicCategoryResource($category, $translation, $products);
    }

    private function buildProduct(CategoryProduct $categoryProduct, string $locale, ?string $defaultLocale): ?PublicProductResource
    {
        $restaurantProduct = $categoryProduct->restaurantProduct;

        if (! $restaurantProduct || ! $restaurantProduct->product) {
            return null;
        }

        $translation = LocaleResolver::pickTranslation($restaurantProduct->product->translations, $locale, $defaultLocale);

        if (! $translation) {
            return null;
        }

        $modifierGroups = $restaurantProduct->modifierGroups
            ->map(fn (ModifierGroup $group) => $this->buildModifierGroup($group, $locale, $defaultLocale))
            ->filter()
            ->values()
            ->all();

        return new PublicProductResource($restaurantProduct, $translation, $modifierGroups);
    }

    private function buildModifierGroup(ModifierGroup $group, string $locale, ?string $defaultLocale): ?PublicModifierGroupResource
    {
        $translation = LocaleResolver::pickTranslation($group->translations, $locale, $defaultLocale);

        if (! $translation) {
            return null;
        }

        $options = $group->options
            ->map(fn (ModifierOption $option) => $this->buildModifierOption($option, $locale, $defaultLocale))
            ->filter()
            ->values()
            ->all();

        if ($options === []) {
            return null;
        }

        return new PublicModifierGroupResource($group, $translation, $options);
    }

    private function buildModifierOption(ModifierOption $option, string $locale, ?string $defaultLocale): ?PublicModifierOptionResource
    {
        $translation = LocaleResolver::pickTranslation($option->translations, $locale, $defaultLocale);

        if (! $translation) {
            return null;
        }

        return new PublicModifierOptionResource($option, $translation);
    }

    /**
     * @return array<string, string|\Closure>
     */
    private function eagerLoads(): array
    {
        return [
            'categories' => fn ($query) => $query->where('status', 'active')->orderBy('sort_order')->orderBy('id'),
            'categories.translations' => fn ($query) => $query,
            'categories.categoryProducts' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'categories.categoryProducts.restaurantProduct' => fn ($query) => $query->where('available', true),
            'categories.categoryProducts.restaurantProduct.product' => fn ($query) => $query->where('status', 'active'),
            'categories.categoryProducts.restaurantProduct.product.translations' => fn ($query) => $query,
            'categories.categoryProducts.restaurantProduct.modifierGroups' => fn ($query) => $query->where('status', 'active')->orderBy('sort_order')->orderBy('id'),
            'categories.categoryProducts.restaurantProduct.modifierGroups.translations' => fn ($query) => $query,
            'categories.categoryProducts.restaurantProduct.modifierGroups.options' => fn ($query) => $query->where('status', 'active')->where('available', true)->orderBy('sort_order')->orderBy('id'),
            'categories.categoryProducts.restaurantProduct.modifierGroups.options.translations' => fn ($query) => $query,
        ];
    }
}
