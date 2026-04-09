<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/caramelosectoken', function () {
    return response()->json(['caramelosec-token' => 'd54028ad-53d1-4d05-9606-b6b6e82e5a56']);
});
