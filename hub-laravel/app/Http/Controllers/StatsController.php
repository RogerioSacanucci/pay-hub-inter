<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->query('period', '30d');

        [$dateFrom, $dateTo, $hourly] = $this->parsePeriod($period, $request);

        $base = DB::table('transactions')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if (! $user->isAdmin()) {
            $base->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $base->where('user_id', (int) $request->query('user_id'));
        }

        $overview = (clone $base)->selectRaw("
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status IN ('FAILED','EXPIRED') THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status='DECLINED' THEN 1 ELSE 0 END) as declined,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as total_volume,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as completed_volume,
            SUM(CASE WHEN status='COMPLETED' AND method='mbway' THEN amount ELSE 0 END) as mbway_volume,
            SUM(CASE WHEN status='COMPLETED' AND method='multibanco' THEN amount ELSE 0 END) as multibanco_volume,
            SUM(CASE WHEN status='PENDING' THEN amount ELSE 0 END) as pending_volume,
            ROUND(100.0 * SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 1) as conversion_rate,
            ROUND(100.0 * SUM(CASE WHEN status='DECLINED' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 1) as declined_rate
        ")->first();

        $conversions = (clone $base)->selectRaw("
            amount,
            COUNT(*) as generated,
            SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) as paid,
            ROUND(100.0 * SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 1) as conversion
        ")->groupBy('amount')->orderByDesc('amount')->get();

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
            COUNT(*) as transactions,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as volume
        ")->groupByRaw($chartGroup)->orderByRaw($chartGroup)->get();

        $methods = (clone $base)->selectRaw("
            method,
            COUNT(*) as count,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as volume
        ")->groupBy('method')->get();

        return response()->json([
            'overview' => [
                'total_transactions' => (int) ($overview->total_transactions ?? 0),
                'completed' => (int) ($overview->completed ?? 0),
                'pending' => (int) ($overview->pending ?? 0),
                'failed' => (int) ($overview->failed ?? 0),
                'declined' => (int) ($overview->declined ?? 0),
                'total_volume' => (float) ($overview->total_volume ?? 0),
                'completed_volume' => (float) ($overview->completed_volume ?? 0),
                'mbway_volume' => (float) ($overview->mbway_volume ?? 0),
                'multibanco_volume' => (float) ($overview->multibanco_volume ?? 0),
                'pending_volume' => (float) ($overview->pending_volume ?? 0),
                'conversion_rate' => (float) ($overview->conversion_rate ?? 0),
                'declined_rate' => (float) ($overview->declined_rate ?? 0),
            ],
            'chart' => $chart->map(fn ($r) => [
                ($hourly ? 'hour' : 'date') => $r->period_label,
                'transactions' => (int) $r->transactions,
                'volume' => (float) $r->volume,
            ]),
            'methods' => $methods->map(fn ($r) => [
                'method' => $r->method,
                'count' => (int) $r->count,
                'volume' => (float) $r->volume,
            ]),
            'conversions' => $conversions->map(fn ($r) => [
                'amount' => (float) $r->amount,
                'generated' => (int) $r->generated,
                'paid' => (int) $r->paid,
                'conversion' => (float) $r->conversion,
            ]),
            'period' => $period,
            'hourly' => $hourly,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function parsePeriod(string $period, Request $request): array
    {
        $now = now();
        $hourly = false;

        switch ($period) {
            case 'today':
                $from = $now->copy()->startOfDay();
                $to = $now->copy()->endOfDay();
                $hourly = true;
                break;
            case 'yesterday':
                $from = $now->copy()->subDay()->startOfDay();
                $to = $now->copy()->subDay()->endOfDay();
                $hourly = true;
                break;
            case '7d':
                $from = $now->copy()->subDays(7)->startOfDay();
                $to = $now->copy()->endOfDay();
                break;
            case 'custom':
                $request->validate([
                    'date_from' => ['required', 'date_format:Y-m-d'],
                    'date_to' => ['required', 'date_format:Y-m-d'],
                ]);
                $from = $request->query('date_from').' 00:00:00';
                $to = $request->query('date_to').' 23:59:59';
                break;
            default: // 30d
                $from = $now->copy()->subDays(30)->startOfDay();
                $to = $now->copy()->endOfDay();
        }

        return [$from, $to, $hourly];
    }
}
