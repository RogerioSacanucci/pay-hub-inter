<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->constrained('cartpanda_shops')->nullOnDelete()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_orders', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropColumn('shop_id');
        });
    }
};
