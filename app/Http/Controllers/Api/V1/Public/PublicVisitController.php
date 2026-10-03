<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Public\ResolvePublicVisitAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Public\PublicVisitResource;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicVisitController extends Controller
{
    public function __construct(private readonly ResolvePublicVisitAction $resolveVisit) {}

    /**
     * Post-payment visit summary (CARTA 5.1A): what was consumed during a
     * paid visit, keyed ONLY by the visit's opaque feedback_token. Keeps
     * working after the table is closed. A separate contract from
     * GET /public/feedback/{feedbackToken}.
     */
    #[OA\Get(
        path: '/api/v1/public/visits/{feedbackToken}',
        operationId: 'publicVisitShow',
        summary: 'Post-payment summary of a visit, resolved from its feedback token',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'feedbackToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The visit summary (billable orders only, snapshot values)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PublicVisit')])
            ),
            new OA\Response(
                response: 404,
                description: 'FEEDBACK_TOKEN_NOT_FOUND — invalid or unknown token',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 409,
                description: 'TABLE_SESSION_NOT_PAID_FOR_VISIT — the visit exists but is not paid yet',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
        ]
    )]
    public function show(string $feedbackToken): JsonResponse
    {
        $session = $this->resolveVisit->execute($feedbackToken);
        $summary = SessionBillCalculator::summarize($session);

        return response()->json([
            'data' => new PublicVisitResource($session, Money::centsToDecimal($summary['ordersTotalCents'])),
        ]);
    }
}
