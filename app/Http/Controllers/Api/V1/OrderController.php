<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Orders\CreateStaffOrderAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\StoreStaffOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Table;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateStaffOrderAction $createStaffOrder,
        private readonly ApproveOrderAction $approveOrder,
        private readonly RejectOrderAction $rejectOrder,
        private readonly TransitionOrderStatusAction $transitionOrderStatus,
    ) {}

    /**
     * Launch an order on a table, on behalf of the restaurant's staff. The
     * order is created already `confirmed`: the waiter placing it does not
     * need to approve their own order.
     */
    #[OA\Post(
        path: '/api/v1/tables/{table}/orders',
        operationId: 'ordersStore',
        summary: "Create an order on a table (waiter's own order)",
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'locale', type: 'string', example: 'es'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItemCreateRequest')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Order created successfully.'),
                        new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create orders on this table'),
            new OA\Response(response: 404, description: 'Table not found'),
            new OA\Response(response: 409, description: 'The table has no active session'),
            new OA\Response(response: 422, description: 'Invalid item/modifier selection or malformed request'),
        ]
    )]
    public function store(StoreStaffOrderRequest $request, int $table): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $tableModel = $this->tableQuery($organization, $user)->findOrFail($table);

        $this->authorize('create', [Order::class, $tableModel]);

        $order = $this->createStaffOrder->execute($tableModel, $user, $request->validated());

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => ['order' => new OrderResource($order)],
        ], 201);
    }

    /**
     * List orders visible to the active organization, scoped to the
     * restaurants the acting user may operate on (see RestaurantScope).
     */
    #[OA\Get(
        path: '/api/v1/orders',
        operationId: 'ordersIndex',
        summary: 'List orders',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'restaurant_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'table_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'waiting_approval')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of orders',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'orders', type: 'array', items: new OA\Items(ref: '#/components/schemas/Order'))],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view orders'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $this->authorize('viewAny', [Order::class, $organization]);

        $query = $this->orderQuery($organization, $user);

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', (int) $request->query('restaurant_id'));
        }
        if ($request->filled('table_id')) {
            $query->where('table_id', (int) $request->query('table_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $orders = $query->with(['restaurant', 'table', 'items.modifiers'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => ['orders' => OrderResource::collection($orders)],
        ]);
    }

    /**
     * Show a single order, scoped to the active organization and the
     * acting user's restaurant scope.
     */
    #[OA\Get(
        path: '/api/v1/orders/{order}',
        operationId: 'ordersShow',
        summary: 'Get an order',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The order',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this order'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function show(Request $request, int $order): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $orderModel = $this->orderQuery($organization, $user)
            ->with(['restaurant', 'table', 'items.modifiers'])
            ->findOrFail($order);

        $this->authorize('view', $orderModel);

        return response()->json([
            'data' => ['order' => new OrderResource($orderModel)],
        ]);
    }

    /**
     * Approve a customer_qr order that is waiting_approval.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/approve',
        operationId: 'ordersApprove',
        summary: 'Approve a customer order',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order approved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Order approved successfully.'),
                        new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to approve orders'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order cannot be approved in its current state'),
        ]
    )]
    public function approve(Request $request, int $order): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $orderModel = $this->orderQuery($organization, $user)->findOrFail($order);

        $this->authorize('approve', $orderModel);

        $updated = $this->approveOrder->execute($orderModel, $user);

        return response()->json([
            'message' => 'Order approved successfully.',
            'data' => ['order' => new OrderResource($updated)],
        ]);
    }

    /**
     * Reject (cancel) a customer_qr order that is waiting_approval.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/reject',
        operationId: 'ordersReject',
        summary: 'Reject a customer order',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order rejected successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Order rejected successfully.'),
                        new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to reject orders'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order cannot be rejected in its current state'),
        ]
    )]
    public function reject(Request $request, int $order): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $orderModel = $this->orderQuery($organization, $user)->findOrFail($order);

        $this->authorize('reject', $orderModel);

        $updated = $this->rejectOrder->execute($orderModel, $user);

        return response()->json([
            'message' => 'Order rejected successfully.',
            'data' => ['order' => new OrderResource($updated)],
        ]);
    }

    /**
     * Kitchen accepts a confirmed order: confirmed -> accepted.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/accept',
        operationId: 'ordersAccept',
        summary: 'Kitchen accepts a confirmed order',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Order accepted successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to accept orders'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order is not confirmed'),
        ]
    )]
    public function accept(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'accept', fn ($o, $u) => $this->transitionOrderStatus->accept($o, $u), 'Order accepted successfully.');
    }

    /**
     * Kitchen starts preparing an accepted order: accepted -> preparing.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/preparing',
        operationId: 'ordersPreparing',
        summary: 'Kitchen starts preparing an accepted order',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Order marked as preparing', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this order'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order is not accepted'),
        ]
    )]
    public function preparing(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'prepare', fn ($o, $u) => $this->transitionOrderStatus->startPreparing($o, $u), 'Order marked as preparing.');
    }

    /**
     * Kitchen marks an order ready: preparing -> ready.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/ready',
        operationId: 'ordersReady',
        summary: 'Kitchen marks an order ready',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Order marked as ready', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this order'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order is not preparing'),
        ]
    )]
    public function ready(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'markReady', fn ($o, $u) => $this->transitionOrderStatus->markReady($o, $u), 'Order marked as ready.');
    }

    /**
     * Waiter serves a ready order to the customer: ready -> served. Does
     * not touch the TableSession — a session outlives many orders and is
     * never closed as a side effect of one being served.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/served',
        operationId: 'ordersServed',
        summary: 'Waiter marks an order as served',
        security: [['sessionCookie' => []]],
        tags: ['Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Order marked as served', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'order', ref: '#/components/schemas/Order')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to serve orders'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order is not ready'),
        ]
    )]
    public function served(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'serve', fn ($o, $u) => $this->transitionOrderStatus->serve($o, $u), 'Order marked as served.');
    }

    /**
     * Shared plumbing for the four lifecycle transition endpoints: resolve
     * within tenant + restaurant scope (404 if outside it), authorize the
     * specific ability (403 if in scope but unauthorized), then run the
     * transition (409 on an invalid state change).
     */
    private function transition(Request $request, int $orderId, string $ability, callable $run, string $message): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $orderModel = $this->orderQuery($organization, $user)->findOrFail($orderId);

        $this->authorize($ability, $orderModel);

        $updated = $run($orderModel, $user);

        return response()->json([
            'message' => $message,
            'data' => ['order' => new OrderResource($updated)],
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
     * Orders scoped to the active organization AND to the restaurants the
     * acting user may operate on. An order outside either scope resolves
     * as "not found" via findOrFail() — see RestaurantScope.
     */
    private function orderQuery(Organization $organization, User $user): Builder
    {
        $query = Order::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }

    /**
     * Tables scoped to the active organization AND to the restaurants the
     * acting user may operate on (same reasoning as orderQuery()).
     */
    private function tableQuery(Organization $organization, User $user): Builder
    {
        $query = Table::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query->with('activeSession');
    }
}
