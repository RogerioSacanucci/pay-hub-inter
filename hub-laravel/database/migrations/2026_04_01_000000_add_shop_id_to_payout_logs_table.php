<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('admin_user_id')
                ->constrained('cartpanda_shops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });
    }
};
