<?php

namespace App\Http\Controllers;

use App\Models\CartpandaShop;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminShopBatchPayoutController extends Controller
{
    public function __construct(private BalanceService $balanceService) {}

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
            // Explicit grouping required for SQLite ONLY_FULL_GROUP_BY compat;
            // structurally a no-op (1 row per user post-join).
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

    public function batchPayout(Request $request, CartpandaShop $shop): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $batchId = (string) Str::uuid();
        $sharedNote = $data['note'] ?? null;
        $success = [];
        $failures = [];

        foreach ($data['items'] as $item) {
            try {
                $user = User::findOrFail($item['user_id']);

                if (! $shop->users()->whereKey($user->id)->exists()) {
                    throw new \DomainException('user_not_assigned_to_shop');
                }

                $log = $this->balanceService->payout(
                    $user,
                    $request->user(),
                    (float) $item['amount'],
                    'withdrawal',
                    $item['note'] ?? $sharedNote,
                    $shop->id,
                    $batchId,
                );

                $success[] = [
                    'user_id' => $user->id,
                    'payout_log_id' => $log->id,
                    'amount' => (float) $item['amount'],
                ];
            } catch (\DomainException $e) {
                $failures[] = [
                    'user_id' => $item['user_id'],
                    'error' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                report($e);
                $failures[] = [
                    'user_id' => $item['user_id'],
                    'error' => 'unexpected_error',
                ];
            }
        }

        return response()->json([
            'batch_id' => $batchId,
            'success' => $success,
            'failures' => $failures,
        ]);
    }
}
