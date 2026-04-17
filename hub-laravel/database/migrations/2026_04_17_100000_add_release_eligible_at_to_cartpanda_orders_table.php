<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->timestamp('release_eligible_at')->nullable()->after('released_at');
            $table->index(['status', 'released_at', 'release_eligible_at'], 'cartpanda_orders_release_eligible_idx');
        });

        $driver = DB::connection()->getDriverName();
        $dateAdd = $driver === 'sqlite'
            ? "datetime(created_at, '+2 days')"
            : 'DATE_ADD(created_at, INTERVAL 2 DAY)';

        DB::statement("
            UPDATE cartpanda_orders
            SET release_eligible_at = CASE
                WHEN released_at IS NOT NULL THEN released_at
                ELSE {$dateAdd}
            END
            WHERE release_eligible_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->dropIndex('cartpanda_orders_release_eligible_idx');
            $table->dropColumn('release_eligible_at');
        });
    }
};
