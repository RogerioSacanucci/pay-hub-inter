<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $balance = $request->user()->balance;

        return response()->json([
            'balance_pending' => $balance?->balance_pending ?? '0.000000',
            'balance_reserve' => $balance?->balance_reserve ?? '0.000000',
            'balance_released' => $balance?->balance_released ?? '0.000000',
            'currency' => $balance?->currency ?? 'USD',
        ]);
    }
}
