<?php

namespace App\Http\Controllers;

use App\Models\CartpandaShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
                s.default_checkout_template,
                s.daily_cap,
                s.active_for_routing,
                s.routing_priority,
                s.ck_url,
                (SELECT COUNT(*) FROM cartpanda_shop_user WHERE shop_id = s.id) as users_count,
                COUNT(o.id) as orders_count,
                SUM(CASE WHEN o.status = \'COMPLETED\' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN o.status = \'COMPLETED\' THEN o.amount ELSE 0 END) as total_volume
            ')
            ->leftJoin('cartpanda_orders as o', function ($join) use ($dateFrom, $dateTo) {
                $join->on('o.shop_id', '=', 's.id')
                    ->whereBetween('o.created_at', [$dateFrom, $dateTo]);
            })
            ->groupBy('s.id', 's.shop_slug', 's.name', 's.default_checkout_template', 's.daily_cap', 's.active_for_routing', 's.routing_priority', 's.ck_url')
            ->orderBy('s.name')
            ->get();

        return response()->json([
            'data' => $shops->map(fn ($s) => [
                'id' => $s->id,
                'shop_slug' => $s->shop_slug,
                'name' => $s->name,
                'default_checkout_template' => $s->default_checkout_template,
                'daily_cap' => $s->daily_cap,
                'active_for_routing' => (bool) $s->active_for_routing,
                'routing_priority' => $s->routing_priority !== null ? (int) $s->routing_priority : null,
                'ck_url' => $s->ck_url,
                'users_count' => (int) $s->users_count,
                'orders_count' => (int) $s->orders_count,
                'completed' => (int) $s->completed,
                'total_volume' => (float) $s->total_volume,
            ]),
            'period' => $period,
        ]);
    }

    public function usage(CartpandaShop $shop): JsonResponse
    {
        $periodStart = now()->startOfDay();

        $consumedToday = (float) DB::table('cartpanda_orders')
            ->where('shop_id', $shop->id)
            ->where('status', 'COMPLETED')
            ->where('created_at', '>=', $periodStart)
            ->sum('amount');

        $targets = DB::table('shop_pool_targets as t')
            ->join('shop_pools as p', 'p.id', '=', 't.shop_pool_id')
            ->where('t.shop_id', $shop->id)
            ->where('t.active', true)
            ->select('t.id', 't.shop_pool_id', 't.daily_cap', 't.priority', 't.is_overflow', 't.clicks', 'p.user_id', 'p.name as pool_name')
            ->get();

        $usersWithTarget = DB::table('shop_pool_targets as t')
            ->join('shop_pools as p', 'p.id', '=', 't.shop_pool_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('t.shop_id', $shop->id)
            ->where('t.active', true)
            ->select('u.id', 'u.email', 'u.cartpanda_param')
            ->distinct()
            ->get();

        $cap = $shop->daily_cap !== null ? (float) $shop->daily_cap : null;
        $remaining = $cap !== null ? max(0, $cap - $consumedToday) : null;

        return response()->json([
            'shop' => [
                'id' => $shop->id,
                'shop_slug' => $shop->shop_slug,
                'name' => $shop->name,
                'default_checkout_template' => $shop->default_checkout_template,
                'daily_cap' => $shop->daily_cap,
            ],
            'consumed_today' => $consumedToday,
            'cap' => $cap,
            'remaining' => $remaining,
            'over_cap' => $cap !== null && $consumedToday >= $cap,
            'targets_count' => $targets->count(),
            'users' => $usersWithTarget->map(fn ($u) => [
                'id' => $u->id,
                'email' => $u->email,
                'cartpanda_param' => $u->cartpanda_param,
            ]),
            'targets' => $targets->map(fn ($t) => [
                'id' => $t->id,
                'shop_pool_id' => $t->shop_pool_id,
                'pool_name' => $t->pool_name,
                'pool_user_id' => $t->user_id,
                'priority' => (int) $t->priority,
                'daily_cap' => $t->daily_cap,
                'is_overflow' => (bool) $t->is_overflow,
                'clicks' => (int) $t->clicks,
            ]),
        ]);
    }

    public function update(Request $request, CartpandaShop $shop): JsonResponse
    {
        $data = $request->validate([
            'default_checkout_template' => ['nullable', 'url', 'max:500'],
            'daily_cap' => ['nullable', 'numeric', 'min:0'],
            'active_for_routing' => ['sometimes', 'boolean'],
            'routing_priority' => ['nullable', 'integer', 'min:0'],
            'ck_url' => ['nullable', 'url', 'max:500'],
        ]);

        $shop->update($data);

        return response()->json([
            'data' => [
                'id' => $shop->id,
                'shop_slug' => $shop->shop_slug,
                'name' => $shop->name,
                'default_checkout_template' => $shop->default_checkout_template,
                'daily_cap' => $shop->daily_cap,
                'active_for_routing' => (bool) $shop->active_for_routing,
                'routing_priority' => $shop->routing_priority,
                'ck_url' => $shop->ck_url,
            ],
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

        $userIds = $userStats->pluck('id');

        $shopBalances = DB::table('users')
            ->whereIn('users.id', $userIds)
            ->leftJoinSub(
                DB::table('cartpanda_orders')
                    ->where('shop_id', $shop->id)
                    ->whereIn('status', ['COMPLETED', 'DECLINED'])
                    ->groupBy('user_id')
                    ->selectRaw('
                        user_id,
                        SUM(CASE WHEN status = \'COMPLETED\' AND released_at IS NULL THEN amount - COALESCE(reserve_amount, 0) ELSE 0 END) as balance_pending,
                        SUM(CASE WHEN status = \'COMPLETED\' AND released_at IS NOT NULL THEN amount - COALESCE(reserve_amount, 0) ELSE 0 END)
                        - SUM(CASE WHEN status = \'DECLINED\' THEN COALESCE(chargeback_penalty, 0) ELSE 0 END) as released_from_orders
                    '),
                'orders',
                'orders.user_id',
                '=',
                'users.id'
            )
            ->leftJoinSub(
                DB::table('payout_logs')
                    ->where('shop_id', $shop->id)
                    ->groupBy('user_id')
                    ->selectRaw('user_id, SUM(amount) as total_payouts'),
                'payouts',
                'payouts.user_id',
                '=',
                'users.id'
            )
            ->selectRaw('
                users.id as user_id,
                COALESCE(orders.balance_pending, 0) as balance_pending,
                COALESCE(orders.released_from_orders, 0) + COALESCE(payouts.total_payouts, 0) as balance_released
            ')
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
                'balance_pending' => round((float) ($shopBalances[$u->id]->balance_pending ?? 0), 2),
                'balance_released' => round((float) ($shopBalances[$u->id]->balance_released ?? 0), 2),
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
        $dbOffset = (int) round(now()->utcOffset() / 60);
        $effectiveOffset = $offset - $dbOffset;
        $now = now()->addHours($effectiveOffset);
        $hourly = false;

        switch ($period) {
            case 'today':
                $from = $now->copy()->startOfDay()->subHours($effectiveOffset);
                $to = $now->copy()->endOfDay()->subHours($effectiveOffset);
                $hourly = true;
                break;
            case 'yesterday':
                $from = $now->copy()->subDay()->startOfDay()->subHours($effectiveOffset);
                $to = $now->copy()->subDay()->endOfDay()->subHours($effectiveOffset);
                $hourly = true;
                break;
            case '7d':
                $from = $now->copy()->subDays(7)->startOfDay()->subHours($effectiveOffset);
                $to = $now->copy()->endOfDay()->subHours($effectiveOffset);
                break;
            case 'custom':
                $request->validate([
                    'date_from' => ['required', 'date_format:Y-m-d'],
                    'date_to' => ['required', 'date_format:Y-m-d'],
                ]);
                $from = Carbon::parse($request->query('date_from').' 00:00:00')->subHours($effectiveOffset);
                $to = Carbon::parse($request->query('date_to').' 23:59:59')->subHours($effectiveOffset);
                break;
            default: // 30d
                $from = $now->copy()->subDays(30)->startOfDay()->subHours($effectiveOffset);
                $to = $now->copy()->endOfDay()->subHours($effectiveOffset);
        }

        return [$from, $to, $hourly];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function parsePeriodDates(string $period, Request $request): array
    {
        [$from, $to] = $this->parsePeriod($period, $request);

        return [$from, $to];
    }
}
