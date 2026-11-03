<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The persisted 3D floor layout of a Restaurant: a versioned JSON document
 * holding layout configuration only. `layout` is the full canonical document
 * ({version, floor, tables[]}) exactly as returned to the editor.
 *
 * Deliberately unrelated to the 2D floor plan (floors/zones/tables' layout_*
 * columns): different coordinate system (metres, not 0..1) and table
 * identities (UUIDs minted by the editor, not tables.id).
 */
#[Fillable(['restaurant_id', 'schema_version', 'layout'])]
class RestaurantFloorLayout extends Model
{
    public const SCHEMA_VERSION = 1;

    // 3D editor vocabulary (note: 'rectangular', unlike the 2D Table::SHAPES).
    public const SHAPES = ['round', 'square', 'rectangular'];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
