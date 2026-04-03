<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->index(['user_id', 'shop_id', 'status'], 'cartpanda_orders_user_shop_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->dropIndex('cartpanda_orders_user_shop_status_idx');
        });
    }
};
