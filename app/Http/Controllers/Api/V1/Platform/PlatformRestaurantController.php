<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\IndexPlatformRestaurantsRequest;
use App\Http\Requests\Api\V1\Platform\UpdatePlatformRestaurantStatusRequest;
use App\Http\Resources\Api\V1\Platform\PlatformRestaurantResource;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Support\Audit\PlatformAuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PlatformRestaurantController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(private readonly PlatformAuditLogger $auditLogger) {}

    #[OA\Get(
        path: '/api/v1/platform/restaurants',
        operationId: 'platformRestaurantsIndex',
        summary: 'List every restaurant on the platform, across every organization',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'organization_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'active')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of restaurants'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
        ]
    )]
    public function index(IndexPlatformRestaurantsRequest $request): JsonResponse
    {
        $this->authorize('platform.restaurants.view');

        $query = Restaurant::query()->with('organization');

        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->validated('organization_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $restaurants = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => [
                'restaurants' => PlatformRestaurantResource::collection($restaurants->items()),
            ],
            'meta' => $this->paginationMeta($restaurants),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/platform/restaurants/{restaurant}',
        operationId: 'platformRestaurantsShow',
        summary: 'Get one restaurant, regardless of organization',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The restaurant'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
        ]
    )]
    public function show(int $restaurant): JsonResponse
    {
        $this->authorize('platform.restaurants.view');

        $restaurantModel = Restaurant::query()->with('organization')->findOrFail($restaurant);

        return response()->json([
            'data' => [
                'restaurant' => new PlatformRestaurantResource($restaurantModel),
            ],
        ]);
    }

    /**
     * Restaurant suspension is state-only in this bloc: it records intent
     * and is protected against tenant self-reversal (see
     * RestaurantController::update), but does not itself block ordering,
     * kitchen, or any other operational flow — that enforcement belongs to
     * whichever future operational block owns each of those flows. See
     * the Bloco 0 report's Gaps section.
     */
    #[OA\Patch(
        path: '/api/v1/platform/restaurants/{restaurant}/status',
        operationId: 'platformRestaurantsUpdateStatus',
        summary: 'Suspend or reactivate a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'suspended'),
                    new OA\Property(property: 'reason', type: 'string', example: 'Health inspection failure reported by local authority.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Restaurant status updated successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateStatus(UpdatePlatformRestaurantStatusRequest $request, int $restaurant): JsonResponse
    {
        $this->authorize('platform.restaurants.manage');

        $restaurantModel = Restaurant::query()->findOrFail($restaurant);

        $oldStatus = $restaurantModel->status;
        $newStatus = $request->validated('status');

        $restaurantModel->update(['status' => $newStatus]);

        $this->auditLogger->log(
            actor: $request->user(),
            event: $newStatus === Restaurant::STATUS_SUSPENDED
                ? AuditLog::EVENT_PLATFORM_RESTAURANT_SUSPENDED
                : AuditLog::EVENT_PLATFORM_RESTAURANT_REACTIVATED,
            resourceType: AuditLog::RESOURCE_RESTAURANT,
            resourceId: $restaurantModel->id,
            organizationId: $restaurantModel->organization_id,
            restaurantId: $restaurantModel->id,
            reason: $request->validated('reason'),
            changes: ['status' => ['old' => $oldStatus, 'new' => $newStatus]],
        );

        return response()->json([
            'message' => 'Restaurant status updated successfully.',
            'data' => [
                'restaurant' => new PlatformRestaurantResource($restaurantModel->fresh(['organization'])),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
