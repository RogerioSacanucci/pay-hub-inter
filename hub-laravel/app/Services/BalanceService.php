<?php

namespace App\Services;

use App\Models\CartpandaOrder;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;

class BalanceService
{
    private const FEE_RATE = 0.085;

    private const RESERVE_RATE = 0.05;

    /**
     * Credit balance_pending and balance_reserve when order.paid is received.
     * Applies fee deduction (8.5%) then splits into reserve (5%) and pending (95%).
     */
    public function creditPending(User $user, CartpandaOrder $order): void
    {
        $this->ensureBalanceExists($user);

        $afterFee = (float) $order->amount * (1 - self::FEE_RATE);
        $reserve = $afterFee * self::RESERVE_RATE;
        $pending = $afterFee - $reserve;

        UserBalance::where('user_id', $user->id)
            ->increment('balance_pending', $pending, ['updated_at' => now()]);
        UserBalance::where('user_id', $user->id)
            ->increment('balance_reserve', $reserve, ['updated_at' => now()]);
    }

    /**
     * Debit on chargeback or refund.
     * Reverses both pending/released and reserve proportionally.
     */
    public function debitOnChargeback(User $user, CartpandaOrder $order): void
    {
        $this->ensureBalanceExists($user);

        $afterFee = (float) $order->amount * (1 - self::FEE_RATE);
        $reserveAmount = $afterFee * self::RESERVE_RATE;
        $pendingAmount = $afterFee - $reserveAmount;

        $column = $order->released_at !== null ? 'balance_released' : 'balance_pending';

        UserBalance::where('user_id', $user->id)
            ->decrement($column, $pendingAmount, ['updated_at' => now()]);
        UserBalance::where('user_id', $user->id)
            ->decrement('balance_reserve', $reserveAmount, ['updated_at' => now()]);
    }

    /**
     * Move net amount from pending → released and set released_at.
     * Uses the fee/reserve-adjusted amount (not raw order amount).
     * Expected to be called inside a DB::transaction().
     */
    public function release(CartpandaOrder $order): void
    {
        $afterFee = (float) $order->amount * (1 - self::FEE_RATE);
        $amount = $afterFee * (1 - self::RESERVE_RATE);

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
    public function payout(User $user, User $admin, float $amount, string $type, ?string $note, ?int $shopId = null): PayoutLog
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
            'shop_id' => $shopId,
            'amount' => $logAmount,
            'type' => $type,
            'note' => $note,
        ]);
    }

    private function ensureBalanceExists(User $user): void
    {
        UserBalance::firstOrCreate(
            ['user_id' => $user->id],
            ['balance_pending' => 0, 'balance_released' => 0, 'balance_reserve' => 0, 'currency' => 'USD']
        );
    }
}
