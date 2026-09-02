<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Printing\OrderNotPrintableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Printing\KitchenTicketResource;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PrintRecord;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class KitchenTicketController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Preview the kitchen ticket document for an order. A GET: read-only,
     * no PrintRecord is created — see report ("preview vs print").
     */
    #[OA\Get(
        path: '/api/v1/orders/{order}/kitchen-ticket',
        operationId: 'ordersKitchenTicketShow',
        summary: 'Preview the kitchen ticket document for an order',
        security: [['sessionCookie' => []]],
        tags: ['Printing'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The kitchen ticket document', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/KitchenTicket')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this order\'s kitchen ticket'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order is waiting_approval or cancelled and cannot be printed'),
        ]
    )]
    public function show(Request $request, int $order): JsonResponse
    {
        $orderModel = $this->resolveOrder($request, $order);

        return response()->json(['data' => new KitchenTicketResource($orderModel)]);
    }

    /**
     * Register a manual kitchen ticket print request and return the same
     * document. A POST: creates a PrintRecord (a side effect) — repeatable
     * for reprints, each call adding a new record.
     */
    #[OA\Post(
        path: '/api/v1/orders/{order}/kitchen-ticket/print',
        operationId: 'ordersKitchenTicketPrint',
        summary: 'Record a manual kitchen ticket print request',
        security: [['sessionCookie' => []]],
        tags: ['Printing'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'Print request recorded', content: new OA\JsonContent(ref: '#/components/schemas/PrintRequestResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to print this order\'s kitchen ticket'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 409, description: 'The order is waiting_approval or cancelled and cannot be printed'),
        ]
    )]
    public function print(Request $request, int $order): JsonResponse
    {
        $orderModel = $this->resolveOrder($request, $order);
        $user = $request->user();

        $printRecord = PrintRecord::query()->create([
            'organization_id' => $this->activeOrganization()->id,
            'restaurant_id' => $orderModel->restaurant_id,
            'document_type' => PrintRecord::DOCUMENT_TYPE_KITCHEN_TICKET,
            'order_id' => $orderModel->id,
            'table_session_id' => $orderModel->table_session_id,
            'requested_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'print_record_id' => $printRecord->id,
                'document' => new KitchenTicketResource($orderModel),
            ],
        ], 201);
    }

    /**
     * Resolve, authorize, and validate the printability of an order,
     * shared by preview and print — they use the exact same authorization
     * (see OrderPolicy::viewKitchenTicket()), never "GET allowed, POST
     * denied" for the same user/order.
     */
    private function resolveOrder(Request $request, int $orderId): Order
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $orderModel = $this->orderQuery($organization, $user)
            ->with(['restaurant', 'table', 'items.modifiers'])
            ->findOrFail($orderId);

        $this->authorize('viewKitchenTicket', $orderModel);

        if (! in_array($orderModel->status, Order::printableStatuses(), true)) {
            throw new OrderNotPrintableException;
        }

        return $orderModel;
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
}
