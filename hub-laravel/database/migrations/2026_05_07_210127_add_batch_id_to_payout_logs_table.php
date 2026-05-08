<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->after('shop_id');
            $table->index('batch_id', 'idx_payout_logs_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->dropIndex('idx_payout_logs_batch_id');
            $table->dropColumn('batch_id');
        });
    }
};
