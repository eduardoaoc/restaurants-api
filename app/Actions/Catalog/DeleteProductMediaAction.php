<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a Product's media slot. The DB row is removed before the file —
 * if the file delete then fails or the file is already gone, we're left
 * with an orphaned blob (harmless) rather than a DB row pointing at
 * nothing (a broken URL). Both disks used here have `throw => false`, so
 * deleting an already-missing file is a no-op, not an exception.
 */
class DeleteProductMediaAction
{
    public function execute(Product $product, string $type): void
    {
        $media = $product->media()->where('type', $type)->first();

        if (! $media) {
            return;
        }

        $media->delete();

        Storage::disk($media->disk)->delete($media->path);
    }
}
