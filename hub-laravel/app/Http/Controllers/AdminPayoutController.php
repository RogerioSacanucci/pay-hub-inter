<?php

namespace App\Http\Controllers;

use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = PayoutLog::where('user_id', $targetUser->id)->orderByDesc('created_at');
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
            'payout_logs' => [
                'data' => $payoutLogs->map(fn (PayoutLog $log) => [
                    'id' => $log->id,
                    'amount' => $log->amount,
                    'type' => $log->type,
                    'note' => $log->note,
                    'admin_email' => $log->admin?->email,
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
        ]);

        $this->balanceService->payout(
            $targetUser,
            $request->user(),
            (float) $data['amount'],
            $data['type'],
            $data['note'] ?? null,
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
