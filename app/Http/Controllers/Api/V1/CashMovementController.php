<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cash\RecordCashMovementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DayClose\StoreCashMovementRequest;
use App\Http\Resources\Api\V1\DayClose\CashMovementResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantCashMovement;
use App\Models\User;
use App\Support\DayClose\DayClosePeriodResolver;
use App\Support\Money\Money;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Cash drawer pay-ins/pay-outs (CARTA 9.1A). Same capability as running
 * the Cierre Diario (close_daily_operation) — they only exist to make the
 * day's expected cash right. Kitchen never holds it.
 */
class CashMovementController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecordCashMovementAction $recordCashMovement,
        private readonly DayClosePeriodResolver $periodResolver,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/cash-movements',
        operationId: 'cashMovementsIndex',
        summary: 'List the cash movements of the current (not yet closed) Cierre Diario period',
        description: 'Returns every pay-in/pay-out with recorded_at >= the current period start (the previous close\'s period_ended_at, or the cutoff of today\'s business date on the first close), oldest first, plus their totals.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cash movements of the current period',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'period_started_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'pay_ins_total', type: 'string', example: '20.00'),
                        new OA\Property(property: 'pay_outs_total', type: 'string', example: '35.50'),
                        new OA\Property(property: 'movements', type: 'array', items: new OA\Items(ref: '#/components/schemas/CashMovement')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires close_daily_operation'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
        ]
    )]
    public function index(Request $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $request->user())->findOrFail($restaurant);

        $this->authorize('closeDay', $restaurantModel);

        $start = $this->periodResolver->resolve($restaurantModel, CarbonImmutable::now('UTC')->startOfSecond())->startUtc;

        $movements = RestaurantCashMovement::query()
            ->where('restaurant_id', $restaurantModel->id)
            ->where('recorded_at', '>=', $start)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $total = fn (string $type) => Money::centsToDecimal($movements->where('type', $type)->sum(fn ($movement) => Money::decimalToCents((string) $movement->amount)));

        return response()->json([
            'data' => [
                'period_started_at' => $start->format('Y-m-d\TH:i:s\Z'),
                'pay_ins_total' => $total(RestaurantCashMovement::TYPE_PAY_IN),
                'pay_outs_total' => $total(RestaurantCashMovement::TYPE_PAY_OUT),
                'movements' => CashMovementResource::collection($movements),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/cash-movements',
        operationId: 'cashMovementsStore',
        summary: 'Record a cash pay-in or pay-out (append-only)',
        description: 'A correction is an opposite movement — movements are never edited or deleted. Idempotent by Idempotency-Key: same key + same payload replays the original (200); same key + different payload is 409 IDEMPOTENCY_KEY_REUSED.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['type', 'amount', 'reason'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['pay_in', 'pay_out']),
                new OA\Property(property: 'amount', type: 'string', example: '20.00', description: 'Decimal string, > 0, max 2 decimals.'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 255, example: 'Compra de hielo'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Movement recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CashMovement')])),
            new OA\Response(response: 200, description: 'Idempotent replay of the original movement', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CashMovement')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires close_daily_operation'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
            new OA\Response(response: 409, description: 'IDEMPOTENCY_KEY_REUSED'),
            new OA\Response(response: 422, description: 'Validation error (including a missing Idempotency-Key header)'),
        ]
    )]
    public function store(StoreCashMovementRequest $request, int $restaurant): JsonResponse
    {
        $user = $request->user();
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $user)->findOrFail($restaurant);

        $this->authorize('closeDay', $restaurantModel);

        $result = $this->recordCashMovement->execute($restaurantModel, $user, $request->validated());

        return response()->json(['data' => new CashMovementResource($result['movement'])], $result['replayed'] ? 200 : 201);
    }

    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    private function restaurantQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return Restaurant::query()
            ->where('organization_id', $organization->id)
            ->when($accessibleRestaurantIds !== null, fn (Builder $query) => $query->whereIn('id', $accessibleRestaurantIds));
    }
}
