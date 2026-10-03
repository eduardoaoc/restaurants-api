<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerFeedbackSummaryResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Feedback\CustomerFeedbackSummaryResolver;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Aggregate-only customer feedback numbers, never individual rows — the
 * PII-safe counterpart to CustomerFeedbackController (Passo 3.5 §12/§13).
 * me(): every authenticated staff member can see their own numbers, no
 * extra permission required — exactly like /me/performance. show(): an
 * owner/manager consulting another staff member's numbers, gated by
 * view_customer_feedback via StaffPolicy::viewFeedbackSummary.
 */
class CustomerFeedbackSummaryController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The authenticated user's own aggregate customer feedback — never a
     * user id, never individual feedback rows or PII.
     */
    #[OA\Get(
        path: '/api/v1/me/feedback-summary',
        operationId: 'meFeedbackSummary',
        summary: "Get the authenticated user's own aggregate customer feedback",
        security: [['sessionCookie' => []]],
        tags: ['Customer Feedback'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The aggregate feedback summary',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerFeedbackSummary')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $this->activeOrganization();

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $summary = CustomerFeedbackSummaryResolver::forWaiter($user->id, $restaurantIds);

        return response()->json([
            'data' => new CustomerFeedbackSummaryResource($summary),
        ]);
    }

    /**
     * An operational staff member's aggregate customer feedback for one
     * explicit Restaurant, as seen by an owner/manager. Requires
     * view_customer_feedback and that the requester can reach $restaurant
     * via RestaurantScope — see StaffPolicy::viewFeedbackSummary.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/staff/{staff}/feedback-summary',
        operationId: 'restaurantStaffFeedbackSummaryShow',
        summary: "Get a staff member's aggregate customer feedback for one restaurant",
        security: [['sessionCookie' => []]],
        tags: ['Customer Feedback'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The aggregate feedback summary',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerFeedbackSummary')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this staff member\'s feedback summary'),
            new OA\Response(response: 404, description: 'Restaurant not found, outside scope, or the staff member has no link to it'),
        ]
    )]
    public function show(Request $request, int $restaurant, int $staff): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);
        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)->findOrFail($restaurant);

        $staffUser = $this->staffQuery($restaurantModel)->findOrFail($staff);

        $this->authorize('viewFeedbackSummary', [$staffUser, $organization, $restaurantModel]);

        $summary = CustomerFeedbackSummaryResolver::forWaiter($staffUser->id, [$restaurantModel->id]);

        return response()->json([
            'data' => new CustomerFeedbackSummaryResource($summary),
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

    /**
     * Users linked to this specific restaurant — mirrors
     * StaffReviewController::staffQuery().
     */
    private function staffQuery(Restaurant $restaurant): Builder
    {
        return User::query()->whereHas('restaurants', function ($query) use ($restaurant) {
            $query->where('restaurants.id', $restaurant->id);
        });
    }
}
