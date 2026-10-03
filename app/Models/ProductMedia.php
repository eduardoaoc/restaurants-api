<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['product_id', 'type', 'disk', 'path', 'mime_type', 'size_bytes'])]
class ProductMedia extends Model
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /**
     * @var array<int, string>
     */
    public const TYPES = [self::TYPE_IMAGE, self::TYPE_VIDEO];

    /**
     * Content-sniffed (never client/extension-trusted) MIME types accepted
     * per slot, and the canonical, safe extension each maps to for the
     * generated storage filename — see StoreOrReplaceProductMediaAction.
     *
     * @var array<string, array<string, string>>
     */
    public const ACCEPTED_MIME_TYPES = [
        self::TYPE_IMAGE => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
        self::TYPE_VIDEO => [
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ],
    ];

    public const MAX_IMAGE_KILOBYTES = 10 * 1024;

    public const MAX_VIDEO_KILOBYTES = 50 * 1024;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A browser-usable URL on whichever disk this file actually lives on —
     * never the disk name or internal path (see ProductMediaResource).
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
