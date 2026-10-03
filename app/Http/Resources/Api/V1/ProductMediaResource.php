<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes `disk`/`path` — only a ready-to-use URL. Shared by the
 * admin Product resource and the public menu's product resource: this
 * media is public-menu content, not an administrative-only detail.
 *
 * @mixin ProductMedia
 */
class ProductMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->resource->url(),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}
