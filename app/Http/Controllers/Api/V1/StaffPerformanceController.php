<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Staff\StaffPerformanceResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Staff\PerformancePeriodResolver;
use App\Support\Staff\StaffPerformanceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StaffPerformanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StaffPerformanceService $performanceService,
    ) {}

    /**
     * The authenticated user's own operational performance. Never accepts
     * a user/staff id and requires no permission beyond authentication +
     * an active organization — everyone can see their own numbers.
     *
     * When the caller has an organization-wide role (owner-like), scope is
     * "organization" and metrics aggregate their own actions across every
     * restaurant of the active organization only — never across other
     * organizations, and never another staff member's actions.
     */
    #[OA\Get(
        path: '/api/v1/me/performance',
        operationId: 'mePerformance',
        summary: "Get the authenticated user's own operational performance",
        security: [['sessionCookie' => []]],
        tags: ['Staff Performance'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The performance summary',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'performance', ref: '#/components/schemas/StaffPerformance'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid performance period'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $this->activeOrganization();

        $accessibleIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($accessibleIds === null) {
            $scope = 'organization';
            $restaurantIds = Restaurant::query()->where('organization_id', $organization->id)->pluck('id')->all();
            $restaurant = null;
        } else {
            $scope = 'restaurant';
            $restaurantIds = $accessibleIds;
            $restaurant = Restaurant::query()->whereIn('id', $accessibleIds)->first();
        }

        $period = PerformancePeriodResolver::resolve($request->query('from'), $request->query('to'));

        $metrics = $this->performanceService->metrics($restaurantIds, $user->id, $period['from'], $period['toExclusive']);
        $rating = $this->performanceService->rating($restaurantIds, $organization->id, $user->id, $period['from'], $period['toExclusive']);

        return response()->json([
            'data' => [
                'performance' => new StaffPerformanceResource(
                    $user,
                    $restaurant,
                    $scope,
                    ['from' => $period['fromLabel'], 'to' => $period['toLabel']],
                    $metrics,
                    $rating,
                ),
            ],
        ]);
    }

    /**
     * An operational staff member's performance, as seen by an
     * administrator. Requires view_reports and RestaurantScope
     * reachability of the target's own restaurant (via StaffPolicy). The
     * target's scope is always "restaurant" — never aggregated across
     * restaurants, regardless of the requester's own scope.
     */
    #[OA\Get(
        path: '/api/v1/staff/{staff}/performance',
        operationId: 'staffPerformanceShow',
        summary: 'Get an operational staff member\'s performance',
        security: [['sessionCookie' => []]],
        tags: ['Staff Performance'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The performance summary',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'performance', ref: '#/components/schemas/StaffPerformance'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this staff member\'s performance'),
            new OA\Response(response: 404, description: 'Staff member not found'),
            new OA\Response(response: 422, description: 'Invalid performance period'),
        ]
    )]
    public function show(Request $request, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();

        $staffUser = $this->staffQuery($organization, $request->user())->findOrFail($staff);

        $this->authorize('viewPerformance', [$staffUser, $organization]);

        $restaurant = $staffUser->restaurants->first();
        $restaurantIds = $restaurant ? [$restaurant->id] : [];

        $period = PerformancePeriodResolver::resolve($request->query('from'), $request->query('to'));

        $metrics = $this->performanceService->metrics($restaurantIds, $staffUser->id, $period['from'], $period['toExclusive']);
        $rating = $this->performanceService->rating($restaurantIds, $organization->id, $staffUser->id, $period['from'], $period['toExclusive']);

        return response()->json([
            'data' => [
                'performance' => new StaffPerformanceResource(
                    $staffUser,
                    $restaurant,
                    'restaurant',
                    ['from' => $period['fromLabel'], 'to' => $period['toLabel']],
                    $metrics,
                    $rating,
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
     * Users linked to a restaurant of the active organization, i.e.
     * operational staff. The owner never appears here: it has no
     * restaurant_users row.
     *
     * Restricted to restaurants the requester can reach via RestaurantScope
     * — a target in another restaurant of the same organization is out of
     * this query entirely, yielding 404 (not 403) via findOrFail, matching
     * the convention used across every other restaurant-scoped resource.
     * The RestaurantScope check in StaffPolicy is then redundant for this
     * query but remains as the single source of truth for authorization.
     */
    private function staffQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return User::query()
            ->whereHas('restaurants', function ($query) use ($organization, $accessibleRestaurantIds) {
                $query->where('restaurants.organization_id', $organization->id);

                if ($accessibleRestaurantIds !== null) {
                    $query->whereIn('restaurants.id', $accessibleRestaurantIds);
                }
            })
            ->with([
                'restaurants' => function ($query) use ($organization) {
                    $query->where('restaurants.organization_id', $organization->id);
                },
            ]);
    }
}
