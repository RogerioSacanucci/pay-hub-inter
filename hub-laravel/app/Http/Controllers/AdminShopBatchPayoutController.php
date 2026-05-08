<?php

namespace App\Http\Controllers;

use App\Models\CartpandaShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminShopBatchPayoutController extends Controller
{
    public function eligibleUsers(CartpandaShop $shop): JsonResponse
    {
        $rows = DB::table('users as u')
            ->join('cartpanda_shop_user as su', function ($join) use ($shop) {
                $join->on('su.user_id', '=', 'u.id')
                    ->where('su.shop_id', '=', $shop->id);
            })
            ->leftJoinSub(
                DB::table('cartpanda_orders')
                    ->where('shop_id', $shop->id)
                    ->whereIn('status', ['COMPLETED', 'DECLINED'])
                    ->groupBy('user_id')
                    ->selectRaw('
                        user_id,
                        SUM(CASE WHEN status = \'COMPLETED\' AND released_at IS NOT NULL THEN amount * 0.95 ELSE 0 END)
                        - SUM(CASE WHEN status = \'DECLINED\' THEN COALESCE(chargeback_penalty, 0) ELSE 0 END) as released_from_orders
                    '),
                'orders',
                'orders.user_id',
                '=',
                'u.id'
            )
            ->leftJoinSub(
                DB::table('payout_logs')
                    ->where('shop_id', $shop->id)
                    ->groupBy('user_id')
                    ->selectRaw('user_id, SUM(amount) as total_payouts'),
                'payouts',
                'payouts.user_id',
                '=',
                'u.id'
            )
            ->selectRaw('
                u.id as user_id,
                u.payer_name as name,
                u.email,
                COALESCE(orders.released_from_orders, 0) + COALESCE(payouts.total_payouts, 0) as balance_released_shop
            ')
            ->groupBy('u.id', 'u.payer_name', 'u.email', 'orders.released_from_orders', 'payouts.total_payouts')
            ->havingRaw('balance_released_shop > 0')
            ->orderByDesc('balance_released_shop')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'email' => $r->email,
                'balance_released_shop' => round((float) $r->balance_released_shop, 2),
            ]),
        ]);
    }
}
