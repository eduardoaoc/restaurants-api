<?php

use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Exceptions\Public\PublicMenuNotAvailableException;
use App\Exceptions\Public\PublicTableNotFoundException;
use App\Exceptions\TableSessionConflictException;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'tenant' => ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (TableSessionConflictException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
        $exceptions->render(function (PublicTableNotFoundException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.'],
            ], 404);
        });
        $exceptions->render(function (PublicMenuNotAvailableException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'MENU_NOT_AVAILABLE', 'message' => 'Menu is not available.'],
            ], 404);
        });
        $exceptions->render(function (InvalidPublicLocaleException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'INVALID_LOCALE', 'message' => 'The locale format is invalid.'],
            ], 422);
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/v1/public/*')) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests.'],
            ], 429, $e->getHeaders());
        });
    })->create();
