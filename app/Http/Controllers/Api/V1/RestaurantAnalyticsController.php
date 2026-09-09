<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\BuildRestaurantAnalyticsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Analytics\RestaurantAnalyticsResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The Analytics read model (Bloco 6) — historical/descriptive numbers for
 * an explicit period, feeding the dashboard's "Analysis" tab. Strictly
 * read-only: no writes, no AuditLog. Deliberately separate from
 * RestaurantDashboardController (untouched, still period-based but
 * timezone-naive/legacy-shaped — see the Bloco 6 report) and from
 * RestaurantOperationsController (Bloco 5 — current state, not history).
 */
class RestaurantAnalyticsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildRestaurantAnalyticsAction $buildAnalytics,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/analytics',
        operationId: 'restaurantAnalyticsShow',
        summary: "Get a restaurant's historical analytics for a period",
        security: [['sessionCookie' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Local calendar date (Y-m-d) in the restaurant\'s own timezone. Defaults, with `to`, to the current local month.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Local calendar date (Y-m-d), inclusive. Must be given together with `from`.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'granularity', in: 'query', required: false, description: 'One of: day, week, month. Defaults to day.', schema: new OA\Schema(type: 'string', example: 'day')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The analytics for the resolved period',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RestaurantAnalytics')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this restaurant\'s analytics'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Invalid period or granularity'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)
            ->findOrFail($restaurant);

        // Reuses view_reports — the exact same ability already gating
        // /dashboard — deliberately not a new permission (see the Bloco 6
        // report).
        $this->authorize('viewReports', $restaurantModel);

        $analytics = $this->buildAnalytics->execute(
            $restaurantModel,
            $request->query('from'),
            $request->query('to'),
            $request->query('granularity'),
        );

        return response()->json([
            'data' => new RestaurantAnalyticsResource($analytics),
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
