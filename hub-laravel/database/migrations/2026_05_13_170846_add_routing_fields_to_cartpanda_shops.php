<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_shops', function (Blueprint $table) {
            $table->boolean('active_for_routing')->default(false)->after('daily_cap');
            $table->unsignedInteger('routing_priority')->nullable()->after('active_for_routing');
            $table->string('ck_url', 500)->nullable()->after('routing_priority');
            $table->index(['active_for_routing', 'routing_priority'], 'idx_cartpanda_shops_routing');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_shops', function (Blueprint $table) {
            $table->dropIndex('idx_cartpanda_shops_routing');
            $table->dropColumn(['active_for_routing', 'routing_priority', 'ck_url']);
        });
    }
};
