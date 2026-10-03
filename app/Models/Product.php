<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'sku', 'internal_name', 'status',
    'allergens', 'calories_kcal', 'protein_g', 'carbohydrates_g', 'fat_g', 'salt_g',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The 14 EU/Spain allergen groups (Regulation (EU) 1169/2011, Annex II),
     * as stable, language-independent codes. The frontend translates labels
     * and picks icons; the backend only ever stores/validates these codes.
     *
     * @var array<int, string>
     */
    public const ALLERGEN_CODES = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans',
        'milk', 'nuts', 'celery', 'mustard', 'sesame', 'sulphites',
        'lupin', 'molluscs',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allergens' => 'array',
            'calories_kcal' => 'integer',
            'protein_g' => 'decimal:2',
            'carbohydrates_g' => 'decimal:2',
            'fat_g' => 'decimal:2',
            'salt_g' => 'decimal:2',
        ];
    }

    /**
     * The public "per serving" nutrition payload, or null when none of the
     * optional values has been recorded — distinct from a value being 0.
     *
     * @return array{basis: string, calories_kcal: ?int, protein_g: ?string, carbohydrates_g: ?string, fat_g: ?string, salt_g: ?string}|null
     */
    public function nutritionPayload(): ?array
    {
        if (
            $this->calories_kcal === null
            && $this->protein_g === null
            && $this->carbohydrates_g === null
            && $this->fat_g === null
            && $this->salt_g === null
        ) {
            return null;
        }

        return [
            'basis' => 'per_serving',
            'calories_kcal' => $this->calories_kcal,
            'protein_g' => $this->protein_g,
            'carbohydrates_g' => $this->carbohydrates_g,
            'fat_g' => $this->fat_g,
            'salt_g' => $this->salt_g,
        ];
    }

    /**
     * The organization this product's catalog entry belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<ProductTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    /**
     * The per-restaurant price/availability entries for this product.
     *
     * @return HasMany<RestaurantProduct, $this>
     */
    public function restaurantProducts(): HasMany
    {
        return $this->hasMany(RestaurantProduct::class);
    }

    /**
     * At most one row per ProductMedia::TYPES value (DB-enforced unique
     * (product_id, type)) — never a general-purpose media gallery.
     *
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function mediaImage(): ?ProductMedia
    {
        return $this->mediaOfType(ProductMedia::TYPE_IMAGE);
    }

    public function mediaVideo(): ?ProductMedia
    {
        return $this->mediaOfType(ProductMedia::TYPE_VIDEO);
    }

    /**
     * Reads the already-eager-loaded `media` collection when available, so
     * a product list never re-queries per row (see ProductResource /
     * PublicProductResource / BuildPublicMenuAction eager loads).
     */
    private function mediaOfType(string $type): ?ProductMedia
    {
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();

        return $media->firstWhere('type', $type);
    }
}
