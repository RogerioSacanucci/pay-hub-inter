<?php

use App\Models\CartpandaOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill cartpanda_orders.reserve_amount from existing payloads.
     * Reserve = seller_allowance_amount × actual_exchange_rate (per CartPanda).
     * If allowance is missing in payload, fall back to amount × 0.05.
     */
    public function up(): void
    {
        $fallbackRate = (float) config('cartpanda.reserve_rate_fallback', 0.05);

        CartpandaOrder::query()
            ->whereNull('reserve_amount')
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($fallbackRate): void {
                DB::transaction(function () use ($orders, $fallbackRate): void {
                    foreach ($orders as $order) {
                        $payload = $order->payload ?? [];
                        $payments = data_get($payload, 'order.all_payments', []);
                        $allowance = (float) data_get($payments, '0.seller_allowance_amount', 0);
                        $xr = (float) data_get($payload, 'order.payment.actual_exchange_rate', 0);

                        $reserve = $allowance > 0 && $xr > 0
                            ? round($allowance * $xr, 6)
                            : round((float) $order->amount * $fallbackRate, 6);

                        CartpandaOrder::where('id', $order->id)->update([
                            'reserve_amount' => $reserve,
                        ]);
                    }
                });
            });
    }

    public function down(): void
    {
        // Not reversible — data correction.
    }
};
