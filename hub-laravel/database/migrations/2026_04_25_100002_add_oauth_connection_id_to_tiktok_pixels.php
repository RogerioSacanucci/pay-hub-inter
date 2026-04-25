<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_pixels', function (Blueprint $table) {
            $table->foreignId('tiktok_oauth_connection_id')
                ->nullable()
                ->after('user_id')
                ->constrained('tiktok_oauth_connections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_pixels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiktok_oauth_connection_id');
        });
    }
};
