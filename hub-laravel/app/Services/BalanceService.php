<?php

namespace App\Services;

use App\Models\CartpandaOrder;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    private const RESERVE_RATE = 0.05;

    private const CHARGEBACK_PENALTY = 30;

    private const RELEASE_DELAY_DAYS = 2;

    /**
     * Credit balance_pending and balance_reserve when order.paid is received.
     * Amount is already net (seller_split_amount from CartPanda webhook).
     * Splits into reserve (5%) and pending (95%).
     */
    public function creditPending(User $user, CartpandaOrder $order): void
    {
        $this->ensureBalanceExists($user);

        $reserve = (float) $order->amount * self::RESERVE_RATE;
        $pending = (float) $order->amount * (1 - self::RESERVE_RATE);

        UserBalance::where('user_id', $user->id)
            ->increment('balance_pending', $pending, ['updated_at' => now()]);
        UserBalance::where('user_id', $user->id)
            ->increment('balance_reserve', $reserve, ['updated_at' => now()]);
    }

    /**
     * Debit on chargeback or refund.
     * Debits from balance_pending or balance_released based on whether the order
     * was already released. Reserve is always debited.
     *
     * When $applyPenalty is true (chargebacks):
     *   - $30 penalty is always deducted from balance_released
     *   - If the order was still in pending, a "reseguro" moves the most recent
     *     released order of the same shop back to pending (reset release timer)
     */
    public function debitOnChargeback(User $user, CartpandaOrder $order, bool $applyPenalty = false): void
    {
        $this->ensureBalanceExists($user);

        DB::transaction(function () use ($user, $order, $applyPenalty) {
            $locked = CartpandaOrder::where('id', $order->id)->lockForUpdate()->first();
            $wasReleased = $locked->released_at !== null;

            $reserveAmount = (float) $locked->amount * self::RESERVE_RATE;
            $pendingAmount = (float) $locked->amount * (1 - self::RESERVE_RATE);
            $column = $wasReleased ? 'balance_released' : 'balance_pending';

            UserBalance::where('user_id', $user->id)
                ->decrement($column, $pendingAmount, ['updated_at' => now()]);
            UserBalance::where('user_id', $user->id)
                ->decrement('balance_reserve', $reserveAmount, ['updated_at' => now()]);

            if (! $applyPenalty) {
                return;
            }

            UserBalance::where('user_id', $user->id)
                ->decrement('balance_released', self::CHARGEBACK_PENALTY, ['updated_at' => now()]);
            $locked->update(['chargeback_penalty' => self::CHARGEBACK_PENALTY]);
            $order->setRawAttributes($locked->getAttributes());

            if ($wasReleased || $locked->shop_id === null) {
                return;
            }

            $this->applyReseguro($user, $locked);
        });
    }

    /**
     * Move net amount from pending → released and set released_at.
     * Uses the fee/reserve-adjusted amount (not raw order amount).
     * Expected to be called inside a DB::transaction().
     */
    public function release(CartpandaOrder $order): void
    {
        $amount = (float) $order->amount * (1 - self::RESERVE_RATE);

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

    /**
     * Find the most recent released order of the same shop and move it back to pending,
     * resetting the release timer. Skips silently if no candidate exists.
     */
    private function applyReseguro(User $user, CartpandaOrder $chargebackedOrder): void
    {
        $candidate = CartpandaOrder::where('user_id', $user->id)
            ->where('shop_id', $chargebackedOrder->shop_id)
            ->where('status', 'COMPLETED')
            ->whereNotNull('released_at')
            ->where('id', '!=', $chargebackedOrder->id)
            ->orderByDesc('released_at')
            ->lockForUpdate()
            ->first();

        if (! $candidate) {
            return;
        }

        $amount = (float) $candidate->amount * (1 - self::RESERVE_RATE);

        UserBalance::where('user_id', $user->id)
            ->decrement('balance_released', $amount, ['updated_at' => now()]);
        UserBalance::where('user_id', $user->id)
            ->increment('balance_pending', $amount, ['updated_at' => now()]);

        $candidate->update([
            'released_at' => null,
            'release_eligible_at' => now()->addDays(self::RELEASE_DELAY_DAYS),
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
