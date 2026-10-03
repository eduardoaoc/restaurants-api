<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Uploads a new image/video into a Product's media slot, replacing
 * whatever was there before. Order matters (see class body): the new file
 * is stored and the DB row is committed BEFORE the old file is deleted, so
 * a mid-upload failure never leaves a product without working media.
 */
class StoreOrReplaceProductMediaAction
{
    public function execute(Product $product, string $type, UploadedFile $file): ProductMedia
    {
        $mimeType = $file->getMimeType();
        $extension = ProductMedia::ACCEPTED_MIME_TYPES[$type][$mimeType] ?? null;

        if ($extension === null) {
            // The FormRequest already validates this — reaching here means
            // a caller bypassed it, which is a programming error, not user input.
            throw new RuntimeException("Unsupported {$type} MIME type: {$mimeType}.");
        }

        $disk = config('media.product_disk');
        $directory = "products/{$product->organization_id}/{$product->id}/{$type}";
        $filename = Str::uuid()->toString().'.'.$extension;

        $storedPath = $file->storeAs($directory, $filename, $disk);

        $existing = $product->media()->where('type', $type)->first();

        try {
            $media = $product->media()->updateOrCreate(
                ['type' => $type],
                [
                    'disk' => $disk,
                    'path' => $storedPath,
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize(),
                ],
            );
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($storedPath);

            throw $e;
        }

        if ($existing) {
            Storage::disk($existing->disk)->delete($existing->path);
        }

        return $media;
    }
}
