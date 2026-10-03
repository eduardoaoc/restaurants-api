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
     * Scope (Bloco 18):
     *   - Organization-wide caller (owner-like): "organization" — every
     *     restaurant of the active organization.
     *   - Operational staff with exactly one restaurant: "restaurant".
     *   - Operational staff with 2+ restaurants (Carlos -> A+B):
     *     "assigned_restaurants" — every restaurant they hold a
     *     restaurant_users row for, never restaurants outside that set.
     *   - Any of the above narrowed to one explicit restaurant via
     *     ?restaurant_id=: "restaurant". A restaurant_id the caller cannot
     *     reach via their own RestaurantScope is 404 — this endpoint never
     *     widens what the caller could already see.
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
            new OA\Parameter(name: 'restaurant_id', in: 'query', required: false, description: 'Restrict to one explicit restaurant the caller can reach.', schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 404, description: 'restaurant_id is outside the caller\'s own restaurant scope'),
            new OA\Response(response: 422, description: 'Invalid performance period'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $this->activeOrganization();

        $accessibleIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($accessibleIds === null) {
            $orgRestaurantIds = Restaurant::query()->where('organization_id', $organization->id)->pluck('id')->all();
            $scope = 'organization';
            $restaurantIds = $orgRestaurantIds;
            $restaurant = null;
        } else {
            $scope = count($accessibleIds) > 1 ? 'assigned_restaurants' : 'restaurant';
            $restaurantIds = $accessibleIds;
            $restaurant = $accessibleIds !== [] ? Restaurant::query()->whereKey($accessibleIds[0])->first() : null;
        }

        if ($request->filled('restaurant_id')) {
            $requestedId = (int) $request->query('restaurant_id');

            if (! in_array($requestedId, $restaurantIds, true)) {
                abort(404);
            }

            $scope = 'restaurant';
            $restaurantIds = [$requestedId];
            $restaurant = Restaurant::query()->whereKey($requestedId)->first();
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
     * An operational staff member's performance for one explicit
     * Restaurant, as seen by an administrator. Requires view_reports and
     * that the requester can reach $restaurant via RestaurantScope; the
     * Restaurant itself is resolved scoped to the active organization
     * first — a Restaurant outside the organization is 404 before the
     * staff lookup even runs.
     *
     * The target's scope is always "restaurant" and always exactly this
     * one Restaurant — even for a staff member assigned to several, and
     * even when the requester is an organization-wide owner who could
     * reach every one of them: metrics never aggregate across the
     * target's other restaurants.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/staff/{staff}/performance',
        operationId: 'restaurantStaffPerformanceShow',
        summary: 'Get an operational staff member\'s performance for one restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Staff Performance'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 404, description: 'Restaurant not found, outside scope, or the staff member has no link to it'),
            new OA\Response(response: 422, description: 'Invalid performance period'),
        ]
    )]
    public function show(Request $request, int $restaurant, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);
        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)->findOrFail($restaurant);

        $staffUser = $this->staffQuery($restaurantModel)->findOrFail($staff);

        $this->authorize('viewPerformance', [$staffUser, $organization, $restaurantModel]);

        $period = PerformancePeriodResolver::resolve($request->query('from'), $request->query('to'));

        $metrics = $this->performanceService->metrics([$restaurantModel->id], $staffUser->id, $period['from'], $period['toExclusive']);
        $rating = $this->performanceService->rating([$restaurantModel->id], $organization->id, $staffUser->id, $period['from'], $period['toExclusive']);

        return response()->json([
            'data' => [
                'performance' => new StaffPerformanceResource(
                    $staffUser,
                    $restaurantModel,
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
     * Restaurants of the active organization reachable by the requester —
     * an out-of-scope restaurant resolves as 404 via findOrFail, before
     * the staff lookup or the permission check ever run.
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

    /**
     * Users linked to this specific restaurant — a staff member assigned
     * to other restaurants but not this one is out of this query entirely,
     * yielding 404 via findOrFail.
     */
    private function staffQuery(Restaurant $restaurant): Builder
    {
        return User::query()->whereHas('restaurants', function ($query) use ($restaurant) {
            $query->where('restaurants.id', $restaurant->id);
        });
    }
}
