<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Kitchen\KitchenOrdersRequest;
use App\Http\Resources\Api\V1\Kitchen\KitchenOrderResource;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class KitchenController extends Controller
{
    /**
     * Oldest first: the KDS is a queue, not a list.
     */
    private const ORDER_LIMIT = 100;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The Kitchen Display queue: confirmed/accepted/preparing/ready orders
     * only — never waiting_approval (not confirmed yet), cancelled, or
     * served (already handled). Scoped to the active organization and the
     * acting user's RestaurantScope; an out-of-scope ?restaurant_id
     * resolves as 404, exactly like every other operational endpoint.
     */
    #[OA\Get(
        path: '/api/v1/kitchen/orders',
        operationId: 'kitchenOrdersIndex',
        summary: 'List the Kitchen Display queue',
        security: [['sessionCookie' => []]],
        tags: ['Kitchen'],
        parameters: [
            new OA\Parameter(name: 'restaurant_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'preparing')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The kitchen queue, oldest order first',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'orders', type: 'array', items: new OA\Items(ref: '#/components/schemas/KitchenOrder')),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user has no kitchen-relevant permission'),
            new OA\Response(response: 404, description: 'restaurant_id is outside the organization or the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Invalid status filter'),
        ]
    )]
    public function orders(KitchenOrdersRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $this->authorize('viewKitchen', [Order::class, $organization]);

        $query = Order::query()
            ->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id))
            ->whereIn('status', Order::kitchenQueueStatuses());

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        if ($request->filled('restaurant_id')) {
            $restaurant = $this->restaurantQuery($organization, $restaurantIds)
                ->findOrFail((int) $request->validated('restaurant_id'));

            $query->where('restaurant_id', $restaurant->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        $orders = $query->with(['restaurant', 'table', 'items.modifiers'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::ORDER_LIMIT)
            ->get();

        return response()->json([
            'data' => ['orders' => KitchenOrderResource::collection($orders)],
        ]);
    }

    /**
     * Resolve the active organization from the tenant context.
     */
    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    /**
     * Restaurants scoped to the active organization AND to the given
     * accessible-restaurant-ids (null means "every restaurant of the
     * organization" — see RestaurantScope).
     *
     * @param  array<int, int>|null  $accessibleRestaurantIds
     */
    private function restaurantQuery(Organization $organization, ?array $accessibleRestaurantIds): Builder
    {
        $query = Restaurant::query()->where('organization_id', $organization->id);

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('id', $accessibleRestaurantIds);
        }

        return $query;
    }
}
