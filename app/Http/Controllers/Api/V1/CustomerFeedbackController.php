<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerFeedback\IndexCustomerFeedbackRequest;
use App\Http\Resources\Api\V1\CustomerFeedbackListItemResource;
use App\Http\Resources\Api\V1\CustomerFeedbackResource;
use App\Models\CustomerFeedback;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Owner/Manager access to customer post-visit feedback (Passo 3.5) —
 * gated by view_customer_feedback, never by role string. A waiter's own
 * aggregate is served by a separate, PII-free endpoint — see
 * CustomerFeedbackSummaryController.
 */
class CustomerFeedbackController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * List customer feedback for one explicit Restaurant, newest first —
     * minimal fields only (date, customer name, table, the four ratings,
     * related waiter). Use show() for the full detail.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/feedback',
        operationId: 'restaurantCustomerFeedbackIndex',
        summary: 'List customer post-visit feedback for one restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Customer Feedback'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of customer feedback, newest first',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'feedback', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerFeedbackListItem'))],
                            type: 'object'
                        ),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/AuditLogPaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this restaurant\'s customer feedback'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Invalid pagination parameters'),
        ]
    )]
    public function index(IndexCustomerFeedbackRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);
        $restaurantModel = $this->restaurantQuery($organization, $accessibleRestaurantIds)->findOrFail($restaurant);

        $this->authorize('viewAny', [CustomerFeedback::class, $organization, $restaurantModel]);

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $feedback = CustomerFeedback::query()
            ->where('restaurant_id', $restaurantModel->id)
            ->with(['tableSession.table', 'waiter'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'feedback' => CustomerFeedbackListItemResource::collection($feedback->items()),
            ],
            'meta' => [
                'current_page' => $feedback->currentPage(),
                'per_page' => $feedback->perPage(),
                'total' => $feedback->total(),
                'last_page' => $feedback->lastPage(),
            ],
        ]);
    }

    /**
     * Full detail of one customer feedback — every field, including
     * comments/contact. RestaurantScope applies: feedback outside the
     * requester's reach resolves as 404, not 403.
     */
    #[OA\Get(
        path: '/api/v1/feedback/{customerFeedback}',
        operationId: 'customerFeedbackShow',
        summary: 'Get full customer feedback detail',
        security: [['sessionCookie' => []]],
        tags: ['Customer Feedback'],
        parameters: [new OA\Parameter(name: 'customerFeedback', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The customer feedback',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerFeedback')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this feedback'),
            new OA\Response(response: 404, description: 'Feedback not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function show(Request $request, int $customerFeedback): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $feedback = $this->feedbackQuery($organization, $user)
            ->with(['restaurant', 'tableSession.table', 'waiter'])
            ->findOrFail($customerFeedback);

        $this->authorize('view', $feedback);

        return response()->json([
            'data' => new CustomerFeedbackResource($feedback),
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
     * Feedback scoped to the active organization AND to the restaurants
     * the acting user may operate on. Feedback outside either scope
     * resolves as "not found" via findOrFail().
     */
    private function feedbackQuery(Organization $organization, User $user): Builder
    {
        $query = CustomerFeedback::query()->where('organization_id', $organization->id);

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }
}
