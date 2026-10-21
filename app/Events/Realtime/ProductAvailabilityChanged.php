<?php

namespace App\Events\Realtime;

/** Minimal invalidation on the existing private restaurant channel. No public payload. */
class ProductAvailabilityChanged extends RealtimeEvent
{
    public function __construct(int $restaurantId, public readonly int $restaurantProductId)
    {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'product.availability_changed';
    }

    protected function payload(): array
    {
        return ['restaurant_product_id' => $this->restaurantProductId];
    }
}
