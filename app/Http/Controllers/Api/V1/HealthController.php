<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/health',
        operationId: 'healthCheck',
        summary: 'Check API health',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'API is operational'
            )
        ]
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ]);
    }
}