<?php

namespace App\Jobs;

use App\Models\CartpandaOrder;
use App\Services\BalanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReleaseBalanceJob implements ShouldQueue
{
    use Queueable;

    public function handle(BalanceService $balanceService): void
    {
        CartpandaOrder::query()
            ->where('status', 'COMPLETED')
            ->whereNull('released_at')
            ->where('created_at', '<=', now()->subDays(2))
            ->chunkById(100, function (Collection $orders) use ($balanceService): void {
                DB::transaction(function () use ($orders, $balanceService): void {
                    foreach ($orders as $order) {
                        $balanceService->release($order);
                    }
                });
            });
    }
}
