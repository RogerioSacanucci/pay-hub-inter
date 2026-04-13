<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_pushcut_urls', function (Blueprint $table) {
            $table->boolean('admin_only')->default(false)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('user_pushcut_urls', function (Blueprint $table) {
            $table->dropColumn('admin_only');
        });
    }
};
