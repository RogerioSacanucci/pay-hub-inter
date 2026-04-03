<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50)->nullable();
            $table->string('cartpanda_order_id')->nullable();
            $table->string('shop_slug')->nullable();
            $table->enum('status', ['processed', 'ignored', 'failed']);
            $table->string('status_reason', 255)->nullable();
            $table->json('payload');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
