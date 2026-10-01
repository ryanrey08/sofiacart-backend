<?php

use App\Http\Controllers\Api\Admin\AdminPermissionController;
use App\Http\Controllers\Api\Admin\AdminRoleController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\CustomerManagementController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\MerchantManagementController;
use App\Http\Controllers\Api\Admin\PlatformReportsController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\SystemLogController;
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
use App\Http\Controllers\Api\ReturnRequestsController;
use App\Http\Controllers\Api\TransactionsController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::prefix('admin/auth')->group(function (): void {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-auth');
    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:admin-auth');
    Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:admin-auth');

    Route::middleware(['auth:sanctum', 'admin', 'admin.token'])->group(function (): void {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::post('/logout-all', [AdminAuthController::class, 'logoutAll']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::get('/sessions', [AdminAuthController::class, 'sessions']);
        Route::delete('/sessions/{tokenId}', [AdminAuthController::class, 'revokeSession']);
    });
});

Route::prefix('merchant')->group(function (): void {
    Route::post('/register', [MerchantController::class, 'register']);
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    Route::get('categories/stats', [CategoryController::class, 'stats']);
    Route::post('categories/bulk', [CategoryController::class, 'bulk']);
    Route::apiResource('categories', CategoryController::class);
    Route::post('categories/{category}/image', [CategoryController::class, 'uploadImage']);
    Route::patch('categories/{category}/status', [CategoryController::class, 'updateStatus']);
    Route::apiResource('products', ProductsController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('orders', OrdersController::class);
    Route::patch('orders/{order}/status', [OrdersController::class, 'updateStatus']);
    Route::get('return-requests', [ReturnRequestsController::class, 'index']);
    Route::post('return-requests', [ReturnRequestsController::class, 'store']);
    Route::get('return-requests/{returnRequest}', [ReturnRequestsController::class, 'show']);
    Route::patch('return-requests/{returnRequest}', [ReturnRequestsController::class, 'review']);
    Route::get('return-requests/{returnRequest}/evidence/{index}', [ReturnRequestsController::class, 'evidence']);

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

Route::middleware(['auth:sanctum', 'admin', 'admin.token', 'admin.audit'])->prefix('admin')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->middleware('admin.permission:dashboard.view');

    Route::get('/merchants', [MerchantManagementController::class, 'index'])
        ->middleware('admin.permission:merchants.view');
    Route::get('/merchants/{merchant}', [MerchantManagementController::class, 'show'])
        ->middleware('admin.permission:merchants.view');
    Route::patch('/merchants/{merchant}/status', [MerchantManagementController::class, 'updateStatus'])
        ->middleware('admin.permission:merchants.manage')
        ->name('admin.merchants.status');
    Route::get('/merchants/{merchant}/onboarding-history', [MerchantManagementController::class, 'onboardingHistory'])
        ->middleware('admin.permission:merchants.view');
    Route::get('/merchants/{merchant}/billing', [MerchantManagementController::class, 'billing'])
        ->middleware('admin.permission:merchants.billing.view');

    Route::get('/customers', [CustomerManagementController::class, 'index'])
        ->middleware('admin.permission:customers.view');
    Route::get('/customers/{customer}', [CustomerManagementController::class, 'show'])
        ->middleware('admin.permission:customers.view');
    Route::get('/customers/{customer}/orders', [CustomerManagementController::class, 'orders'])
        ->middleware('admin.permission:customers.view');

    Route::get('/orders', [OrdersController::class, 'index'])
        ->middleware('admin.permission:orders.view');
    Route::get('/orders/{order}', [OrdersController::class, 'show'])
        ->middleware('admin.permission:orders.view');
    Route::post('/orders', [OrdersController::class, 'store'])
        ->middleware('admin.permission:orders.manage');
    Route::match(['put', 'patch'], '/orders/{order}', [OrdersController::class, 'update'])
        ->middleware('admin.permission:orders.manage');
    Route::patch('/orders/{order}/status', [OrdersController::class, 'updateStatus'])
        ->middleware('admin.permission:orders.manage');

    Route::get('/products', [ProductsController::class, 'index'])
        ->middleware('admin.permission:products.view');
    Route::get('/products/{product}', [ProductsController::class, 'show'])
        ->middleware('admin.permission:products.view');
    Route::post('/products', [ProductsController::class, 'store'])
        ->middleware('admin.permission:products.manage');
    Route::match(['put', 'patch'], '/products/{product}', [ProductsController::class, 'update'])
        ->middleware('admin.permission:products.manage');
    Route::delete('/products/{product}', [ProductsController::class, 'destroy'])
        ->middleware('admin.permission:products.manage');
    Route::get('/inventory/logs', [InventoryController::class, 'index'])
        ->middleware('admin.permission:products.inventory.manage');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
        ->middleware('admin.permission:products.inventory.manage');

    Route::get('/payments', [PaymentsController::class, 'index'])
        ->middleware('admin.permission:payments.view');
    Route::get('/payments/{payment}', [PaymentsController::class, 'show'])
        ->middleware('admin.permission:payments.view');
    Route::post('/payments', [PaymentsController::class, 'store'])
        ->middleware('admin.permission:payments.manage');
    Route::match(['put', 'patch'], '/payments/{payment}', [PaymentsController::class, 'update'])
        ->middleware('admin.permission:payments.manage');
    Route::get('/transactions', [TransactionsController::class, 'index'])
        ->middleware('admin.permission:payments.view');
    Route::get('/transactions/{transaction}', [TransactionsController::class, 'show'])
        ->middleware('admin.permission:payments.view');

    Route::get('/refunds', [RefundsController::class, 'index'])
        ->middleware('admin.permission:payments.view');
    Route::get('/refunds/{refund}', [RefundsController::class, 'show'])
        ->middleware('admin.permission:payments.view');
    Route::post('/refunds', [RefundsController::class, 'store'])
        ->middleware('admin.permission:payments.refund');
    Route::match(['put', 'patch'], '/refunds/{refund}', [RefundsController::class, 'update'])
        ->middleware('admin.permission:payments.refund');

    Route::get('/reports/platform', [PlatformReportsController::class, 'index'])
        ->middleware('admin.permission:reports.view');
    Route::get('/reports/export', [PlatformReportsController::class, 'export'])
        ->middleware('admin.permission:reports.export');
    Route::get('/reports/sales', [ReportsController::class, 'sales'])
        ->middleware('admin.permission:reports.view');
    Route::get('/reports/customers', [ReportsController::class, 'customers'])
        ->middleware('admin.permission:reports.view');
    Route::get('/reports/products', [ReportsController::class, 'products'])
        ->middleware('admin.permission:reports.view');
    Route::get('/reports/inventory', [ReportsController::class, 'inventory'])
        ->middleware('admin.permission:reports.view');

    Route::get('/settings', [SettingController::class, 'index'])
        ->middleware('admin.permission:settings.view');
    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('admin.permission:settings.manage');

    Route::get('/users', [AdminUserController::class, 'index'])
        ->middleware('admin.permission:users.view');
    Route::post('/users', [AdminUserController::class, 'store'])
        ->middleware('admin.permission:users.manage');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])
        ->middleware('admin.permission:users.view');
    Route::match(['put', 'patch'], '/users/{user}', [AdminUserController::class, 'update'])
        ->middleware('admin.permission:users.manage');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])
        ->middleware('admin.permission:users.manage');
    Route::get('/users/{user}/activity', [AdminUserController::class, 'activity'])
        ->middleware('admin.permission:users.view');

    Route::apiResource('roles', AdminRoleController::class)
        ->except(['create', 'edit'])
        ->middlewareFor(['index', 'show'], 'admin.permission:roles.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'admin.permission:roles.manage');

    Route::apiResource('permissions', AdminPermissionController::class)
        ->except(['create', 'edit'])
        ->middlewareFor(['index', 'show'], 'admin.permission:permissions.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'admin.permission:permissions.manage');

    Route::get('/logs', [SystemLogController::class, 'index'])
        ->middleware('admin.permission:logs.view');
    Route::get('/logs/{log}', [SystemLogController::class, 'show'])
        ->middleware('admin.permission:logs.view');
});
