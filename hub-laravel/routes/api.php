<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/create-payment', [PaymentController::class, 'create']);
Route::get('/check-status', [PaymentController::class, 'checkStatus']);
Route::post('/webhook', [WebhookController::class, 'handle']);

// Authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/update', [AuthController::class, 'update']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/auth/users', [UserController::class, 'index'])->middleware(AdminMiddleware::class);
});
