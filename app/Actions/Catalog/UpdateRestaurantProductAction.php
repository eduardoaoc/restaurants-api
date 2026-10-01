<?php

namespace App\Actions\Catalog;

use App\Models\RestaurantProduct;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use Illuminate\Support\Facades\DB;

/**
 * Updates a restaurant's product (price/availability). An actual change of
 * `available` is an operational fact the floor cares about ("product
 * marked as unavailable"), so it is recorded in the activity feed (CARTA
 * 6.1A) in the same transaction. This is availability, not stock — AFORO
 * has no quantity/stock module. A price-only change, or re-sending the
 * same `available` value, records nothing.
 */
class UpdateRestaurantProductAction
{
    public function __construct(private readonly RestaurantActivityRecorder $activityRecorder) {}

    /**
     * @param  array{price?: mixed, available?: bool}  $data
     */
    public function execute(RestaurantProduct $restaurantProduct, User $actor, array $data): RestaurantProduct
    {
        return DB::transaction(function () use ($restaurantProduct, $actor, $data) {
            $restaurantProduct->update($data);

            if ($restaurantProduct->wasChanged('available')) {
                $this->activityRecorder->record(
                    restaurantId: $restaurantProduct->restaurant_id,
                    type: $restaurantProduct->available
                        ? RestaurantActivityType::PRODUCT_MARKED_AVAILABLE
                        : RestaurantActivityType::PRODUCT_MARKED_UNAVAILABLE,
                    actor: ActivityActor::staff($actor),
                    metadata: [
                        'restaurant_product_id' => $restaurantProduct->id,
                        'product_name' => $restaurantProduct->product->internal_name,
                    ],
                );
            }

            return $restaurantProduct;
        });
    }
}
