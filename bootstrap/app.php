<?php

use App\Exceptions\Orders\IdempotencyKeyReusedException;
use App\Exceptions\Orders\InvalidModifierSelectionException;
use App\Exceptions\Orders\InvalidOrderItemException;
use App\Exceptions\Orders\OrderCreationConflictException;
use App\Exceptions\Orders\OrderStateConflictException;
use App\Exceptions\Orders\TableSessionNotActiveException;
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
        $exceptions->render(function (TableSessionNotActiveException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE', 'message' => 'The table session is not active.'],
            ], 409);
        });
        $exceptions->render(function (InvalidOrderItemException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'INVALID_ORDER_ITEM', 'message' => 'One of the selected items is invalid.'],
            ], 422);
        });
        $exceptions->render(function (InvalidModifierSelectionException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'INVALID_MODIFIER_SELECTION', 'message' => 'The modifier selection is invalid.'],
            ], 422);
        });
        $exceptions->render(function (OrderCreationConflictException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'ORDER_CREATION_CONFLICT', 'message' => 'The order could not be created due to a conflicting change.'],
            ], 409);
        });
        $exceptions->render(function (IdempotencyKeyReusedException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => 'This idempotency key was already used with a different request.'],
            ], 409);
        });
        $exceptions->render(function (OrderStateConflictException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 409);
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
