<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mundpay_webhook_logs', function (Blueprint $table) {
            $table->index('mundpay_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('mundpay_webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['mundpay_order_id']);
        });
    }
};
