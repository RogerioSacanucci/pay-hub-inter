<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartpanda_shop_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('cartpanda_shops')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['shop_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartpanda_shop_user');
    }
};
