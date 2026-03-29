<?php

use App\Http\Controllers\AdminAaPanelConfigController;
use App\Http\Controllers\AdminCartpandaShopController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminUserLinkController;
use App\Http\Controllers\AdminUserShopController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\CartpandaOrderController;
use App\Http\Controllers\CartpandaStatsController;
use App\Http\Controllers\CartpandaWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserLinkController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

// Public
Route::middleware('throttle:auth')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
});
Route::post('/create-payment', [PaymentController::class, 'create']);
Route::get('/check-status', [PaymentController::class, 'checkStatus']);
Route::post('/webhook', [WebhookController::class, 'handle']);
Route::post('/cartpanda-webhook', [CartpandaWebhookController::class, 'handle']);

// Authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/update', [AuthController::class, 'update']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/cartpanda-orders', [CartpandaOrderController::class, 'index']);
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/cartpanda-stats', [CartpandaStatsController::class, 'index']);
    Route::get('/balance', [BalanceController::class, 'index']);
    Route::get('/auth/users', [UserController::class, 'index'])->middleware(AdminMiddleware::class);

    Route::get('/links', [UserLinkController::class, 'index']);
    Route::get('/links/{link}/content', [UserLinkController::class, 'getContent']);
    Route::put('/links/{link}/content', [UserLinkController::class, 'saveContent']);

    Route::middleware(AdminMiddleware::class)->group(function () {
        Route::apiResource('admin/users', AdminUserController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('admin/aapanel-configs', AdminAaPanelConfigController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('admin/user-links', AdminUserLinkController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('admin/users/{user}/shops', [AdminUserShopController::class, 'store']);
        Route::delete('admin/users/{user}/shops/{shop}', [AdminUserShopController::class, 'destroy']);
        Route::get('admin/users/{user}/balance', [AdminPayoutController::class, 'show']);
        Route::post('admin/users/{user}/payout', [AdminPayoutController::class, 'store']);
        Route::get('admin/cartpanda-shops', [AdminCartpandaShopController::class, 'index']);
        Route::get('admin/cartpanda-shops/{shop}', [AdminCartpandaShopController::class, 'show']);
    });
});
