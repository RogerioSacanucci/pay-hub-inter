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
        $shops = $user->shops()->orderBy('cartpanda_shops.id')->get(['cartpanda_shops.id']);

        if ($shops->count() <= 1) {
            return response()->json(['shop_balances' => []]);
        }

        $shopBalances = DB::table('cartpanda_orders')
            ->where('cartpanda_orders.user_id', $user->id)
            ->whereIn('cartpanda_orders.status', ['COMPLETED', 'DECLINED'])
            ->whereNotNull('cartpanda_orders.shop_id')
            ->leftJoinSub(
                DB::table('payout_logs')
                    ->where('user_id', $user->id)
                    ->whereNotNull('shop_id')
                    ->groupBy('shop_id')
                    ->selectRaw('shop_id, SUM(amount) as total_payouts'),
                'payouts',
                'payouts.shop_id',
                '=',
                'cartpanda_orders.shop_id'
            )
            ->groupBy('cartpanda_orders.shop_id')
            ->selectRaw('
                cartpanda_orders.shop_id,
                SUM(CASE WHEN cartpanda_orders.status = \'COMPLETED\' AND cartpanda_orders.released_at IS NULL THEN cartpanda_orders.amount - COALESCE(cartpanda_orders.reserve_amount, 0) ELSE 0 END) as balance_pending,
                SUM(CASE WHEN cartpanda_orders.status = \'COMPLETED\' AND cartpanda_orders.released_at IS NOT NULL THEN cartpanda_orders.amount - COALESCE(cartpanda_orders.reserve_amount, 0) ELSE 0 END)
                + COALESCE(MAX(payouts.total_payouts), 0)
                - SUM(CASE WHEN cartpanda_orders.status = \'DECLINED\' THEN COALESCE(cartpanda_orders.chargeback_penalty, 0) ELSE 0 END) as balance_released,
                SUM(CASE WHEN cartpanda_orders.status = \'COMPLETED\' THEN COALESCE(cartpanda_orders.reserve_amount, 0) ELSE 0 END) as balance_reserve
            ')
            ->get()
            ->keyBy('shop_id');

        return response()->json([
            'shop_balances' => $shops->values()->map(fn ($shop, $i) => [
                'account_index' => $i + 1,
                'shop_id' => $shop->id,
                'balance_pending' => round((float) ($shopBalances[$shop->id]?->balance_pending ?? 0), 2),
                'balance_released' => round((float) ($shopBalances[$shop->id]?->balance_released ?? 0), 2),
                'balance_reserve' => round((float) ($shopBalances[$shop->id]?->balance_reserve ?? 0), 2),
            ]),
        ]);
    }
}
