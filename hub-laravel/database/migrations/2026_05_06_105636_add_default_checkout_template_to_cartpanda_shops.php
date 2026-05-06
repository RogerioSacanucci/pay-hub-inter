<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartpanda_shops', function (Blueprint $table) {
            $table->string('default_checkout_template', 500)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('cartpanda_shops', function (Blueprint $table) {
            $table->dropColumn('default_checkout_template');
        });
    }
};
