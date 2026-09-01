<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CategoryProductController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\RestaurantProductController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\TableSessionController;
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

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::patch('/organization', [OrganizationController::class, 'update']);

        Route::get('/restaurants', [RestaurantController::class, 'index']);
        Route::post('/restaurants', [RestaurantController::class, 'store']);
        Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show']);
        Route::patch('/restaurants/{restaurant}', [RestaurantController::class, 'update']);

        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::get('/staff/{user}', [StaffController::class, 'show']);
        Route::patch('/staff/{user}', [StaffController::class, 'update']);

        Route::get('/restaurants/{restaurant}/tables', [TableController::class, 'index']);
        Route::post('/restaurants/{restaurant}/tables', [TableController::class, 'store']);
        Route::get('/tables/{table}', [TableController::class, 'show']);
        Route::patch('/tables/{table}', [TableController::class, 'update']);
        Route::post('/tables/{table}/open', [TableSessionController::class, 'open']);
        Route::post('/tables/{table}/close', [TableSessionController::class, 'close']);

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
    });
});
