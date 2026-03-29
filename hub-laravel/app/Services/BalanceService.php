<?php

namespace App\Services;

use App\Models\CartpandaOrder;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;

class BalanceService
{
    /**
     * Credit balance_pending when order.paid is received.
     */
    public function creditPending(User $user, CartpandaOrder $order): void
    {
        $this->ensureBalanceExists($user);

        UserBalance::where('user_id', $user->id)
            ->increment('balance_pending', (float) $order->amount, ['updated_at' => now()]);
    }

    /**
     * Debit on chargeback or refund.
     * If released_at IS NOT NULL: debit balance_released.
     * If released_at IS NULL: debit balance_pending.
     */
    public function debitOnChargeback(User $user, CartpandaOrder $order): void
    {
        $this->ensureBalanceExists($user);

        $column = $order->released_at !== null ? 'balance_released' : 'balance_pending';

        UserBalance::where('user_id', $user->id)
            ->decrement($column, (float) $order->amount, ['updated_at' => now()]);
    }

    /**
     * Move amount from pending → released and set released_at.
     * Expected to be called inside a DB::transaction().
     */
    public function release(CartpandaOrder $order): void
    {
        $amount = (float) $order->amount;

        UserBalance::where('user_id', $order->user_id)
            ->decrement('balance_pending', $amount, ['updated_at' => now()]);

        UserBalance::where('user_id', $order->user_id)
            ->increment('balance_released', $amount, ['updated_at' => now()]);

        $order->update(['released_at' => now()]);
    }

    /**
     * Record a payout (withdrawal or adjustment).
     * Withdrawal: logAmount = -abs(amount) (always debit).
     * Adjustment: logAmount = amount (positive = credit, negative = debit).
     */
    public function payout(User $user, User $admin, float $amount, string $type, ?string $note): PayoutLog
    {
        $this->ensureBalanceExists($user);

        $logAmount = $type === 'withdrawal' ? -abs($amount) : $amount;

        if ($logAmount < 0) {
            UserBalance::where('user_id', $user->id)
                ->decrement('balance_released', abs($logAmount), ['updated_at' => now()]);
        } else {
            UserBalance::where('user_id', $user->id)
                ->increment('balance_released', $logAmount, ['updated_at' => now()]);
        }

        return PayoutLog::create([
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'amount' => $logAmount,
            'type' => $type,
            'note' => $note,
        ]);
    }

    private function ensureBalanceExists(User $user): void
    {
        UserBalance::firstOrCreate(
            ['user_id' => $user->id],
            ['balance_pending' => 0, 'balance_released' => 0, 'currency' => 'USD']
        );
    }
}
