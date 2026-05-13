<?php

use App\Http\Controllers\ClickRouterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/caramelosectoken', function () {
    return response()->json(['caramelosec-token' => 'd54028ad-53d1-4d05-9606-b6b6e82e5a56']);
});

Route::middleware('throttle:click')
    ->get('/r/{cartpanda_param}', [ClickRouterController::class, 'redirect'])
    ->where('cartpanda_param', '[A-Za-z0-9_-]+');
