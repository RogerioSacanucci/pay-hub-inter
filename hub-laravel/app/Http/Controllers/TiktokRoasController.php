<?php

namespace App\Http\Controllers;

use App\Models\CartpandaOrder;
use App\Models\TiktokOauthConnection;
use App\Services\TiktokDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TiktokRoasController extends Controller
{
    public function __construct(private TiktokDiscoveryService $discovery) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        [$dateFrom, $dateTo] = $this->resolveWindow($request);

        // Resolve target user — admin can ?user_id=, regular users see only own
        $targetUserId = $user->isAdmin() && $request->filled('user_id')
            ? (int) $request->query('user_id')
            : $user->id;

        $connections = TiktokOauthConnection::where('user_id', $targetUserId)
            ->where('status', 'active')
            ->get();

        $totalSpend = 0.0;
        $byAdvertiser = [];
        $byDaySpend = [];
        $currency = 'USD';

        // TikTok report API takes Y-m-d strings, not full timestamps. Convert
        // back to local-day strings using the user's offset for parity with
        // the dashboard chart.
        $reportFrom = $dateFrom->format('Y-m-d');
        $reportTo = $dateTo->format('Y-m-d');

        foreach ($connections as $conn) {
            $report = $this->discovery->spendReport($conn, $reportFrom, $reportTo);
            $totalSpend += $report['total_spend'];
            if (! empty($report['currency'])) {
                $currency = $report['currency'];
            }
            foreach ($report['by_advertiser'] as $row) {
                $byAdvertiser[] = [...$row, 'connection_id' => $conn->id];
            }
            foreach ($report['by_day'] as $row) {
                $byDaySpend[$row['date']] = ($byDaySpend[$row['date']] ?? 0) + $row['spend'];
            }
        }

        // CartPanda revenue from completed orders in the same window (UTC)
        $orders = CartpandaOrder::query()
            ->where('user_id', $targetUserId)
            ->where('status', 'COMPLETED')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get(['amount', 'created_at']);

        $totalRevenue = (float) $orders->sum('amount');
        $orderCount = $orders->count();

        $byDayRevenue = [];
        foreach ($orders as $o) {
            $day = Carbon::parse($o->created_at)->format('Y-m-d');
            $byDayRevenue[$day] = ($byDayRevenue[$day] ?? 0) + (float) $o->amount;
        }

        $allDays = array_unique(array_merge(array_keys($byDaySpend), array_keys($byDayRevenue)));
        sort($allDays);
        $byDay = [];
        foreach ($allDays as $day) {
            $spend = (float) ($byDaySpend[$day] ?? 0);
            $revenue = (float) ($byDayRevenue[$day] ?? 0);
            $byDay[] = [
                'date' => $day,
                'spend' => round($spend, 2),
                'revenue' => round($revenue, 2),
                'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            ];
        }

        return response()->json([
            'data' => [
                'date_from' => $reportFrom,
                'date_to' => $reportTo,
                'total_spend' => round($totalSpend, 2),
                'total_revenue' => round($totalRevenue, 2),
                'orders' => $orderCount,
                'roas' => $totalSpend > 0 ? round($totalRevenue / $totalSpend, 2) : null,
                'currency' => $currency,
                'by_advertiser' => $byAdvertiser,
                'by_day' => $byDay,
            ],
        ]);
    }

    /**
     * Resolve the [start, end] window — mirrors CartpandaStatsController::parsePeriod
     * so this card always agrees with the dashboard chart.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(Request $request): array
    {
        $period = (string) $request->query('period', '');
        $offset = max(-14, min(14, (int) $request->query('utc_offset', 0)));
        $dbOffset = (int) round(now()->utcOffset() / 60);
        $effectiveOffset = $offset - $dbOffset;
        $now = now()->addHours($effectiveOffset);

        $explicitFrom = (string) $request->query('date_from', '');
        $explicitTo = (string) $request->query('date_to', '');
        if ($explicitFrom !== '' && $explicitTo !== '') {
            return [
                Carbon::parse($explicitFrom.' 00:00:00')->subHours($effectiveOffset),
                Carbon::parse($explicitTo.' 23:59:59')->subHours($effectiveOffset),
            ];
        }

        switch ($period) {
            case 'today':
                return [
                    $now->copy()->startOfDay()->subHours($effectiveOffset),
                    $now->copy()->endOfDay()->subHours($effectiveOffset),
                ];
            case 'yesterday':
                return [
                    $now->copy()->subDay()->startOfDay()->subHours($effectiveOffset),
                    $now->copy()->subDay()->endOfDay()->subHours($effectiveOffset),
                ];
            case '7d':
                return [
                    $now->copy()->subDays(7)->startOfDay()->subHours($effectiveOffset),
                    $now->copy()->endOfDay()->subHours($effectiveOffset),
                ];
            case '30d':
                return [
                    $now->copy()->subDays(30)->startOfDay()->subHours($effectiveOffset),
                    $now->copy()->endOfDay()->subHours($effectiveOffset),
                ];
            default:
                // No period and no dates → fallback "today"
                return [
                    $now->copy()->startOfDay()->subHours($effectiveOffset),
                    $now->copy()->endOfDay()->subHours($effectiveOffset),
                ];
        }
    }
}
