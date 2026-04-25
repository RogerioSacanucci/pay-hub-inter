<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_events_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tiktok_pixel_id')->constrained('tiktok_pixels')->cascadeOnDelete();
            $table->string('cartpanda_order_id', 64);
            $table->string('event', 50);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->integer('tiktok_code')->nullable();
            $table->text('tiktok_message')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['tiktok_pixel_id', 'created_at']);
            $table->index('cartpanda_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_events_log');
    }
};
