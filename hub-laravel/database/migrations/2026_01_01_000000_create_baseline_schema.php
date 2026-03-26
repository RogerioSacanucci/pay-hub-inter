<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('payer_email');
            $table->string('payer_name')->default('');
            $table->string('success_url', 500)->default('');
            $table->string('failed_url', 500)->default('');
            $table->string('pushcut_url', 500)->default('');
            $table->enum('role', ['user', 'admin'])->default('user');
            $table->enum('pushcut_notify', ['all', 'created', 'paid'])->default('all');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->enum('method', ['mbway', 'multibanco']);
            $table->enum('status', ['PENDING', 'COMPLETED', 'FAILED', 'EXPIRED', 'DECLINED'])->default('PENDING');
            $table->string('payer_email')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_document', 50)->nullable();
            $table->string('payer_phone', 20)->nullable();
            $table->string('reference_entity', 20)->nullable();
            $table->string('reference_number', 50)->nullable();
            $table->string('reference_expires_at', 50)->nullable();
            $table->json('callback_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('users');
    }
};
