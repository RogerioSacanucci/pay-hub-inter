<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_shops', function (Blueprint $table) {
            $table->decimal('daily_cap', 12, 2)->nullable()->after('default_checkout_template');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_shops', function (Blueprint $table) {
            $table->dropColumn('daily_cap');
        });
    }
};
