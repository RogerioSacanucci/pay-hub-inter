<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_events_log', function (Blueprint $table) {
            $table->string('mundpay_order_id')->nullable()->after('cartpanda_order_id');
            $table->index('mundpay_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_events_log', function (Blueprint $table) {
            $table->dropIndex(['mundpay_order_id']);
            $table->dropColumn('mundpay_order_id');
        });
    }
};
