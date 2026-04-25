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

        $dateFrom = (string) $request->query('date_from', now()->subDays(7)->format('Y-m-d'));
        $dateTo = (string) $request->query('date_to', now()->format('Y-m-d'));

        // Validate format defensively
        try {
            Carbon::parse($dateFrom);
            Carbon::parse($dateTo);
        } catch (\Throwable) {
            return response()->json(['error' => 'date_from / date_to inválidos'], 422);
        }

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

        foreach ($connections as $conn) {
            $report = $this->discovery->spendReport($conn, $dateFrom, $dateTo);
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

        // CartPanda revenue from completed orders in the same window
        $orders = CartpandaOrder::query()
            ->where('user_id', $targetUserId)
            ->where('status', 'COMPLETED')
            ->whereBetween('created_at', [
                Carbon::parse($dateFrom.' 00:00:00'),
                Carbon::parse($dateTo.' 23:59:59'),
            ])
            ->get(['amount', 'created_at']);

        $totalRevenue = (float) $orders->sum('amount');
        $orderCount = $orders->count();

        $byDayRevenue = [];
        foreach ($orders as $o) {
            $day = Carbon::parse($o->created_at)->format('Y-m-d');
            $byDayRevenue[$day] = ($byDayRevenue[$day] ?? 0) + (float) $o->amount;
        }

        // Combined day series
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
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
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
}
