<?php

namespace App\Console\Commands;

use App\Models\CartpandaOrder;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RecalculateOrderAmounts extends Command
{
    protected $signature = 'backfill:recalculate-amounts
                            {--user= : Email or ID of a specific user to process}
                            {--dry-run : Preview changes without applying them}';

    protected $description = 'Recalculate order amounts from seller_split_amount in payload and rebuild user balances (removes erroneous 8.5% fee deduction)';

    private const RESERVE_RATE = 0.05;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $userFilter = $this->option('user');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be applied.');
            $this->newLine();
        }

        $users = $this->resolveUsers($userFilter);

        if ($users->isEmpty()) {
            $this->error('No users found.');

            return self::FAILURE;
        }

        $this->info(sprintf('Processing %d user(s).', $users->count()));
        $this->newLine();

        foreach ($users as $user) {
            $this->processUser($user, $dryRun);
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('DRY RUN complete — run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }

    private function processUser(User $user, bool $dryRun): void
    {
        $this->line("<fg=cyan>User:</> {$user->email} (#{$user->id})");

        $orders = CartpandaOrder::where('user_id', $user->id)
            ->whereNotNull('payload')
            ->whereRaw("JSON_EXTRACT(payload, '$.order.all_payments[0].seller_split_amount') IS NOT NULL")
            ->whereRaw("JSON_EXTRACT(payload, '$.order.payment.actual_exchange_rate') IS NOT NULL")
            ->get();

        if ($orders->isEmpty()) {
            $this->line('  <fg=yellow>SKIP</> — no orders with seller_split_amount in payload');

            return;
        }

        /** @var array<int, float> $updatedAmounts map of order.id → new amount */
        $updatedAmounts = [];
        $orderRows = [];

        foreach ($orders as $order) {
            $payload = $order->payload;
            $sellerSplit = (float) ($payload['order']['all_payments'][0]['seller_split_amount'] ?? 0);
            $exchangeRate = (float) ($payload['order']['payment']['actual_exchange_rate'] ?? 0);

            if ($sellerSplit <= 0 || $exchangeRate <= 0) {
                continue;
            }

            $newAmount = round($sellerSplit * $exchangeRate, 6);
            $diff = round($newAmount - (float) $order->amount, 6);

            $orderRows[] = [
                $order->cartpanda_order_id,
                $order->status,
                number_format((float) $order->amount, 6),
                number_format($newAmount, 6),
                ($diff >= 0 ? '+' : '').number_format($diff, 6),
            ];

            $updatedAmounts[$order->id] = $newAmount;
        }

        $this->table(
            ['Order ID', 'Status', 'Amount (wrong)', 'Amount (correct)', 'Diff'],
            $orderRows
        );

        [$currentBalance, $newBalance] = $this->previewBalance($user, $updatedAmounts);

        $this->table(
            ['Balance', 'Current', 'After Fix'],
            [
                ['pending', number_format((float) $currentBalance->balance_pending, 6), number_format($newBalance['pending'], 6)],
                ['reserve', number_format((float) $currentBalance->balance_reserve, 6), number_format($newBalance['reserve'], 6)],
                ['released', number_format((float) $currentBalance->balance_released, 6), number_format($newBalance['released'], 6)],
            ]
        );

        if ($dryRun) {
            return;
        }

        if (! $this->confirm("Apply changes for {$user->email}?")) {
            $this->line('  <fg=yellow>SKIPPED</>');

            return;
        }

        DB::transaction(function () use ($user, $updatedAmounts, $newBalance): void {
            foreach ($updatedAmounts as $orderId => $newAmount) {
                CartpandaOrder::where('id', $orderId)->update(['amount' => $newAmount]);
            }

            UserBalance::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'balance_pending' => $newBalance['pending'],
                    'balance_reserve' => $newBalance['reserve'],
                    'balance_released' => $newBalance['released'],
                    'updated_at' => now(),
                ]
            );
        });

        $this->line('  <fg=green>APPLIED</>');
    }

    /**
     * Calculate current balance and projected balance after fix.
     *
     * @param  array<int, float>  $updatedAmounts  map of order.id → new amount
     * @return array{0: UserBalance, 1: array{pending: float, reserve: float, released: float}}
     */
    private function previewBalance(User $user, array $updatedAmounts): array
    {
        $currentBalance = UserBalance::firstOrCreate(
            ['user_id' => $user->id],
            ['balance_pending' => 0, 'balance_reserve' => 0, 'balance_released' => 0, 'currency' => 'USD']
        );

        $completedOrders = CartpandaOrder::where('user_id', $user->id)
            ->where('status', 'COMPLETED')
            ->get(['id', 'amount', 'released_at']);

        $pendingSum = 0.0;
        $reserveSum = 0.0;
        $releasedOrdersSum = 0.0;

        foreach ($completedOrders as $order) {
            $amount = $updatedAmounts[$order->id] ?? (float) $order->amount;
            $reserveSum += $amount * self::RESERVE_RATE;

            if ($order->released_at === null) {
                $pendingSum += $amount * (1 - self::RESERVE_RATE);
            } else {
                $releasedOrdersSum += $amount * (1 - self::RESERVE_RATE);
            }
        }

        $payoutAdjustment = (float) PayoutLog::where('user_id', $user->id)->sum('amount');

        return [
            $currentBalance,
            [
                'pending' => round($pendingSum, 6),
                'reserve' => round($reserveSum, 6),
                'released' => round($releasedOrdersSum + $payoutAdjustment, 6),
            ],
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(?string $filter): Collection
    {
        if ($filter === null) {
            return User::all();
        }

        $query = is_numeric($filter)
            ? User::where('id', (int) $filter)
            : User::where('email', $filter);

        return $query->get();
    }
}
