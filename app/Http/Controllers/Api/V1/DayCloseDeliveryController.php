<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WhatsApp\ResendDayCloseWhatsAppAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WhatsApp\DayCloseDeliveryResource;
use App\Models\Organization;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * WhatsApp deliveries of a Cierre Diario (CARTA 9.1E): history and manual
 * resend. view_daily_closes + RestaurantScope (out of scope = 404).
 */
class DayCloseDeliveryController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResendDayCloseWhatsAppAction $resend,
    ) {}

    #[OA\Get(
        path: '/api/v1/day-closes/{dayClose}/deliveries',
        operationId: 'dayCloseDeliveriesIndex',
        summary: 'WhatsApp deliveries of a Cierre Diario (automatic + manual resends), oldest first',
        description: 'status: pending (queued) -> accepted (Meta took the request; NOT delivered) -> sent -> delivered -> read (Meta webhooks); failed; skipped (automatic delivery not attempted: failure.code configuration_incomplete | recipient_missing | consent_missing). Never the full phone number.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'dayClose', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deliveries', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'deliveries', type: 'array', items: new OA\Items(ref: '#/components/schemas/DayCloseDelivery'))], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires view_daily_closes'),
            new OA\Response(response: 404, description: 'Not found in the user\'s organization/restaurant scope'),
        ]
    )]
    public function index(Request $request, int $dayClose): JsonResponse
    {
        $dayCloseModel = $this->dayCloseQuery($this->activeOrganization(), $request->user())->findOrFail($dayClose);

        $this->authorize('viewDayCloses', $dayCloseModel->restaurant);

        return response()->json(['data' => ['deliveries' => DayCloseDeliveryResource::collection($dayCloseModel->deliveries)]]);
    }

    #[OA\Post(
        path: '/api/v1/day-closes/{dayClose}/deliveries',
        operationId: 'dayCloseDeliveriesResend',
        summary: 'Resend a Cierre Diario by WhatsApp to the current recipient (new manual delivery)',
        description: 'The POST is the confirmation. Creates a new manual_resend delivery for the current active recipient; never reuses an old delivery and never creates a new close. Idempotent by Idempotency-Key (same key + same user = same delivery, 200). Throttled.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [
            new OA\Parameter(name: 'dayClose', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Delivery queued', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayCloseDelivery')])),
            new OA\Response(response: 200, description: 'Idempotent replay', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayCloseDelivery')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires view_daily_closes'),
            new OA\Response(response: 404, description: 'Not found in the user\'s organization/restaurant scope'),
            new OA\Response(response: 409, description: 'DELIVERY_ALREADY_PENDING or IDEMPOTENCY_KEY_REUSED'),
            new OA\Response(response: 422, description: 'WHATSAPP_NOT_AVAILABLE, WHATSAPP_DISABLED, RECIPIENT_REQUIRED, CONSENT_REQUIRED, or missing Idempotency-Key'),
            new OA\Response(response: 429, description: 'Too many resend attempts'),
        ]
    )]
    public function store(Request $request, int $dayClose): JsonResponse
    {
        $user = $request->user();
        $dayCloseModel = $this->dayCloseQuery($this->activeOrganization(), $user)->findOrFail($dayClose);

        $this->authorize('viewDayCloses', $dayCloseModel->restaurant);

        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'The Idempotency-Key header is required (max 100 characters).']);
        }

        $result = $this->resend->execute($dayCloseModel, $user, $key);

        return response()->json(['data' => new DayCloseDeliveryResource($result['delivery'])], $result['replayed'] ? 200 : 201);
    }

    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    private function dayCloseQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return RestaurantDayClose::query()
            ->where('organization_id', $organization->id)
            ->when($accessibleRestaurantIds !== null, fn (Builder $query) => $query->whereIn('restaurant_id', $accessibleRestaurantIds));
    }
}
