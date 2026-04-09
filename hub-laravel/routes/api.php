<?php

use App\Http\Controllers\AdminAaPanelConfigController;
use App\Http\Controllers\AdminCartpandaShopController;
use App\Http\Controllers\AdminEmailInstanceController;
use App\Http\Controllers\AdminEmailServiceController;
use App\Http\Controllers\AdminMilestoneController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminUserLinkController;
use App\Http\Controllers\AdminUserShopController;
use App\Http\Controllers\AdminWebhookLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\CartpandaOrderController;
use App\Http\Controllers\CartpandaStatsController;
use App\Http\Controllers\CartpandaWebhookController;
use App\Http\Controllers\MilestoneProgressController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutsController;
use App\Http\Controllers\PushcutUrlController;
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
    Route::apiResource('pushcut-urls', PushcutUrlController::class)->except(['show']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/internacional-orders', [CartpandaOrderController::class, 'index']);
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/internacional-stats', [CartpandaStatsController::class, 'index']);
    Route::get('/balance', [BalanceController::class, 'index']);
    Route::get('/balance/shops', [BalanceController::class, 'shops']);
    Route::get('/payouts', [PayoutsController::class, 'index']);
    Route::get('/milestones/progress', [MilestoneProgressController::class, 'index']);
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
        Route::get('admin/payouts', [AdminPayoutController::class, 'index']);
        Route::get('admin/users/{user}/balance', [AdminPayoutController::class, 'show']);
        Route::post('admin/users/{user}/payout', [AdminPayoutController::class, 'store']);
        Route::get('admin/internacional-shops', [AdminCartpandaShopController::class, 'index']);
        Route::get('admin/internacional-shops/{shop}', [AdminCartpandaShopController::class, 'show']);
        Route::get('admin/webhook-logs', [AdminWebhookLogController::class, 'index']);
        Route::apiResource('admin/email-instances', AdminEmailInstanceController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('admin/email-service/logs', [AdminEmailServiceController::class, 'logs']);
        Route::get('admin/email-service/stats', [AdminEmailServiceController::class, 'stats']);
        Route::get('admin/email-service/users', [AdminEmailServiceController::class, 'users']);
        Route::apiResource('admin/milestones', AdminMilestoneController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});
