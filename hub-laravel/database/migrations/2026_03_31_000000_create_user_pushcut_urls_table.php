<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_pushcut_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('url', 500);
            $table->enum('notify', ['all', 'created', 'paid'])->default('all');
            $table->string('label', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('users')
            ->whereNotNull('pushcut_url')
            ->where('pushcut_url', '!=', '')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('user_pushcut_urls')->insert([
                    'user_id'    => $user->id,
                    'url'        => $user->pushcut_url,
                    'notify'     => $user->pushcut_notify,
                    'created_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pushcut_url', 'pushcut_notify']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pushcut_url', 500)->default('')->after('failed_url');
            $table->enum('pushcut_notify', ['all', 'created', 'paid'])->default('all')->after('pushcut_url');
        });

        DB::table('user_pushcut_urls')->orderBy('id')->each(function ($dest) {
            DB::table('users')->where('id', $dest->user_id)->update([
                'pushcut_url'    => $dest->url,
                'pushcut_notify' => $dest->notify,
            ]);
        });

        Schema::dropIfExists('user_pushcut_urls');
    }
};
