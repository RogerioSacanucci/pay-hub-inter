<?php

namespace App\Jobs;

use App\Models\MundpayOrder;
use App\Services\BalanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReleaseMundpayBalanceJob implements ShouldQueue
{
    use Queueable;

    public function handle(BalanceService $balanceService): void
    {
        MundpayOrder::query()
            ->where('status', 'COMPLETED')
            ->whereNull('released_at')
            ->where('release_eligible_at', '<=', now())
            ->chunkById(100, function (Collection $orders) use ($balanceService): void {
                DB::transaction(function () use ($orders, $balanceService): void {
                    foreach ($orders as $order) {
                        $balanceService->releaseMundpay($order);
                    }
                });
            });
    }
}
