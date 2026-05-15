<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mundpay_orders', function (Blueprint $table) {
            $table->id();
            $table->string('mundpay_order_id')->unique();
            $table->string('mundpay_ref')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 12, 6);
            $table->decimal('reserve_amount', 12, 6)->default(0);
            $table->decimal('chargeback_penalty', 12, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['PENDING', 'COMPLETED', 'FAILED', 'DECLINED', 'REFUNDED'])->default('PENDING');
            $table->string('event', 50);
            $table->string('payment_method', 30)->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_phone')->nullable();
            $table->string('payer_document')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('chargeback_at')->nullable();
            $table->timestamp('release_eligible_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'release_eligible_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mundpay_orders');
    }
};
