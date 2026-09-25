<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\PaymentsController;
use App\Http\Controllers\Api\ProductsController;
use App\Http\Controllers\Api\RefundsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\TransactionsController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::prefix('merchant')->group(function (): void {
    Route::post('/register', [MerchantController::class, 'register']);
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('products', ProductsController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('orders', OrdersController::class);
    Route::patch('orders/{order}/status', [OrdersController::class, 'updateStatus']);

    Route::get('inventory/logs', [InventoryController::class, 'index']);
    Route::post('inventory/adjust', [InventoryController::class, 'adjust']);

    Route::apiResource('payments', PaymentsController::class);
    Route::apiResource('transactions', TransactionsController::class);
    Route::apiResource('refunds', RefundsController::class);

    Route::prefix('reports')->group(function (): void {
        Route::get('/sales', [ReportsController::class, 'sales']);
        Route::get('/customers', [ReportsController::class, 'customers']);
        Route::get('/products', [ReportsController::class, 'products']);
        Route::get('/inventory', [ReportsController::class, 'inventory']);
    });
});
