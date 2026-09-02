<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Staff\CreateStaffAction;
use App\Actions\Staff\UpdateStaffAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Staff\StoreStaffRequest;
use App\Http\Requests\Api\V1\Staff\UpdateStaffRequest;
use App\Http\Resources\Api\V1\StaffResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StaffController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateStaffAction $createStaffAction,
        private readonly UpdateStaffAction $updateStaffAction,
    ) {}

    /**
     * List the operational staff of the active organization.
     */
    #[OA\Get(
        path: '/api/v1/staff',
        operationId: 'staffIndex',
        summary: 'List operational staff of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of staff members',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'staff',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Staff')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage staff'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('viewAny', [User::class, $organization]);

        $staff = $this->staffQuery($organization, $request->user())->get();

        return response()->json([
            'data' => [
                'staff' => StaffResource::collection($staff),
            ],
        ]);
    }

    /**
     * Create an operational staff member under the active organization.
     */
    #[OA\Post(
        path: '/api/v1/staff',
        operationId: 'staffStore',
        summary: 'Create an operational staff member',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'restaurant_id', 'role', 'sub_id'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'TemporaryPassword123!'),
                    new OA\Property(property: 'restaurant_id', type: 'integer', example: 3),
                    new OA\Property(property: 'role', type: 'string', example: 'waiter'),
                    new OA\Property(property: 'sub_id', type: 'string', example: 'W-023'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Staff member created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Staff member created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'staff', ref: '#/components/schemas/Staff'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create staff'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        // Scope before permission: a restaurant outside the requester's
        // RestaurantScope resolves as 404, exactly like show()/update() —
        // it never even reaches the create permission check.
        $restaurant = $this->restaurantQuery($organization, $request->user())
            ->findOrFail($request->validated('restaurant_id'));

        $this->authorize('create', [User::class, $organization, $restaurant]);

        $staff = $this->createStaffAction->execute($organization, $request->validated(), $request->user());

        $staff = $this->staffQuery($organization, $request->user())->findOrFail($staff->id);

        return response()->json([
            'message' => 'Staff member created successfully.',
            'data' => [
                'staff' => new StaffResource($staff),
            ],
        ], 201);
    }

    /**
     * Show an operational staff member of the active organization.
     */
    #[OA\Get(
        path: '/api/v1/staff/{user}',
        operationId: 'staffShow',
        summary: 'Get an operational staff member of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The staff member',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'staff', ref: '#/components/schemas/Staff'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view staff'),
            new OA\Response(response: 404, description: 'Staff member not found'),
        ]
    )]
    public function show(Request $request, int $user): JsonResponse
    {
        $organization = $this->activeOrganization();

        $staff = $this->staffQuery($organization, $request->user())->findOrFail($user);

        $this->authorize('view', [$staff, $organization]);

        return response()->json([
            'data' => [
                'staff' => new StaffResource($staff),
            ],
        ]);
    }

    /**
     * Update an operational staff member of the active organization.
     */
    #[OA\Patch(
        path: '/api/v1/staff/{user}',
        operationId: 'staffUpdate',
        summary: 'Update an operational staff member',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
                    new OA\Property(property: 'restaurant_id', type: 'integer', example: 3),
                    new OA\Property(property: 'role', type: 'string', example: 'waiter'),
                    new OA\Property(property: 'sub_id', type: 'string', example: 'W-023'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Staff member updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Staff member updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'staff', ref: '#/components/schemas/Staff'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this staff member'),
            new OA\Response(response: 404, description: 'Staff member not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateStaffRequest $request, int $user): JsonResponse
    {
        $organization = $this->activeOrganization();

        $staff = $this->staffQuery($organization, $request->user())->findOrFail($user);

        $this->authorize('update', [$staff, $organization]);

        $staff = $this->updateStaffAction->execute($organization, $staff, $request->validated(), $request->user());

        $staff = $this->staffQuery($organization, $request->user())->findOrFail($staff->id);

        return response()->json([
            'message' => 'Staff member updated successfully.',
            'data' => [
                'staff' => new StaffResource($staff),
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
     * — a target staff member in another restaurant of the same
     * organization is out of this query entirely, yielding 404 (not 403)
     * via findOrFail, matching the convention used across every other
     * restaurant-scoped resource (Orders, TableRequests, StaffPerformance,
     * ...). An organization-wide requester (RestaurantScope returns null)
     * still sees every restaurant of the organization.
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
                'roles' => function ($query) use ($organization) {
                    $query->wherePivot('organization_id', $organization->id);
                },
            ]);
    }

    /**
     * Restaurants of the active organization reachable by the requester —
     * used by store() to resolve the target restaurant_id the same way
     * staffQuery() resolves an existing staff member: out of scope means
     * 404, before the create permission is even checked.
     */
    private function restaurantQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return Restaurant::query()
            ->where('organization_id', $organization->id)
            ->when(
                $accessibleRestaurantIds !== null,
                fn (Builder $query) => $query->whereIn('id', $accessibleRestaurantIds),
            );
    }
}
