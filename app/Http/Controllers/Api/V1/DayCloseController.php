<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DayClose\AddDayCloseAnnotationAction;
use App\Actions\DayClose\BuildDayClosePreviewAction;
use App\Actions\DayClose\CloseRestaurantDayAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DayClose\IndexDayClosesRequest;
use App\Http\Requests\Api\V1\DayClose\PreviewDayCloseRequest;
use App\Http\Requests\Api\V1\DayClose\StoreDayCloseAnnotationRequest;
use App\Http\Requests\Api\V1\DayClose\StoreDayCloseRequest;
use App\Http\Resources\Api\V1\DayClose\DayCloseAnnotationResource;
use App\Http\Resources\Api\V1\DayClose\DayCloseResource;
use App\Http\Resources\Api\V1\DayClose\DayCloseSummaryResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\DayClose\Pdf\DayClosePdf;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Cierre Diario (CARTA 9.1A): live preview, the close itself, history,
 * the persisted detail, its PDF (CARTA 9.1C) and post-close annotations.
 *
 * Restaurants/closes outside the requester's organization or
 * RestaurantScope resolve as 404 before any Policy runs (scoped queries),
 * exactly like every other tenant endpoint. Preview/close require
 * close_daily_operation; history/detail/PDF/annotations view_daily_closes.
 */
class DayCloseController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildDayClosePreviewAction $buildPreview,
        private readonly CloseRestaurantDayAction $closeRestaurantDay,
        private readonly AddDayCloseAnnotationAction $addAnnotation,
        private readonly DayClosePdf $dayClosePdf,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/day-close/preview',
        operationId: 'dayClosePreview',
        summary: 'Live preview of the Cierre Diario that a close right now would produce',
        description: 'Computed live, nothing persisted. Lists blockers (every active table session, each with diagnostics and can_be_voided; business_date_already_closed; expected_cash_negative) and non-blocking warnings. period.first_close = true means activity before period.period_started_at is outside the closing system. Send period.period_started_at and cash.expected_cash back on POST /day-closes.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'opening_float', in: 'query', required: false, description: 'Only used when cash.opening_float_source is "required", to preview cash.expected_cash.', schema: new OA\Schema(type: 'string', example: '150.00')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The live preview', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayClosePreview')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires close_daily_operation'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function preview(PreviewDayCloseRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $request->user())->findOrFail($restaurant);

        $this->authorize('closeDay', $restaurantModel);

        return response()->json(['data' => $this->buildPreview->execute($restaurantModel, $request->validated('opening_float'))]);
    }

    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/day-closes',
        operationId: 'dayClosesStore',
        summary: 'Close the business day (Cierre Diario) — immutable snapshot',
        description: 'Recomputes everything under an exclusive per-restaurant lock (never trusts the preview). The server computes expected cash and the difference. A cash_difference_note is required when |difference| > RestaurantSettings.cash_difference_note_threshold (strictly greater). opening_float is required only when the preview said opening_float_source = "required"; otherwise it may be omitted, and if sent must equal the suggested value. Idempotent by Idempotency-Key.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['period_started_at', 'expected_cash_seen', 'counted_cash'],
            properties: [
                new OA\Property(property: 'period_started_at', type: 'string', format: 'date-time', description: 'preview.period.period_started_at'),
                new OA\Property(property: 'expected_cash_seen', type: 'string', example: '762.00', description: 'preview.cash.expected_cash the user counted against'),
                new OA\Property(property: 'opening_float', type: 'string', nullable: true, example: '150.00'),
                new OA\Property(property: 'counted_cash', type: 'string', example: '758.80'),
                new OA\Property(property: 'cash_left_for_next_day', type: 'string', nullable: true, example: '150.00', description: 'Becomes the next close\'s opening float.'),
                new OA\Property(property: 'cash_difference_note', type: 'string', maxLength: 500, nullable: true),
                new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true, description: 'Plain text (never HTML).'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Day closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayClose')])),
            new OA\Response(response: 200, description: 'Idempotent replay of the same close', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayClose')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires close_daily_operation'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
            new OA\Response(response: 409, description: 'PERIOD_CHANGED (current_period_started_at), CASH_EXPECTATION_CHANGED (current_expected_cash), BUSINESS_DATE_ALREADY_CLOSED, PERIOD_EMPTY (only a zero-length period, T <= period_started_at — never a day with no activity: a day without payments/orders closes normally with zeros) or IDEMPOTENCY_KEY_REUSED'),
            new OA\Response(response: 422, description: 'CLOSE_BLOCKED (blockers[]) — any active table session, for every user: there is no force close, EXPECTED_CASH_NEGATIVE, OPENING_FLOAT_MISMATCH, or validation (opening_float required, cash_difference_note required, missing Idempotency-Key)'),
        ]
    )]
    public function store(StoreDayCloseRequest $request, int $restaurant): JsonResponse
    {
        $user = $request->user();
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $user)->findOrFail($restaurant);

        $this->authorize('closeDay', $restaurantModel);

        $result = $this->closeRestaurantDay->execute($restaurantModel, $user, $request->validated());

        return response()->json(['data' => new DayCloseResource($result['day_close'])], $result['replayed'] ? 200 : 201);
    }

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/day-closes',
        operationId: 'dayClosesIndex',
        summary: 'Cierre Diario history (summaries, never the full report)',
        description: 'Newest business_date first. Cursor-paginated: pass meta.next_cursor back as ?cursor=.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'business_date >= from', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'business_date <= to', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'closed_by', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'has_incidents', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'History page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'day_closes', type: 'array', items: new OA\Items(ref: '#/components/schemas/DayCloseSummary'))], type: 'object'),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'prev_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires view_daily_closes'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function index(IndexDayClosesRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $request->user())->findOrFail($restaurant);

        $this->authorize('viewDayCloses', $restaurantModel);

        $filters = $request->validated();

        $page = RestaurantDayClose::query()
            ->where('restaurant_id', $restaurantModel->id)
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('business_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('business_date', '<=', $filters['to']))
            ->when(isset($filters['closed_by']), fn (Builder $query) => $query->where('closed_by_user_id', (int) $filters['closed_by']))
            ->when(isset($filters['has_incidents']), fn (Builder $query) => $query->where('has_incidents', $request->boolean('has_incidents')))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => ['day_closes' => DayCloseSummaryResource::collection($page->items())],
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/day-closes/{dayClose}',
        operationId: 'dayClosesShow',
        summary: 'A persisted Cierre Diario — the immutable snapshot, never recomputed',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'dayClose', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The close', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayClose')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires view_daily_closes'),
            new OA\Response(response: 404, description: 'Not found in the user\'s organization/restaurant scope'),
        ]
    )]
    public function show(Request $request, int $dayClose): JsonResponse
    {
        $dayCloseModel = $this->dayCloseQuery($this->activeOrganization(), $request->user())->findOrFail($dayClose);

        $this->authorize('viewDayCloses', $dayCloseModel->restaurant);

        return response()->json(['data' => new DayCloseResource($dayCloseModel)]);
    }

    #[OA\Get(
        path: '/api/v1/day-closes/{dayClose}/pdf',
        operationId: 'dayClosesPdf',
        summary: 'Download the PDF of a persisted Cierre Diario',
        description: 'PDF is generated from the persisted day-close snapshot (RestaurantDayClose columns + report + annotations) — never recomputed from current orders/payments/analytics. Generated on demand (A4 portrait, es-ES, the close\'s own timezone and currency). Post-close annotations appear only in a separate final "Notas posteriores" section. Downloaded as an attachment; Cache-Control: private, no-store. No public URL exists.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'dayClose', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The PDF (Content-Disposition: attachment; filename="aforo-cierre-diario-{restaurant-slug}-{business_date}.pdf")',
                content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires view_daily_closes'),
            new OA\Response(response: 404, description: 'Not found in the user\'s organization/restaurant scope'),
        ]
    )]
    public function pdf(Request $request, int $dayClose): Response
    {
        $dayCloseModel = $this->dayCloseQuery($this->activeOrganization(), $request->user())->findOrFail($dayClose);

        $this->authorize('viewDayCloses', $dayCloseModel->restaurant);

        return response($this->dayClosePdf->render($dayCloseModel), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->dayClosePdf->filename($dayCloseModel).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/day-closes/{dayClose}/annotations',
        operationId: 'dayClosesAnnotationsStore',
        summary: 'Add a post-close note (append-only; never changes the report, hash or totals)',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'dayClose', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['body'],
            properties: [new OA\Property(property: 'body', type: 'string', maxLength: 2000, example: 'El terminal de tarjeta duplicó un cobro de 12,00 €; devuelto el 03/10.')]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Annotation added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayCloseAnnotation')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires view_daily_closes'),
            new OA\Response(response: 404, description: 'Not found in the user\'s organization/restaurant scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeAnnotation(StoreDayCloseAnnotationRequest $request, int $dayClose): JsonResponse
    {
        $user = $request->user();
        $dayCloseModel = $this->dayCloseQuery($this->activeOrganization(), $user)->findOrFail($dayClose);

        $this->authorize('viewDayCloses', $dayCloseModel->restaurant);

        $annotation = $this->addAnnotation->execute($dayCloseModel, $user, $request->validated('body'));

        return response()->json(['data' => new DayCloseAnnotationResource($annotation)], 201);
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

    /**
     * Closes of the active organization within the requester's
     * RestaurantScope — a sibling restaurant's close is a 404.
     */
    private function dayCloseQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return RestaurantDayClose::query()
            ->where('organization_id', $organization->id)
            ->when($accessibleRestaurantIds !== null, fn (Builder $query) => $query->whereIn('restaurant_id', $accessibleRestaurantIds));
    }
}
