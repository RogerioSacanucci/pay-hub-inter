<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartpanda_orders', function (Blueprint $table) {
            $table->id();
            $table->string('cartpanda_order_id')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 12, 6);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['PENDING', 'COMPLETED', 'FAILED', 'DECLINED', 'REFUNDED'])->default('PENDING');
            $table->string('event', 50);
            $table->string('payer_email')->nullable();
            $table->string('payer_name')->nullable();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartpanda_orders');
    }
};
