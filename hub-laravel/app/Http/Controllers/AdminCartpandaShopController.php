<?php

namespace App\Http\Controllers;

use App\Models\CartpandaShop;
use App\Models\UserBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCartpandaShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->query('period', '30d');
        [$dateFrom, $dateTo] = $this->parsePeriodDates($period, $request);

        $shops = DB::table('cartpanda_shops as s')
            ->selectRaw('
                s.id,
                s.shop_slug,
                s.name,
                (SELECT COUNT(*) FROM cartpanda_shop_user WHERE shop_id = s.id) as users_count,
                COUNT(o.id) as orders_count,
                SUM(CASE WHEN o.status = \'COMPLETED\' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN o.status = \'COMPLETED\' THEN o.amount ELSE 0 END) as total_volume
            ')
            ->leftJoin('cartpanda_orders as o', function ($join) use ($dateFrom, $dateTo) {
                $join->on('o.shop_id', '=', 's.id')
                    ->whereBetween('o.created_at', [$dateFrom, $dateTo]);
            })
            ->groupBy('s.id', 's.shop_slug', 's.name')
            ->orderBy('s.name')
            ->get();

        return response()->json([
            'data' => $shops->map(fn ($s) => [
                'id' => $s->id,
                'shop_slug' => $s->shop_slug,
                'name' => $s->name,
                'users_count' => (int) $s->users_count,
                'orders_count' => (int) $s->orders_count,
                'completed' => (int) $s->completed,
                'total_volume' => (float) $s->total_volume,
            ]),
            'period' => $period,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $shop = CartpandaShop::findOrFail($id);
        $period = $request->query('period', '30d');
        [$dateFrom, $dateTo, $hourly] = $this->parsePeriod($period, $request);

        $aggregate = DB::table('cartpanda_orders')
            ->where('shop_id', $shop->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw("
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'DECLINED' THEN 1 ELSE 0 END) as declined,
                SUM(CASE WHEN status = 'REFUNDED' THEN 1 ELSE 0 END) as refunded,
                SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END) as total_volume
            ")
            ->first();

        $driver = DB::getDriverName();
        if ($hourly) {
            $chartGroup = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d %H:00', created_at)"
                : "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')";
        } else {
            $chartGroup = $driver === 'sqlite' ? 'date(created_at)' : 'DATE(created_at)';
        }

        $chart = DB::table('cartpanda_orders')
            ->where('shop_id', $shop->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw("{$chartGroup} as period_label, COUNT(*) as orders, SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END) as volume")
            ->groupByRaw($chartGroup)
            ->orderByRaw($chartGroup)
            ->get();

        $userStats = DB::table('users as u')
            ->join('cartpanda_shop_user as su', 'su.user_id', '=', 'u.id')
            ->leftJoin('cartpanda_orders as o', function ($join) use ($shop, $dateFrom, $dateTo) {
                $join->on('o.user_id', '=', 'u.id')
                    ->where('o.shop_id', '=', $shop->id)
                    ->whereBetween('o.created_at', [$dateFrom, $dateTo]);
            })
            ->where('su.shop_id', $shop->id)
            ->selectRaw("
                u.id,
                u.email,
                u.payer_name,
                COUNT(o.id) as orders_count,
                SUM(CASE WHEN o.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN o.status = 'COMPLETED' THEN o.amount ELSE 0 END) as total_volume
            ")
            ->groupBy('u.id', 'u.email', 'u.payer_name')
            ->get();

        $balances = UserBalance::whereIn('user_id', $userStats->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return response()->json([
            'shop' => [
                'id' => $shop->id,
                'shop_slug' => $shop->shop_slug,
                'name' => $shop->name,
            ],
            'aggregate' => [
                'total_orders' => (int) ($aggregate->total_orders ?? 0),
                'completed' => (int) ($aggregate->completed ?? 0),
                'pending' => (int) ($aggregate->pending ?? 0),
                'failed' => (int) ($aggregate->failed ?? 0),
                'declined' => (int) ($aggregate->declined ?? 0),
                'refunded' => (int) ($aggregate->refunded ?? 0),
                'total_volume' => (float) ($aggregate->total_volume ?? 0),
            ],
            'chart' => $chart->map(fn ($r) => [
                ($hourly ? 'hour' : 'date') => $r->period_label,
                'orders' => (int) $r->orders,
                'volume' => (float) $r->volume,
            ]),
            'users' => $userStats->map(fn ($u) => [
                'id' => $u->id,
                'email' => $u->email,
                'payer_name' => $u->payer_name,
                'orders_count' => (int) $u->orders_count,
                'completed' => (int) $u->completed,
                'total_volume' => (float) $u->total_volume,
                'balance_pending' => $balances->get($u->id)?->balance_pending ?? '0.000000',
                'balance_released' => $balances->get($u->id)?->balance_released ?? '0.000000',
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

    /**
     * @return array{0: string, 1: string}
     */
    private function parsePeriodDates(string $period, Request $request): array
    {
        [$from, $to] = $this->parsePeriod($period, $request);

        return [$from, $to];
    }
}
