<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Activity\IndexRestaurantActivityRequest;
use App\Http\Requests\Api\V1\Activity\MarkRestaurantActivityReadRequest;
use App\Http\Resources\Api\V1\RestaurantActivityEventResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Support\Activity\RestaurantActivityReadState;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The operational activity feed (CARTA 6.1A) — "what happened" in one
 * restaurant, newest first, plus the requesting user's read cursor on it.
 * Distinct from the audit log (administrative/security trail, never
 * exposed here) and from /operations/live alerts (what needs attention
 * right now — the feed is history and never decides that).
 *
 * The restaurant always comes from the route, resolved through the
 * active organization + RestaurantScope (404 outside it), then
 * view_activity (403) — same pattern as RestaurantOperationsController.
 */
class RestaurantActivityController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RestaurantActivityReadState $readState,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/activity',
        operationId: 'restaurantActivityIndex',
        summary: "List a restaurant's operational activity feed",
        description: 'Newest first (by id). Cursor-paginated: pass meta.next_cursor back as ?cursor= to load older events — stable while new events keep arriving at the top (which offset pages would not be). New events arrive in realtime as `restaurant.activity.created` on the private `restaurant.{id}.activity` channel, carrying this exact item shape.',
        security: [['sessionCookie' => []]],
        tags: ['Activity'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['orders', 'service', 'billing', 'tables', 'menu'])),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(ref: '#/components/schemas/RestaurantActivityType')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'ISO 8601 instant, inclusive (occurred_at >= from).', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'ISO 8601 instant, exclusive (occurred_at < to). Must be after `from` when both are sent.', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'Opaque cursor from meta.next_cursor / meta.prev_cursor.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of activity events, newest first',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'activity', type: 'array', items: new OA\Items(ref: '#/components/schemas/RestaurantActivityEvent'))],
                            type: 'object'
                        ),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/RestaurantActivityMeta'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user lacks view_activity for this restaurant'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the active organization / the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Invalid filter, period or pagination value'),
        ]
    )]
    public function index(IndexRestaurantActivityRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->authorizedRestaurant($request, $restaurant);

        $query = RestaurantActivityEvent::query()->where('restaurant_id', $restaurantModel->id);

        if ($request->filled('category')) {
            $query->where('category', $request->validated('category'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->validated('type'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', Carbon::parse($request->validated('from'))->utc());
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<', Carbon::parse($request->validated('to'))->utc());
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);
        $events = $query->orderByDesc('id')->cursorPaginate($perPage);

        $lastReadEventId = $this->readState->lastReadEventId($restaurantModel, $request->user());

        return response()->json([
            'data' => [
                'activity' => RestaurantActivityEventResource::collection($events->items()),
            ],
            'meta' => [
                'per_page' => $events->perPage(),
                'next_cursor' => $events->nextCursor()?->encode(),
                'prev_cursor' => $events->previousCursor()?->encode(),
                'last_read_event_id' => $lastReadEventId,
                'unread_count' => $this->readState->unreadCount($restaurantModel, $lastReadEventId),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/activity/unread-count',
        operationId: 'restaurantActivityUnreadCount',
        summary: "The requesting user's unread activity count for a restaurant",
        description: 'Cheap badge endpoint. Unread = events of this restaurant with id > the user\'s own last_read_event_id (0 if never marked). Each user has an independent cursor per restaurant.',
        security: [['sessionCookie' => []]],
        tags: ['Activity'],
        parameters: [new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The read state',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RestaurantActivityReadState')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user lacks view_activity for this restaurant'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the active organization / the user\'s restaurant scope'),
        ]
    )]
    public function unreadCount(Request $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->authorizedRestaurant($request, $restaurant);

        return response()->json(['data' => $this->readStatePayload($restaurantModel, $request)]);
    }

    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/activity/read',
        operationId: 'restaurantActivityMarkRead',
        summary: 'Mark the activity feed as read up to an event',
        description: 'Moves the requesting user\'s read cursor forward to `last_event_id` (the newest event the client has shown), or to the restaurant\'s latest event when omitted. The cursor never moves backward. Only affects this user on this restaurant.',
        security: [['sessionCookie' => []]],
        tags: ['Activity'],
        parameters: [new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'last_event_id', type: 'integer', format: 'int64', example: 1842, description: 'Must be an event of this restaurant (422 otherwise).'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The updated read state',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RestaurantActivityReadState')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user lacks view_activity for this restaurant'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the active organization / the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'last_event_id is not an event of this restaurant'),
        ]
    )]
    public function markRead(MarkRestaurantActivityReadRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->authorizedRestaurant($request, $restaurant);

        if ($request->has('last_event_id')) {
            $upTo = (int) $request->validated('last_event_id');

            $belongsToRestaurant = RestaurantActivityEvent::query()
                ->where('restaurant_id', $restaurantModel->id)
                ->whereKey($upTo)
                ->exists();

            if (! $belongsToRestaurant) {
                throw ValidationException::withMessages(['last_event_id' => 'The selected last event id is invalid.']);
            }
        } else {
            $upTo = $this->readState->latestEventId($restaurantModel);
        }

        if ($upTo > 0) {
            $this->readState->markRead($restaurantModel, $request->user(), $upTo);
        }

        return response()->json(['data' => $this->readStatePayload($restaurantModel, $request)]);
    }

    /**
     * @return array{last_read_event_id: int, unread_count: int}
     */
    private function readStatePayload(Restaurant $restaurant, Request $request): array
    {
        $lastReadEventId = $this->readState->lastReadEventId($restaurant, $request->user());

        return [
            'last_read_event_id' => $lastReadEventId,
            'unread_count' => $this->readState->unreadCount($restaurant, $lastReadEventId),
        ];
    }

    private function authorizedRestaurant(Request $request, int $restaurant): Restaurant
    {
        $organization = Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($request->user(), $organization);

        $query = Restaurant::query()->where('organization_id', $organization->id);

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('id', $accessibleRestaurantIds);
        }

        $restaurantModel = $query->findOrFail($restaurant);

        $this->authorize('viewActivity', $restaurantModel);

        return $restaurantModel;
    }
}
