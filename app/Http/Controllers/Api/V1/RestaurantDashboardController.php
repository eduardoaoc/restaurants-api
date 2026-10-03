<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RestaurantDashboardResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Support\Reports\ReportPeriodResolver;
use App\Support\Reports\RestaurantDashboardService;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RestaurantDashboardController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RestaurantDashboardService $dashboardService,
    ) {}

    /**
     * The operational dashboard of one explicit restaurant of the active
     * organization. Restaurant resolution is scoped by RestaurantScope
     * before authorization runs: a restaurant outside the requester's
     * scope (a manager's other restaurant, or another organization's
     * restaurant entirely) resolves as 404 via findOrFail, never 403 — the
     * same convention used across every other restaurant-scoped resource
     * (see StaffPerformanceController, KitchenController). An owner
     * (organization-wide role) can reach every restaurant of the active
     * organization, but the dashboard itself is always of exactly one
     * restaurant — numbers never aggregate across restaurants.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/dashboard',
        operationId: 'restaurantDashboardShow',
        summary: "Get a restaurant's operational dashboard",
        security: [['sessionCookie' => []]],
        tags: ['Restaurant Dashboard'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The restaurant dashboard',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'dashboard', ref: '#/components/schemas/RestaurantDashboard'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this restaurant\'s reports'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Invalid report period'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)
            ->findOrFail($restaurant);

        $this->authorize('viewReports', $restaurantModel);

        $period = ReportPeriodResolver::resolve($request->query('from'), $request->query('to'));

        $summary = $this->dashboardService->summarize($restaurantModel, $period['from'], $period['toExclusive']);

        return response()->json([
            'data' => [
                'dashboard' => new RestaurantDashboardResource(
                    $restaurantModel,
                    ['from' => $period['fromLabel'], 'to' => $period['toLabel']],
                    $summary,
                ),
            ],
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
     * KitchenController::restaurantQuery().
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
