<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\BillReceiptController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CategoryProductController;
use App\Http\Controllers\Api\V1\FloorController;
use App\Http\Controllers\Api\V1\FloorPlanController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KitchenController;
use App\Http\Controllers\Api\V1\KitchenTicketController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\ModifierGroupController;
use App\Http\Controllers\Api\V1\ModifierOptionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\Platform\PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\PlatformOrganizationController;
use App\Http\Controllers\Api\V1\Platform\PlatformRestaurantController;
use App\Http\Controllers\Api\V1\Platform\PlatformUserController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\Public\PublicMenuController;
use App\Http\Controllers\Api\V1\Public\PublicOrderController;
use App\Http\Controllers\Api\V1\Public\PublicTableController;
use App\Http\Controllers\Api\V1\Public\PublicTableRequestController;
use App\Http\Controllers\Api\V1\RestaurantAnalyticsController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\RestaurantDashboardController;
use App\Http\Controllers\Api\V1\RestaurantOperationsController;
use App\Http\Controllers\Api\V1\RestaurantProductController;
use App\Http\Controllers\Api\V1\RestaurantSettingsController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StaffPerformanceController;
use App\Http\Controllers\Api\V1\StaffReviewController;
use App\Http\Controllers\Api\V1\StaffShiftController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\TableRequestController;
use App\Http\Controllers\Api\V1\TableSessionBillController;
use App\Http\Controllers\Api\V1\TableSessionController;
use App\Http\Controllers\Api\V1\TableSessionTransferController;
use App\Http\Controllers\Api\V1\TableSessionWaiterController;
use App\Http\Controllers\Api\V1\WaiterCallController;
use App\Http\Controllers\Api\V1\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Public surface: QR resolution + menu + order creation. No auth, no
    // tenant context — everything is derived from the public_token itself
    // (see Bloco 9). Order creation gets its own, stricter limiter.
    Route::prefix('public')->group(function () {
        Route::middleware('throttle:public-menu')->group(function () {
            Route::get('/tables/{publicToken}', [PublicTableController::class, 'show']);
            Route::get('/tables/{publicToken}/menu', [PublicMenuController::class, 'show']);
        });

        Route::post('/tables/{publicToken}/orders', [PublicOrderController::class, 'store'])
            ->middleware('throttle:public-orders');

        Route::middleware('throttle:public-table-requests')->group(function () {
            Route::post('/tables/{publicToken}/requests/call-waiter', [PublicTableRequestController::class, 'callWaiter']);
            Route::post('/tables/{publicToken}/requests/bill', [PublicTableRequestController::class, 'bill']);
        });
    });

    // Platform namespace: cross-tenant administration for AFORO platform
    // admins only (see EnsurePlatformAdmin). Deliberately its own
    // top-level group, sibling to the tenant group below — NOT nested
    // inside it, and NOT running `tenant` (ResolveTenant): a platform
    // admin has no "active organization" of their own, and every target
    // organization/restaurant/user here is addressed explicitly by its
    // own id in the URL, never resolved from the actor's own membership.
    Route::prefix('platform')->middleware(['auth:sanctum', 'active_user', 'platform_admin'])->group(function () {
        Route::get('/users', [PlatformUserController::class, 'index']);
        Route::get('/users/{user}', [PlatformUserController::class, 'show']);
        Route::patch('/users/{user}/status', [PlatformUserController::class, 'updateStatus']);

        Route::get('/organizations', [PlatformOrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [PlatformOrganizationController::class, 'show']);
        Route::patch('/organizations/{organization}/status', [PlatformOrganizationController::class, 'updateStatus']);
        Route::patch('/organizations/{organization}/plan', [PlatformOrganizationController::class, 'updatePlan']);

        Route::get('/restaurants', [PlatformRestaurantController::class, 'index']);
        Route::get('/restaurants/{restaurant}', [PlatformRestaurantController::class, 'show']);
        Route::patch('/restaurants/{restaurant}/status', [PlatformRestaurantController::class, 'updateStatus']);

        Route::get('/audit-logs', [PlatformAuditLogController::class, 'index']);
    });

    Route::middleware(['auth:sanctum', 'active_user', 'tenant'])->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::patch('/organization', [OrganizationController::class, 'update']);

        Route::get('/restaurants', [RestaurantController::class, 'index']);
        Route::post('/restaurants', [RestaurantController::class, 'store']);
        Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show']);
        Route::patch('/restaurants/{restaurant}', [RestaurantController::class, 'update']);
        Route::get('/restaurants/{restaurant}/dashboard', [RestaurantDashboardController::class, 'show']);
        // Operations Live (Bloco 5): real-time operational snapshot,
        // deliberately separate from the historical/period dashboard
        // above — see RestaurantOperationsController.
        Route::get('/restaurants/{restaurant}/operations/live', [RestaurantOperationsController::class, 'live']);
        // Analytics (Bloco 6): historical/period read model, distinct
        // from both /dashboard above and /operations/live — see
        // RestaurantAnalyticsController.
        Route::get('/restaurants/{restaurant}/analytics', [RestaurantAnalyticsController::class, 'show']);
        Route::get('/restaurants/{restaurant}/settings', [RestaurantSettingsController::class, 'show']);
        Route::patch('/restaurants/{restaurant}/settings', [RestaurantSettingsController::class, 'update']);

        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::get('/staff/{user}', [StaffController::class, 'show']);
        Route::patch('/staff/{user}', [StaffController::class, 'update']);

        Route::get('/me/performance', [StaffPerformanceController::class, 'me']);
        Route::get('/restaurants/{restaurant}/staff/{staff}/performance', [StaffPerformanceController::class, 'show']);
        Route::post('/restaurants/{restaurant}/staff/{staff}/reviews', [StaffReviewController::class, 'store']);
        Route::get('/restaurants/{restaurant}/staff/{staff}/reviews', [StaffReviewController::class, 'index']);

        Route::get('/restaurants/{restaurant}/tables', [TableController::class, 'index']);
        Route::post('/restaurants/{restaurant}/tables', [TableController::class, 'store']);
        Route::get('/tables/{table}', [TableController::class, 'show']);
        Route::patch('/tables/{table}', [TableController::class, 'update']);
        Route::post('/tables/{table}/open', [TableSessionController::class, 'open']);
        Route::post('/tables/{table}/close', [TableSessionController::class, 'close']);

        // Floor Plan (Bloco 1): floors, zones and the aggregated/bulk-save
        // endpoints that feed the map editor. See FloorPolicy/ZonePolicy
        // for the view (manage_tables/close_bill) vs manage
        // (manage_floor_plan) permission split.
        Route::get('/restaurants/{restaurant}/floors', [FloorController::class, 'index']);
        Route::post('/restaurants/{restaurant}/floors', [FloorController::class, 'store']);
        Route::get('/floors/{floor}', [FloorController::class, 'show']);
        Route::patch('/floors/{floor}', [FloorController::class, 'update']);
        Route::delete('/floors/{floor}', [FloorController::class, 'destroy']);

        Route::get('/restaurants/{restaurant}/zones', [ZoneController::class, 'index']);
        Route::post('/restaurants/{restaurant}/zones', [ZoneController::class, 'store']);
        Route::get('/zones/{zone}', [ZoneController::class, 'show']);
        Route::patch('/zones/{zone}', [ZoneController::class, 'update']);
        Route::delete('/zones/{zone}', [ZoneController::class, 'destroy']);

        Route::get('/restaurants/{restaurant}/floor-plan', [FloorPlanController::class, 'show']);
        Route::patch('/restaurants/{restaurant}/floor-plan/layout', [FloorPlanController::class, 'updateLayout']);

        Route::get('/restaurants/{restaurant}/menu', [MenuController::class, 'show']);
        Route::post('/restaurants/{restaurant}/menu', [MenuController::class, 'store']);
        Route::patch('/restaurants/{restaurant}/menu', [MenuController::class, 'update']);

        Route::get('/restaurants/{restaurant}/categories', [CategoryController::class, 'index']);
        Route::post('/restaurants/{restaurant}/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);

        Route::post('/restaurants/{restaurant}/products', [RestaurantProductController::class, 'store']);
        Route::patch('/restaurant-products/{restaurantProduct}', [RestaurantProductController::class, 'update']);

        Route::post('/categories/{category}/products', [CategoryProductController::class, 'store']);
        Route::patch('/categories/{category}/products/{restaurantProduct}', [CategoryProductController::class, 'update']);

        Route::get('/restaurant-products/{restaurantProduct}/modifier-groups', [ModifierGroupController::class, 'index']);
        Route::post('/restaurant-products/{restaurantProduct}/modifier-groups', [ModifierGroupController::class, 'store']);
        Route::get('/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'show']);
        Route::patch('/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update']);

        Route::get('/modifier-groups/{modifierGroup}/options', [ModifierOptionController::class, 'index']);
        Route::post('/modifier-groups/{modifierGroup}/options', [ModifierOptionController::class, 'store']);
        Route::get('/modifier-options/{modifierOption}', [ModifierOptionController::class, 'show']);
        Route::patch('/modifier-options/{modifierOption}', [ModifierOptionController::class, 'update']);

        Route::post('/tables/{table}/orders', [OrderController::class, 'store']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/approve', [OrderController::class, 'approve']);
        Route::post('/orders/{order}/reject', [OrderController::class, 'reject']);
        Route::post('/orders/{order}/accept', [OrderController::class, 'accept']);
        Route::post('/orders/{order}/preparing', [OrderController::class, 'preparing']);
        Route::post('/orders/{order}/ready', [OrderController::class, 'ready']);
        Route::post('/orders/{order}/served', [OrderController::class, 'served']);
        Route::get('/orders/{order}/kitchen-ticket', [KitchenTicketController::class, 'show']);
        Route::post('/orders/{order}/kitchen-ticket/print', [KitchenTicketController::class, 'print']);

        Route::get('/kitchen/orders', [KitchenController::class, 'orders']);

        Route::get('/table-requests', [TableRequestController::class, 'index']);
        Route::get('/table-requests/{tableRequest}', [TableRequestController::class, 'show']);
        Route::post('/table-requests/{tableRequest}/acknowledge', [TableRequestController::class, 'acknowledge']);
        Route::post('/table-requests/{tableRequest}/complete', [TableRequestController::class, 'complete']);
        Route::post('/table-requests/{tableRequest}/cancel', [TableRequestController::class, 'cancel']);

        Route::get('/table-sessions/{tableSession}/bill', [TableSessionBillController::class, 'show']);
        Route::post('/table-sessions/{tableSession}/payments', [TableSessionBillController::class, 'storePayment']);
        Route::get('/table-sessions/{tableSession}/receipt', [BillReceiptController::class, 'show']);
        Route::post('/table-sessions/{tableSession}/receipt/print', [BillReceiptController::class, 'print']);

        // Waiter assignment (Bloco 2): belongs to the TableSession, not the
        // Table — see TableSessionWaiterController.
        Route::put('/table-sessions/{tableSession}/waiter', [TableSessionWaiterController::class, 'update']);
        Route::delete('/table-sessions/{tableSession}/waiter', [TableSessionWaiterController::class, 'destroy']);

        // Staff Shift (Bloco 3): canonical operational presence, scoped to
        // one Restaurant — see StaffShiftController.
        Route::get('/restaurants/{restaurant}/staff-shifts', [StaffShiftController::class, 'index']);
        Route::post('/restaurants/{restaurant}/staff-shifts', [StaffShiftController::class, 'store']);
        Route::post('/staff-shifts/{staffShift}/end', [StaffShiftController::class, 'end']);

        // Table Operational Actions (Bloco 4): transfer belongs to the
        // TableSession, not the Table — see TableSessionTransferController.
        Route::post('/table-sessions/{tableSession}/transfer', [TableSessionTransferController::class, 'transfer']);

        // "Call responsible waiter" — internal escalation, its own minimal
        // resource (WaiterCall), not a TableRequest — see WaiterCallController.
        Route::post('/table-sessions/{tableSession}/waiter-calls', [WaiterCallController::class, 'store']);
        Route::post('/waiter-calls/{waiterCall}/acknowledge', [WaiterCallController::class, 'acknowledge']);
    });
});
