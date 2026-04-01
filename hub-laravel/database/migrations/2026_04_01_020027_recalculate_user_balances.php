<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Skipped on SQLite (tests). Recalculates all balance columns from scratch
        // using cartpanda_orders + payout_logs as authoritative sources.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Recalculate balance_reserve: 5% of after-fee amount per COMPLETED order
        DB::statement("
            UPDATE user_balances ub
            SET balance_reserve = (
                SELECT COALESCE(SUM(
                    CASE
                        WHEN status = 'COMPLETED' THEN amount * 0.915 * 0.05
                        WHEN status IN ('DECLINED', 'REFUNDED') THEN -(amount * 0.915 * 0.05)
                        ELSE 0
                    END
                ), 0)
                FROM cartpanda_orders
                WHERE user_id = ub.user_id
            ),
            updated_at = NOW()
        ");

        // Recalculate balance_pending: 95% of after-fee amount for unreleased COMPLETED orders
        DB::statement("
            UPDATE user_balances ub
            SET balance_pending = GREATEST(0, (
                SELECT COALESCE(SUM(
                    CASE
                        WHEN status = 'COMPLETED' THEN amount * 0.915 * 0.95
                        WHEN status IN ('DECLINED', 'REFUNDED') THEN -(amount * 0.915 * 0.95)
                        ELSE 0
                    END
                ), 0)
                FROM cartpanda_orders
                WHERE user_id = ub.user_id AND released_at IS NULL
            )),
            updated_at = NOW()
        ");

        // Recalculate balance_released: released orders (net) + payout adjustments
        DB::statement("
            UPDATE user_balances ub
            SET balance_released = GREATEST(0, (
                SELECT COALESCE(SUM(
                    CASE
                        WHEN status = 'COMPLETED' THEN amount * 0.915 * 0.95
                        WHEN status IN ('DECLINED', 'REFUNDED') THEN -(amount * 0.915 * 0.95)
                        ELSE 0
                    END
                ), 0)
                FROM cartpanda_orders
                WHERE user_id = ub.user_id AND released_at IS NOT NULL
            ) + (
                SELECT COALESCE(SUM(amount), 0)
                FROM payout_logs
                WHERE user_id = ub.user_id
            )),
            updated_at = NOW()
        ");
    }

    public function down(): void
    {
        // Irreversible — original gross amounts are not stored separately
    }
};
