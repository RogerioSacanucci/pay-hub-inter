<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_pool_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_pool_id')->constrained('shop_pools')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('cartpanda_shops');
            $table->string('checkout_template', 500);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->decimal('daily_cap', 12, 2)->nullable();
            $table->boolean('is_overflow')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->index(['shop_pool_id', 'active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_pool_targets');
    }
};
