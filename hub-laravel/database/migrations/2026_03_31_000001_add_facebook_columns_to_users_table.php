<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('facebook_pixel_id')->nullable()->after('cartpanda_param');
            $table->text('facebook_access_token')->nullable()->after('facebook_pixel_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['facebook_pixel_id', 'facebook_access_token']);
        });
    }
};
