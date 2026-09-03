<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Staff\CreateStaffReviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Staff\StoreStaffReviewRequest;
use App\Http\Resources\Api\V1\Staff\StaffReviewResource;
use App\Models\Organization;
use App\Models\Restaurant;
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
     * Create an internal review of an operational staff member for one
     * explicit Restaurant. All context beyond rating/comment
     * (organization_id, restaurant_id, staff_user_id, reviewer_user_id) is
     * derived server-side from the route — restaurant_id always comes from
     * the URL, never the request body, and the target's restaurant is
     * always this one, never "their first restaurant" (Bloco 18: a staff
     * member may have several). Self-review is rejected with 422.
     */
    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/staff/{staff}/reviews',
        operationId: 'restaurantStaffReviewStore',
        summary: 'Create an internal review of an operational staff member for one restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Staff Reviews'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 404, description: 'Restaurant not found, outside scope, or the staff member has no link to it'),
            new OA\Response(response: 422, description: 'Validation error, or the staff member cannot review themselves'),
        ]
    )]
    public function store(StoreStaffReviewRequest $request, int $restaurant, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);
        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)->findOrFail($restaurant);

        $staffUser = $this->staffQuery($restaurantModel)->findOrFail($staff);

        $this->authorize('manageReviews', [$staffUser, $organization, $restaurantModel]);

        $review = $this->createStaffReviewAction->execute(
            $organization,
            $restaurantModel,
            $staffUser,
            $user,
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
     * List the reviews of an operational staff member for one explicit
     * Restaurant, newest first. Even the target staff member themselves
     * needs manage_staff_reviews to see this list — their redacted rating
     * summary is available instead through /me/performance.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/staff/{staff}/reviews',
        operationId: 'restaurantStaffReviewIndex',
        summary: 'List the internal reviews of an operational staff member for one restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Staff Reviews'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 404, description: 'Restaurant not found, outside scope, or the staff member has no link to it'),
        ]
    )]
    public function index(Request $request, int $restaurant, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);
        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)->findOrFail($restaurant);

        $staffUser = $this->staffQuery($restaurantModel)->findOrFail($staff);

        $this->authorize('manageReviews', [$staffUser, $organization, $restaurantModel]);

        $reviews = $staffUser->staffReviews()
            ->where('restaurant_id', $restaurantModel->id)
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
