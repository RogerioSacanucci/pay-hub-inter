<?php

namespace App\Http\Controllers;

use App\Models\PayoutLog;
use App\Models\UserBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $balance = UserBalance::firstOrCreate(
            ['user_id' => $userId],
            ['balance_pending' => 0, 'balance_reserve' => 0, 'balance_released' => 0, 'currency' => 'USD']
        );

        $perPage = 20;
        $page = max(1, $request->integer('page', 1));

        $shopIndexMap = $request->user()
            ->shops()
            ->orderBy('cartpanda_shops.id')
            ->pluck('cartpanda_shops.id')
            ->values()
            ->flip()
            ->map(fn ($i) => $i + 1);

        $query = PayoutLog::where('user_id', $userId)
            ->orderByDesc('created_at');

        $totals = PayoutLog::where('user_id', $userId)
            ->selectRaw('
                SUM(CASE WHEN type = "withdrawal" THEN amount ELSE 0 END) as total_withdrawals,
                SUM(CASE WHEN type = "adjustment" THEN amount ELSE 0 END) as total_adjustments
            ')->first();

        $total = $query->count();
        $logs = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'totals' => [
                'total_withdrawals' => $totals->total_withdrawals ?? '0',
                'total_adjustments' => $totals->total_adjustments ?? '0',
            ],
            'balance' => [
                'balance_pending' => $balance->balance_pending,
                'balance_reserve' => $balance->balance_reserve,
                'balance_released' => $balance->balance_released,
                'currency' => $balance->currency,
            ],
            'payout_logs' => [
                'data' => $logs->map(fn (PayoutLog $log) => [
                    'id' => $log->id,
                    'amount' => $log->amount,
                    'type' => $log->type,
                    'note' => $log->note,
                    'account_index' => $log->shop_id ? ($shopIndexMap[$log->shop_id] ?? null) : null,
                    'created_at' => $log->created_at,
                ]),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'pages' => (int) ceil($total / $perPage),
                ],
            ],
        ]);
    }
}
