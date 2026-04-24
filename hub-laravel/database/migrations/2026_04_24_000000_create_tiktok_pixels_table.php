<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_pixels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('pixel_code', 64);
            $table->text('access_token');
            $table->string('label', 100)->nullable();
            $table->string('test_event_code', 50)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'pixel_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_pixels');
    }
};
