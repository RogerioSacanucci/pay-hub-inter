<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Third reconciliation of user_balances after fixing the debitOnChargeback
     * regression that was debiting balance_released instead of balance_pending
     * when the order had not yet been released.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $caseWhen = fn (string $when, string $then, string $else = '0') => "CASE WHEN {$when} THEN {$then} ELSE {$else} END";

        $completedReleasedNull = "status = 'COMPLETED' AND released_at IS NULL";
        $completedReleased = "status = 'COMPLETED' AND released_at IS NOT NULL";
        $completed = "status = 'COMPLETED'";
        $declined = "status = 'DECLINED'";

        $rows = DB::table('cartpanda_orders')
            ->whereIn('status', ['COMPLETED', 'DECLINED'])
            ->groupBy('user_id')
            ->selectRaw("
                user_id,
                SUM({$caseWhen($completedReleasedNull, 'amount * 0.95')}) as balance_pending,
                SUM({$caseWhen($completedReleased, 'amount * 0.95')}) as released_from_orders,
                SUM({$caseWhen($completed, 'amount * 0.05')}) as balance_reserve,
                SUM({$caseWhen($declined, 'COALESCE(chargeback_penalty, 0)')}) as total_penalties
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
        // Not reversible — data correction
    }
};
