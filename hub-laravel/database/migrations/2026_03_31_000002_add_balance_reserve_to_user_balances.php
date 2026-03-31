<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_balances', function (Blueprint $table) {
            $table->decimal('balance_reserve', 14, 6)->default(0)->after('balance_pending');
        });

        // Backfill reserve from completed orders
        DB::statement("
            UPDATE user_balances ub
            SET balance_reserve = (
                SELECT COALESCE(SUM(amount * 0.915 * 0.05), 0)
                FROM cartpanda_orders
                WHERE user_id = ub.user_id AND status = 'COMPLETED'
            ), updated_at = NOW()
        ");

        // Reduce balance_pending for unreleased completed orders
        DB::statement("
            UPDATE user_balances ub
            SET balance_pending = balance_pending - (
                SELECT COALESCE(SUM(amount * (1 - 0.915 * 0.95)), 0)
                FROM cartpanda_orders
                WHERE user_id = ub.user_id AND status = 'COMPLETED' AND released_at IS NULL
            ), updated_at = NOW()
        ");

        // Reduce balance_released for already released completed orders
        DB::statement("
            UPDATE user_balances ub
            SET balance_released = balance_released - (
                SELECT COALESCE(SUM(amount * (1 - 0.915 * 0.95)), 0)
                FROM cartpanda_orders
                WHERE user_id = ub.user_id AND status = 'COMPLETED' AND released_at IS NOT NULL
            ), updated_at = NOW()
        ");
    }

    public function down(): void
    {
        Schema::table('user_balances', function (Blueprint $table) {
            $table->dropColumn('balance_reserve');
        });
    }
};
