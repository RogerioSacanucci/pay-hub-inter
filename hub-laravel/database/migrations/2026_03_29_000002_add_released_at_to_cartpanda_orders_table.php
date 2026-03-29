<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('payload');
            $table->index(['status', 'released_at', 'created_at'], 'cartpanda_orders_release_job_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->dropIndex('cartpanda_orders_release_job_idx');
            $table->dropColumn('released_at');
        });
    }
};
