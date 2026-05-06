<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->index(['shop_id', 'status', 'created_at'], 'cartpanda_orders_shop_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->dropIndex('cartpanda_orders_shop_status_created_idx');
        });
    }
};
