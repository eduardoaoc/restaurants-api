<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post-close note on a Cierre Diario (CARTA 9.1A). Append-only
 * (created_at only) and never part of the close's report/hash.
 */
#[Fillable(['restaurant_day_close_id', 'restaurant_id', 'body', 'created_by_user_id', 'created_by_name_snapshot', 'created_at'])]
class RestaurantDayCloseAnnotation extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<RestaurantDayClose, $this>
     */
    public function dayClose(): BelongsTo
    {
        return $this->belongsTo(RestaurantDayClose::class, 'restaurant_day_close_id');
    }
}
