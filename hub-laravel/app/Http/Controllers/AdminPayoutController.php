<?php

namespace App\Http\Controllers;

use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPayoutController extends Controller
{
    public function __construct(private BalanceService $balanceService) {}

    public function show(Request $request, int $user): JsonResponse
    {
        $targetUser = User::findOrFail($user);

        $balance = UserBalance::firstOrCreate(
            ['user_id' => $targetUser->id],
            ['balance_pending' => 0, 'balance_reserve' => 0, 'balance_released' => 0, 'currency' => 'USD']
        );

        $shops = $targetUser->shops()->get(['cartpanda_shops.id', 'name', 'shop_slug']);

        $shopBalances = collect();
        if ($shops->count() > 1) {
            $shopBalances = DB::table('cartpanda_orders')
                ->where('user_id', $targetUser->id)
                ->where('status', 'COMPLETED')
                ->whereNotNull('shop_id')
                ->groupBy('shop_id')
                ->selectRaw('
                    shop_id,
                    SUM(amount) as gross_volume,
                    SUM(CASE WHEN released_at IS NULL THEN amount ELSE 0 END) * 0.95 as balance_pending,
                    SUM(CASE WHEN released_at IS NOT NULL THEN amount ELSE 0 END) * 0.95 as balance_released,
                    SUM(amount) * 0.05 as balance_reserve
                ')
                ->get()
                ->keyBy('shop_id');
        }

        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = PayoutLog::where('user_id', $targetUser->id)
            ->with('shop:id,name,shop_slug')
            ->orderByDesc('created_at');
        $total = $query->count();
        $payoutLogs = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'balance' => [
                'balance_pending' => $balance->balance_pending,
                'balance_reserve' => $balance->balance_reserve,
                'balance_released' => $balance->balance_released,
                'currency' => $balance->currency,
            ],
            'shop_balances' => $shops->count() > 1
                ? $shops->map(fn ($s) => [
                    'shop_id' => $s->id,
                    'shop_name' => $s->name ?: $s->shop_slug,
                    'gross_volume' => round((float) ($shopBalances[$s->id]->gross_volume ?? 0), 2),
                    'balance_pending' => round((float) ($shopBalances[$s->id]->balance_pending ?? 0), 2),
                    'balance_released' => round((float) ($shopBalances[$s->id]->balance_released ?? 0), 2),
                    'balance_reserve' => round((float) ($shopBalances[$s->id]->balance_reserve ?? 0), 2),
                ])
                : [],
            'payout_logs' => [
                'data' => $payoutLogs->map(fn (PayoutLog $log) => [
                    'id' => $log->id,
                    'amount' => $log->amount,
                    'type' => $log->type,
                    'note' => $log->note,
                    'admin_email' => $log->admin?->email,
                    'shop_name' => $log->shop?->name ?? $log->shop?->shop_slug,
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

    public function store(Request $request, int $user): JsonResponse
    {
        $targetUser = User::findOrFail($user);

        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'type' => ['required', 'in:withdrawal,adjustment'],
            'note' => ['nullable', 'string', 'max:500'],
            'shop_id' => ['nullable', 'integer', 'exists:cartpanda_shops,id'],
        ]);

        $this->balanceService->payout(
            $targetUser,
            $request->user(),
            (float) $data['amount'],
            $data['type'],
            $data['note'] ?? null,
            $data['shop_id'] ?? null,
        );

        $balance = UserBalance::where('user_id', $targetUser->id)->first();

        return response()->json([
            'balance' => [
                'balance_pending' => $balance->balance_pending,
                'balance_reserve' => $balance->balance_reserve,
                'balance_released' => $balance->balance_released,
                'currency' => $balance->currency,
            ],
        ]);
    }
}
