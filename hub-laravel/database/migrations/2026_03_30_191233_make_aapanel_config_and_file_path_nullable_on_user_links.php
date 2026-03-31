<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_links', function (Blueprint $table) {
            $table->dropForeign(['aapanel_config_id']);
            $table->unsignedBigInteger('aapanel_config_id')->nullable()->change();
            $table->foreign('aapanel_config_id')->references('id')->on('user_aapanel_configs')->nullOnDelete();
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_links', function (Blueprint $table) {
            $table->dropForeign(['aapanel_config_id']);
            $table->unsignedBigInteger('aapanel_config_id')->nullable(false)->change();
            $table->foreign('aapanel_config_id')->references('id')->on('user_aapanel_configs')->cascadeOnDelete();
            $table->string('file_path')->nullable(false)->change();
        });
    }
};
