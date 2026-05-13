<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shop_pool_targets');
        Schema::dropIfExists('shop_pools');
        Schema::dropIfExists('affiliate_codes');
    }

    public function down(): void
    {
        // Irreversible: pool/code tables are replaced by global routing
        // (active_for_routing + routing_priority on cartpanda_shops).
    }
};
