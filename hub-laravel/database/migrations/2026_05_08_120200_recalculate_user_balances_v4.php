<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recalculate user_balances after switching from fixed 5%/95% split to per-order
     * reserve_amount (CartPanda's seller_allowance_amount × XR).
     *
     * Reserve = SUM(reserve_amount) where COMPLETED
     * Pending = SUM(amount - reserve_amount) where COMPLETED + released_at IS NULL
     * Released = SUM(amount - reserve_amount) where COMPLETED + released_at IS NOT NULL
     *          + payout_logs total
     *          - chargeback_penalty (DECLINED)
     */
    public function up(): void
    {
        $rows = DB::table('cartpanda_orders')
            ->whereIn('status', ['COMPLETED', 'DECLINED'])
            ->groupBy('user_id')
            ->selectRaw("
                user_id,
                SUM(CASE WHEN status = 'COMPLETED' AND released_at IS NULL THEN amount - COALESCE(reserve_amount, 0) ELSE 0 END) as balance_pending,
                SUM(CASE WHEN status = 'COMPLETED' AND released_at IS NOT NULL THEN amount - COALESCE(reserve_amount, 0) ELSE 0 END) as released_from_orders,
                SUM(CASE WHEN status = 'COMPLETED' THEN COALESCE(reserve_amount, 0) ELSE 0 END) as balance_reserve,
                SUM(CASE WHEN status = 'DECLINED' THEN COALESCE(chargeback_penalty, 0) ELSE 0 END) as total_penalties
            ")
            ->get();

        foreach ($rows as $row) {
            $payoutTotal = (float) DB::table('payout_logs')
                ->where('user_id', $row->user_id)
                ->sum('amount');

            DB::table('user_balances')
                ->where('user_id', $row->user_id)
                ->update([
                    'balance_pending' => round((float) $row->balance_pending, 6),
                    'balance_released' => round(
                        (float) $row->released_from_orders + $payoutTotal - (float) $row->total_penalties,
                        6
                    ),
                    'balance_reserve' => round((float) $row->balance_reserve, 6),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Not reversible — data correction.
    }
};
