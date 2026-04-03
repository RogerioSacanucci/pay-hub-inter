<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function shops(Request $request): JsonResponse
    {
        $user = $request->user();
        $shops = $user->shops()->get(['cartpanda_shops.id']);

        if ($shops->count() <= 1) {
            return response()->json(['shop_balances' => []]);
        }

        $shopBalances = DB::table('cartpanda_orders')
            ->where('user_id', $user->id)
            ->where('status', 'COMPLETED')
            ->whereNotNull('shop_id')
            ->groupBy('shop_id')
            ->selectRaw('
                shop_id,
                SUM(CASE WHEN released_at IS NULL THEN amount ELSE 0 END) * 0.95 as balance_pending,
                SUM(CASE WHEN released_at IS NOT NULL THEN amount ELSE 0 END) * 0.95 as balance_released,
                SUM(amount) * 0.05 as balance_reserve
            ')
            ->get()
            ->keyBy('shop_id');

        $index = 0;

        return response()->json([
            'shop_balances' => $shops->map(fn ($shop) => [
                'account_index' => ++$index,
                'shop_id' => $shop->id,
                'balance_pending' => round((float) ($shopBalances[$shop->id]->balance_pending ?? 0), 2),
                'balance_released' => round((float) ($shopBalances[$shop->id]->balance_released ?? 0), 2),
                'balance_reserve' => round((float) ($shopBalances[$shop->id]->balance_reserve ?? 0), 2),
            ]),
        ]);
    }
}
