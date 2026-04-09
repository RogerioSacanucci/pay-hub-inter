<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE payout_logs SET created_at = DATE_SUB(created_at, INTERVAL 3 HOUR)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE payout_logs SET created_at = DATE_ADD(created_at, INTERVAL 3 HOUR)');
        }
    }
};
