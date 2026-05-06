<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_pool_targets', function (Blueprint $table) {
            $table->string('checkout_template', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shop_pool_targets', function (Blueprint $table) {
            $table->string('checkout_template', 500)->nullable(false)->change();
        });
    }
};
