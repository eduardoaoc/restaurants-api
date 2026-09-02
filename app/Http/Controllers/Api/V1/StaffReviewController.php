<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Staff\CreateStaffReviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Staff\StoreStaffReviewRequest;
use App\Http\Resources\Api\V1\Staff\StaffReviewResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StaffReviewController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateStaffReviewAction $createStaffReviewAction,
    ) {}

    /**
     * Create an internal review of an operational staff member. All
     * context beyond rating/comment (organization_id, restaurant_id,
     * staff_user_id, reviewer_user_id) is derived server-side — the
     * target's restaurant_id is always their own, never a value the
     * requester can influence. Self-review is rejected with 422.
     */
    #[OA\Post(
        path: '/api/v1/staff/{staff}/reviews',
        operationId: 'staffReviewStore',
        summary: 'Create an internal review of an operational staff member',
        security: [['sessionCookie' => []]],
        tags: ['Staff Reviews'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateStaffReviewRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Review created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Review created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'review', ref: '#/components/schemas/StaffReview'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to review this staff member'),
            new OA\Response(response: 404, description: 'Staff member not found'),
            new OA\Response(response: 422, description: 'Validation error, or the staff member cannot review themselves'),
        ]
    )]
    public function store(StoreStaffReviewRequest $request, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();

        $staffUser = $this->staffQuery($organization, $request->user())->findOrFail($staff);

        $this->authorize('manageReviews', [$staffUser, $organization]);

        $staffRestaurant = $staffUser->restaurants->first();

        $review = $this->createStaffReviewAction->execute(
            $organization,
            $staffRestaurant,
            $staffUser,
            $request->user(),
            (int) $request->validated('rating'),
            $request->validated('comment'),
        );

        $review->load('reviewer');

        return response()->json([
            'message' => 'Review created successfully.',
            'data' => [
                'review' => new StaffReviewResource($review),
            ],
        ], 201);
    }

    /**
     * List the reviews of an operational staff member, newest first. Even
     * the target staff member themselves needs manage_staff_reviews to see
     * this list — their redacted rating summary is available instead
     * through /me/performance.
     */
    #[OA\Get(
        path: '/api/v1/staff/{staff}/reviews',
        operationId: 'staffReviewIndex',
        summary: 'List the internal reviews of an operational staff member',
        security: [['sessionCookie' => []]],
        tags: ['Staff Reviews'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of reviews',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'reviews',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/StaffReview')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this staff member\'s reviews'),
            new OA\Response(response: 404, description: 'Staff member not found'),
        ]
    )]
    public function index(Request $request, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();

        $staffUser = $this->staffQuery($organization, $request->user())->findOrFail($staff);

        $this->authorize('manageReviews', [$staffUser, $organization]);

        $reviews = $staffUser->staffReviews()
            ->with('reviewer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'reviews' => StaffReviewResource::collection($reviews),
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
