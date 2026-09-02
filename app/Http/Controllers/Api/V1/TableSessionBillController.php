<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\RecordPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TableSessions\StorePaymentRequest;
use App\Http\Resources\Api\V1\PaymentRecordResource;
use App\Http\Resources\Api\V1\TableSessionBillResource;
use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TableSessionBillController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecordPaymentAction $recordPayment,
    ) {}

    /**
     * The computed bill for a table session — orders_total/paid_total/
     * balance/can_close, all derived from Orders' persisted totals and
     * PaymentRecords, never recalculated from the catalog. Works for
     * historical (closed) sessions too, not just the active one.
     */
    #[OA\Get(
        path: '/api/v1/table-sessions/{tableSession}/bill',
        operationId: 'tableSessionsBill',
        summary: "Get a table session's bill",
        security: [['sessionCookie' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The bill',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TableSessionBill')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this bill'),
            new OA\Response(response: 404, description: 'Table session not found'),
        ]
    )]
    public function show(Request $request, int $tableSession): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $session = $this->tableSessionQuery($organization, $user)
            ->with(['table', 'orders', 'paymentRecords.recordedBy'])
            ->findOrFail($tableSession);

        $this->authorize('viewBill', $session);

        $summary = SessionBillCalculator::summarize($session);

        return response()->json([
            'data' => new TableSessionBillResource(
                $session,
                Money::centsToDecimal($summary['ordersTotalCents']),
                Money::centsToDecimal($summary['paidTotalCents']),
                Money::centsToDecimal($summary['balanceCents']),
                SessionBillCalculator::canClose($session, $summary),
            ),
        ]);
    }

    /**
     * Record a manual payment (cash/card/other) against a table session's
     * bill. The frontend only sends intent (method/amount/reference/note)
     * — everything else (restaurant/table/session context, who recorded
     * it, currency, whether the bill is now fully paid) is derived by the
     * backend.
     */
    #[OA\Post(
        path: '/api/v1/table-sessions/{tableSession}/payments',
        operationId: 'tableSessionsPayments',
        summary: 'Record a manual payment against a table session',
        security: [['sessionCookie' => []]],
        tags: ['Billing'],
        parameters: [
            new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePaymentRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Payment recorded',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PaymentRecord')])
            ),
            new OA\Response(
                response: 200,
                description: 'Idempotent replay of a previously recorded payment',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PaymentRecord')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to record payments'),
            new OA\Response(response: 404, description: 'Table session not found'),
            new OA\Response(response: 409, description: 'The session is closed, already paid, has no billable orders, or the idempotency key was reused with a different payload'),
            new OA\Response(response: 422, description: 'Invalid payload, or the amount exceeds the current balance'),
        ]
    )]
    public function storePayment(StorePaymentRequest $request, int $tableSession): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $session = $this->tableSessionQuery($organization, $user)->findOrFail($tableSession);

        $this->authorize('recordPayment', $session);

        $result = $this->recordPayment->execute($session, $user, $request->validated());

        return response()->json([
            'data' => new PaymentRecordResource($result['payment']),
        ], $result['replayed'] ? 200 : 201);
    }

    /**
     * Resolve the active organization from the tenant context.
     */
    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    /**
     * Table sessions scoped to the active organization AND to the
     * restaurants the acting user may operate on. A session outside
     * either scope resolves as "not found" via findOrFail().
     */
    private function tableSessionQuery(Organization $organization, User $user): Builder
    {
        $query = TableSession::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }
}
