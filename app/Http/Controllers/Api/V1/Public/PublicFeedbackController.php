<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Feedback\ResolvePublicFeedbackContextAction;
use App\Actions\Feedback\SubmitPublicFeedbackAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StorePublicFeedbackRequest;
use App\Http\Resources\Api\V1\Public\PublicFeedbackContextResource;
use App\Http\Resources\Api\V1\Public\PublicFeedbackResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicFeedbackController extends Controller
{
    public function __construct(
        private readonly ResolvePublicFeedbackContextAction $resolveContext,
        private readonly SubmitPublicFeedbackAction $submitFeedback,
    ) {}

    /**
     * Resolve a feedback_token's context: whether it has already been
     * used, and minimal restaurant/table display info. The token is the
     * only key accepted here — never table_session_id/table_id/the
     * table's own public_token (Passo 3.5 §6).
     */
    #[OA\Get(
        path: '/api/v1/public/feedback/{feedbackToken}',
        operationId: 'publicFeedbackShow',
        summary: "Resolve a visit's feedback context from its feedback token",
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'feedbackToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The feedback context',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PublicFeedbackContext')])
            ),
            new OA\Response(
                response: 404,
                description: 'FEEDBACK_TOKEN_NOT_FOUND — invalid or unknown token',
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
        $session = $this->resolveContext->execute($feedbackToken);

        return response()->json([
            'data' => new PublicFeedbackContextResource($session, $session->hasSubmittedFeedback()),
        ]);
    }

    /**
     * Submit a customer's post-visit feedback for the token's visit. Only
     * accepted once per visit — a second submission with the same payload
     * replays the existing record (200); a different payload is rejected
     * (409 FEEDBACK_ALREADY_SUBMITTED).
     */
    #[OA\Post(
        path: '/api/v1/public/feedback/{feedbackToken}',
        operationId: 'publicFeedbackStore',
        summary: "Submit a customer's post-visit feedback",
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'feedbackToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePublicFeedbackRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Feedback recorded',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PublicFeedback')])
            ),
            new OA\Response(
                response: 200,
                description: 'Idempotent replay of an identical, already-submitted feedback',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PublicFeedback')])
            ),
            new OA\Response(
                response: 404,
                description: 'FEEDBACK_TOKEN_NOT_FOUND — invalid or unknown token',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 409,
                description: 'FEEDBACK_ALREADY_SUBMITTED (a different feedback already exists for this visit) or TABLE_SESSION_NOT_PAID_FOR_FEEDBACK',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid payload — missing/empty name, a rating outside 1-5, or a comment over its length limit',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
        ]
    )]
    public function store(StorePublicFeedbackRequest $request, string $feedbackToken): JsonResponse
    {
        $result = $this->submitFeedback->execute($feedbackToken, $request->validated());

        return response()->json([
            'data' => new PublicFeedbackResource($result['feedback']),
        ], $result['replayed'] ? 200 : 201);
    }
}
