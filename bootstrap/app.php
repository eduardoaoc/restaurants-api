<?php

use App\Exceptions\Audit\InvalidAuditPeriodException;
use App\Exceptions\Billing\PaymentExceedsBalanceException;
use App\Exceptions\Billing\PaymentIdempotencyKeyReusedException;
use App\Exceptions\Billing\TableSessionAlreadyPaidException;
use App\Exceptions\Billing\TableSessionClosedException;
use App\Exceptions\Billing\TableSessionHasNoBillableOrdersException;
use App\Exceptions\Billing\TableSessionHasOpenOrdersException;
use App\Exceptions\Billing\TableSessionNotPaidException;
use App\Exceptions\Orders\IdempotencyKeyReusedException;
use App\Exceptions\Orders\InvalidModifierSelectionException;
use App\Exceptions\Orders\InvalidOrderItemException;
use App\Exceptions\Orders\OrderCreationConflictException;
use App\Exceptions\Orders\OrderStateConflictException;
use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Exceptions\Printing\BillReceiptPrintingDisabledException;
use App\Exceptions\Printing\KitchenTicketPrintingDisabledException;
use App\Exceptions\Printing\OrderNotPrintableException;
use App\Exceptions\Public\BillRequestDisabledException;
use App\Exceptions\Public\CustomerOrderingDisabledException;
use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Exceptions\Public\PublicMenuNotAvailableException;
use App\Exceptions\Public\PublicTableNotFoundException;
use App\Exceptions\Public\WaiterCallDisabledException;
use App\Exceptions\Reports\InvalidReportPeriodException;
use App\Exceptions\Staff\CannotReviewSelfException;
use App\Exceptions\Staff\InvalidPerformancePeriodException;
use App\Exceptions\TableRequests\TableRequestAlreadyOpenException;
use App\Exceptions\TableRequests\TableRequestStateConflictException;
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
        $exceptions->render(function (TableRequestAlreadyOpenException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_REQUEST_ALREADY_OPEN', 'message' => 'A request of this type is already open for this table session.'],
            ], 409);
        });
        $exceptions->render(function (TableRequestStateConflictException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
        $exceptions->render(function (TableSessionClosedException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_SESSION_CLOSED', 'message' => 'This table session is already closed.'],
            ], 409);
        });
        $exceptions->render(function (TableSessionAlreadyPaidException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_SESSION_ALREADY_PAID', 'message' => 'This table session is already fully paid.'],
            ], 409);
        });
        $exceptions->render(function (TableSessionNotPaidException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_SESSION_NOT_PAID', 'message' => 'This table session has not been fully paid yet.'],
            ], 409);
        });
        $exceptions->render(function (TableSessionHasOpenOrdersException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_SESSION_HAS_OPEN_ORDERS', 'message' => 'This table session still has an order in progress.'],
            ], 409);
        });
        $exceptions->render(function (TableSessionHasNoBillableOrdersException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS', 'message' => 'This table session has no billable orders.'],
            ], 409);
        });
        $exceptions->render(function (PaymentExceedsBalanceException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'PAYMENT_EXCEEDS_BALANCE', 'message' => 'The payment amount exceeds the current balance.'],
            ], 422);
        });
        $exceptions->render(function (PaymentIdempotencyKeyReusedException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'PAYMENT_IDEMPOTENCY_KEY_REUSED', 'message' => 'This idempotency key was already used with a different payment.'],
            ], 409);
        });
        $exceptions->render(function (OrderNotPrintableException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'ORDER_NOT_PRINTABLE', 'message' => 'This order cannot be printed in its current state.'],
            ], 409);
        });
        $exceptions->render(function (InvalidAuditPeriodException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'INVALID_AUDIT_PERIOD', 'message' => 'The audit log period is invalid.'],
            ], 422);
        });
        $exceptions->render(function (CannotReviewSelfException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'CANNOT_REVIEW_SELF', 'message' => 'A staff member cannot review themselves.'],
            ], 422);
        });
        $exceptions->render(function (InvalidPerformancePeriodException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'INVALID_PERFORMANCE_PERIOD', 'message' => 'The performance period is invalid.'],
            ], 422);
        });
        $exceptions->render(function (InvalidReportPeriodException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'INVALID_REPORT_PERIOD', 'message' => 'The report period is invalid.'],
            ], 422);
        });
        $exceptions->render(function (CustomerOrderingDisabledException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'CUSTOMER_ORDERING_DISABLED', 'message' => 'Customer ordering is disabled for this restaurant.'],
            ], 409);
        });
        $exceptions->render(function (WaiterCallDisabledException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'WAITER_CALL_DISABLED', 'message' => 'Calling the waiter is disabled for this restaurant.'],
            ], 409);
        });
        $exceptions->render(function (BillRequestDisabledException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'BILL_REQUEST_DISABLED', 'message' => 'Requesting the bill is disabled for this restaurant.'],
            ], 409);
        });
        $exceptions->render(function (KitchenTicketPrintingDisabledException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'KITCHEN_TICKET_PRINTING_DISABLED', 'message' => 'Kitchen ticket printing is disabled for this restaurant.'],
            ], 409);
        });
        $exceptions->render(function (BillReceiptPrintingDisabledException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'BILL_RECEIPT_PRINTING_DISABLED', 'message' => 'Bill receipt printing is disabled for this restaurant.'],
            ], 409);
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
