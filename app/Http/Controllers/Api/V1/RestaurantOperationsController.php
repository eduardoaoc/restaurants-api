<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Operations\BuildRestaurantOperationsSnapshotAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\OperationsLiveResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The Operations Live snapshot (Bloco 5) — "what is the operational state
 * of this restaurant RIGHT NOW". Strictly read-only: no status write, no
 * AuditLog, no side effect of any kind — see
 * BuildRestaurantOperationsSnapshotAction. Deliberately separate from
 * RestaurantDashboardController (historical/period analytics, untouched
 * by this block).
 */
class RestaurantOperationsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildRestaurantOperationsSnapshotAction $buildSnapshot,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/operations/live',
        operationId: 'restaurantOperationsLive',
        summary: "Get a restaurant's live operations snapshot",
        security: [['sessionCookie' => []]],
        tags: ['Operations'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The live operations snapshot',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/OperationsLiveSnapshot'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this restaurant\'s live operations'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function live(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)
            ->findOrFail($restaurant);

        $this->authorize('viewOperations', $restaurantModel);

        $snapshot = $this->buildSnapshot->execute($restaurantModel);

        return response()->json([
            'data' => new OperationsLiveResource($snapshot),
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
     * organization" — see RestaurantScope). Same pattern as
     * RestaurantDashboardController::restaurantQuery().
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
