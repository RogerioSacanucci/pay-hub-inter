<?php

namespace App\Http\Controllers;

use App\Models\UserBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CartpandaStatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->query('period', '30d');

        [$dateFrom, $dateTo, $hourly] = $this->parsePeriod($period, $request);

        $base = DB::table('cartpanda_orders')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if (! $user->isAdmin()) {
            $base->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $base->where('user_id', (int) $request->query('user_id'));
        }

        $overview = (clone $base)->selectRaw("
            COUNT(*) as total_orders,
            SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='FAILED' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status='DECLINED' THEN 1 ELSE 0 END) as declined,
            SUM(CASE WHEN status='REFUNDED' THEN 1 ELSE 0 END) as refunded,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as total_volume
        ")->first();

        if ($user->isAdmin() && ! $request->has('user_id')) {
            $balancePending = number_format((float) UserBalance::sum('balance_pending'), 6, '.', '');
            $balanceReserve = number_format((float) UserBalance::sum('balance_reserve'), 6, '.', '');
            $balanceReleased = number_format((float) UserBalance::sum('balance_released'), 6, '.', '');
        } elseif ($user->isAdmin() && $request->has('user_id')) {
            $targetBalance = UserBalance::where('user_id', (int) $request->query('user_id'))->first();
            $balancePending = $targetBalance?->balance_pending ?? '0.000000';
            $balanceReserve = $targetBalance?->balance_reserve ?? '0.000000';
            $balanceReleased = $targetBalance?->balance_released ?? '0.000000';
        } else {
            $userBalance = $user->balance;
            $balancePending = $userBalance?->balance_pending ?? '0.000000';
            $balanceReserve = $userBalance?->balance_reserve ?? '0.000000';
            $balanceReleased = $userBalance?->balance_released ?? '0.000000';
        }

        $driver = DB::getDriverName();
        if ($hourly) {
            $chartGroup = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d %H:00', created_at)"
                : "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')";
        } else {
            $chartGroup = $driver === 'sqlite' ? 'date(created_at)' : 'DATE(created_at)';
        }

        $chart = (clone $base)->selectRaw("
            {$chartGroup} as period_label,
            COUNT(*) as orders,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as volume
        ")->groupByRaw($chartGroup)->orderByRaw($chartGroup)->get();

        return response()->json([
            'overview' => [
                'total_orders' => (int) ($overview->total_orders ?? 0),
                'completed' => (int) ($overview->completed ?? 0),
                'pending' => (int) ($overview->pending ?? 0),
                'failed' => (int) ($overview->failed ?? 0),
                'declined' => (int) ($overview->declined ?? 0),
                'refunded' => (int) ($overview->refunded ?? 0),
                'total_volume' => (float) ($overview->total_volume ?? 0),
                'balance_pending' => (string) $balancePending,
                'balance_reserve' => (string) $balanceReserve,
                'balance_released' => (string) $balanceReleased,
            ],
            'chart' => $chart->map(fn ($r) => [
                ($hourly ? 'hour' : 'date') => $r->period_label,
                'orders' => (int) $r->orders,
                'volume' => (float) $r->volume,
            ]),
            'period' => $period,
            'hourly' => $hourly,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function parsePeriod(string $period, Request $request): array
    {
        $offset = max(-14, min(14, (int) $request->query('utc_offset', 0)));
        $now = now()->addHours($offset);
        $hourly = false;

        switch ($period) {
            case 'today':
                $from = $now->copy()->startOfDay()->subHours($offset);
                $to = $now->copy()->endOfDay()->subHours($offset);
                $hourly = true;
                break;
            case 'yesterday':
                $from = $now->copy()->subDay()->startOfDay()->subHours($offset);
                $to = $now->copy()->subDay()->endOfDay()->subHours($offset);
                $hourly = true;
                break;
            case '7d':
                $from = $now->copy()->subDays(7)->startOfDay()->subHours($offset);
                $to = $now->copy()->endOfDay()->subHours($offset);
                break;
            case 'custom':
                $request->validate([
                    'date_from' => ['required', 'date_format:Y-m-d'],
                    'date_to' => ['required', 'date_format:Y-m-d'],
                ]);
                $from = Carbon::parse($request->query('date_from').' 00:00:00')->subHours($offset);
                $to = Carbon::parse($request->query('date_to').' 23:59:59')->subHours($offset);
                break;
            default: // 30d
                $from = $now->copy()->subDays(30)->startOfDay()->subHours($offset);
                $to = $now->copy()->endOfDay()->subHours($offset);
        }

        return [$from, $to, $hourly];
    }
}
