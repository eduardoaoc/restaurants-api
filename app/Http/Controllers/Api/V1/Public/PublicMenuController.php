<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Public\BuildPublicMenuAction;
use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Exceptions\Public\PublicMenuNotAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\PublicMenuRequest;
use App\Http\Resources\Api\V1\Public\PublicRestaurantResource;
use App\Http\Resources\Api\V1\Public\PublicSessionStateResource;
use App\Http\Resources\Api\V1\Public\PublicTableResource;
use App\Support\Locale\LocaleResolver;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicMenuController extends Controller
{
    public function __construct(
        private readonly ResolvePublicTableAction $resolvePublicTable,
        private readonly BuildPublicMenuAction $buildPublicMenu,
    ) {}

    /**
     * Return the full public menu for a table's public token, ready for
     * rendering: categories, products, prices and modifiers, filtered to
     * what is publicly visible and translated to the resolved locale.
     */
    #[OA\Get(
        path: '/api/v1/public/tables/{publicToken}/menu',
        operationId: 'publicTablesMenu',
        summary: 'Get the public menu for a table QR public token',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'es')),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false, schema: new OA\Schema(type: 'string', example: 'es-ES,es;q=0.9,en;q=0.8')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The public menu',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicMenu')
            ),
            new OA\Response(
                response: 404,
                description: 'Table not found, or menu not available',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid locale format',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
        ]
    )]
    public function show(PublicMenuRequest $request, string $publicToken): JsonResponse
    {
        $table = $this->resolvePublicTable->execute($publicToken);

        // The allowlist only governs an EXPLICITLY requested locale (the
        // language switcher) — omitting ?locale= (Accept-Language or the
        // hardcoded default) is never blocked by it, so pre-Bloco-18
        // requests keep working unchanged. See report.
        if ($request->validated('locale') !== null) {
            $requested = LocaleResolver::normalize($request->validated('locale'));

            if (! in_array($requested, $table->restaurant->settings->enabled_locales, true)) {
                throw new InvalidPublicLocaleException;
            }
        }

        $locale = LocaleResolver::resolve($request->validated('locale'), $request->header('Accept-Language'));

        $menu = $this->buildPublicMenu->execute($table->restaurant, $locale);

        if ($menu === null) {
            throw new PublicMenuNotAvailableException;
        }

        return response()->json([
            'data' => [
                'restaurant' => new PublicRestaurantResource($table->restaurant),
                'table' => new PublicTableResource($table),
                'session' => new PublicSessionStateResource($table->activeSession),
                'locale' => $locale,
                'menu' => $menu,
            ],
        ]);
    }
}
