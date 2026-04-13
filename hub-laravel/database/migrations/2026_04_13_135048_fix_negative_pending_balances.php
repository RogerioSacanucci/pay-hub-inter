<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Transfer negative balance_pending to balance_released and zero out pending.
     * Chargebacks/refunds should only affect released, not pending.
     */
    public function up(): void
    {
        DB::table('user_balances')
            ->where('balance_pending', '<', 0)
            ->update([
                'balance_released' => DB::raw('balance_released + balance_pending'),
                'balance_pending' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Not reversible — data correction
    }
};
