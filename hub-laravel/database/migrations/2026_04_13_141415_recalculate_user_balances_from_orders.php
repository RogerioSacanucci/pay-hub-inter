<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recalculate user_balances from orders + payout_logs.
     * Chargebacks/refunds should only affect released, not pending.
     */
    public function up(): void
    {
        $computed = DB::table('cartpanda_orders')
            ->whereIn('status', ['COMPLETED', 'DECLINED'])
            ->groupBy('user_id')
            ->selectRaw('
                user_id,
                SUM(CASE WHEN status = "COMPLETED" AND released_at IS NULL THEN amount * 0.95 ELSE 0 END) as balance_pending,
                SUM(CASE WHEN status = "COMPLETED" AND released_at IS NOT NULL THEN amount * 0.95 ELSE 0 END) as released_from_orders,
                SUM(CASE WHEN status = "COMPLETED" THEN amount * 0.05 ELSE 0 END) as balance_reserve,
                SUM(CASE WHEN status = "DECLINED" THEN COALESCE(chargeback_penalty, 0) ELSE 0 END) as total_penalties
            ')
            ->get();

        foreach ($computed as $row) {
            $payoutTotal = (float) DB::table('payout_logs')
                ->where('user_id', $row->user_id)
                ->sum('amount');

            $released = round((float) $row->released_from_orders + $payoutTotal - (float) $row->total_penalties, 6);
            $pending = round((float) $row->balance_pending, 6);
            $reserve = round((float) $row->balance_reserve, 6);

            DB::table('user_balances')
                ->where('user_id', $row->user_id)
                ->update([
                    'balance_pending' => $pending,
                    'balance_released' => $released,
                    'balance_reserve' => $reserve,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Not reversible — data correction
    }
};
