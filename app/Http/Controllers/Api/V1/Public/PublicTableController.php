<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Public\EnterPublicTableAction;
use App\Actions\Public\ResolvePublicTableAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Public\PublicTableResolutionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PublicTableController extends Controller
{
    public function __construct(private readonly ResolvePublicTableAction $resolvePublicTable) {}

    /**
     * Resolve a table's public token: which restaurant/table it points to,
     * whether it has an active session, and whether a menu is available.
     * Does not return the menu content itself.
     */
    #[OA\Get(
        path: '/api/v1/public/tables/{publicToken}',
        operationId: 'publicTablesShow',
        summary: 'Resolve a table QR public token',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The resolved table',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicTableResolution')
            ),
            new OA\Response(
                response: 404,
                description: 'Table not found or not publicly servable',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
        ]
    )]
    public function show(Request $request, string $publicToken): JsonResponse
    {
        $table = $this->resolvePublicTable->execute($publicToken, $request->header('X-Table-Visit'));

        $menu = $table->restaurant->menu;
        $menuAvailable = $menu !== null && $menu->status === 'active';

        return response()->json([
            'data' => new PublicTableResolutionResource($table, $menuAvailable),
        ])->header('Cache-Control', 'no-store');
    }

    #[OA\Post(
        path: '/api/v1/public/tables/{publicToken}/enter',
        operationId: 'publicTablesEnter',
        summary: 'Start a QR visit or resume its pinned session',
        description: 'Fresh entry opens/reuses one session. X-Table-Visit pins a returning browser to its original visit, including after close; it never follows a new session.',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Table-Visit', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Visit context, inside the data envelope', content: new OA\JsonContent(ref: '#/components/schemas/PublicTableResolution')),
            new OA\Response(response: 404, description: 'QR not publicly servable'),
            new OA\Response(response: 409, description: 'PUBLIC_VISIT_INVALID'),
            new OA\Response(response: 429, description: 'Too many entry requests'),
        ]
    )]
    public function enter(Request $request, string $publicToken, EnterPublicTableAction $enterTable): JsonResponse
    {
        $table = $enterTable->execute($publicToken, $request->header('X-Table-Visit'));
        $menu = $table->restaurant->menu;

        return response()->json([
            'data' => new PublicTableResolutionResource($table, $menu !== null && $menu->status === 'active'),
        ])->header('Cache-Control', 'no-store');
    }
}
