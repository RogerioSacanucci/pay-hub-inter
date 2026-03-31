<?php

namespace Tests\Feature\Balance;

use App\Models\CartpandaOrder;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BalanceService;
    }

    // --- creditPending ---

    public function test_credit_pending_creates_balance_and_increments(): void
    {
        $user = User::factory()->create();
        $order = CartpandaOrder::factory()->for($user)->create(['amount' => 100]);

        $this->service->creditPending($user, $order);

        // afterFee = 100 * 0.915 = 91.5; reserve = 91.5 * 0.05 = 4.575; pending = 91.5 - 4.575 = 86.925
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(86.925, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(4.575, (float) $balance->balance_reserve, 0.001);
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_credit_pending_accumulates_on_existing_balance(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 50, 'balance_released' => 0, 'balance_reserve' => 0]);

        $order = CartpandaOrder::factory()->for($user)->create(['amount' => 200]);

        $this->service->creditPending($user, $order);

        // afterFee = 200 * 0.915 = 183; reserve = 183 * 0.05 = 9.15; pending = 183 - 9.15 = 173.85
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(223.85, (float) $balance->balance_pending, 0.001); // 50 + 173.85
        $this->assertEqualsWithDelta(9.15, (float) $balance->balance_reserve, 0.001);
    }

    // --- debitOnChargeback ---

    public function test_debit_on_chargeback_debits_pending_and_reserve_when_not_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0, 'balance_reserve' => 10]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 40,
            'released_at' => null,
        ]);

        $this->service->debitOnChargeback($user, $order);

        // afterFee = 40 * 0.915 = 36.6; reserve = 36.6 * 0.05 = 1.83; pending = 36.6 - 1.83 = 34.77
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(65.23, (float) $balance->balance_pending, 0.001); // 100 - 34.77
        $this->assertEqualsWithDelta(8.17, (float) $balance->balance_reserve, 0.001); // 10 - 1.83
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_debit_on_chargeback_debits_released_and_reserve_when_already_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 200, 'balance_reserve' => 10]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100,
            'released_at' => now(),
        ]);

        $this->service->debitOnChargeback($user, $order);

        // afterFee = 100 * 0.915 = 91.5; reserve = 91.5 * 0.05 = 4.575; pending = 91.5 - 4.575 = 86.925
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(0.0, (float) $balance->balance_pending);
        $this->assertEqualsWithDelta(113.075, (float) $balance->balance_released, 0.001); // 200 - 86.925
        $this->assertEqualsWithDelta(5.425, (float) $balance->balance_reserve, 0.001); // 10 - 4.575
    }

    public function test_debit_on_chargeback_can_make_released_negative(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 10, 'balance_reserve' => 2]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 50,
            'released_at' => now(),
        ]);

        $this->service->debitOnChargeback($user, $order);

        // afterFee = 50 * 0.915 = 45.75; reserve = 45.75 * 0.05 = 2.2875; pending = 45.75 - 2.2875 = 43.4625
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(-33.4625, (float) $balance->balance_released, 0.001); // 10 - 43.4625
        $this->assertEqualsWithDelta(-0.2875, (float) $balance->balance_reserve, 0.001); // 2 - 2.2875
    }

    // --- release ---

    public function test_release_moves_net_amount_from_pending_to_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 50, 'balance_reserve' => 5]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100,
            'released_at' => null,
        ]);

        $this->service->release($order);

        // afterFee = 100 * 0.915 = 91.5; netAmount = 91.5 * 0.95 = 86.925
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(13.075, (float) $balance->balance_pending, 0.001); // 100 - 86.925
        $this->assertEqualsWithDelta(136.925, (float) $balance->balance_released, 0.001); // 50 + 86.925
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001); // unchanged

        $order->refresh();
        $this->assertNotNull($order->released_at);
    }

    // --- payout ---

    public function test_payout_withdrawal_debits_released_balance(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 500]);

        $log = $this->service->payout($user, $admin, 200, 'withdrawal', 'Weekly payout');

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(300.0, (float) $balance->balance_released);

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'amount' => -200.000000,
            'type' => 'withdrawal',
            'note' => 'Weekly payout',
        ]);
        $this->assertEquals(-200.0, (float) $log->amount);
    }

    public function test_payout_withdrawal_uses_negative_abs(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 500]);

        $log = $this->service->payout($user, $admin, -150, 'withdrawal', null);

        $this->assertEquals(-150.0, (float) $log->amount);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(350.0, (float) $balance->balance_released);
    }

    public function test_payout_positive_adjustment_credits_released(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 100]);

        $log = $this->service->payout($user, $admin, 50, 'adjustment', 'Bonus');

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(150.0, (float) $balance->balance_released);
        $this->assertEquals(50.0, (float) $log->amount);
    }

    public function test_payout_negative_adjustment_debits_released(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 100]);

        $log = $this->service->payout($user, $admin, -30, 'adjustment', 'Correction');

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(70.0, (float) $balance->balance_released);
        $this->assertEquals(-30.0, (float) $log->amount);
    }

    public function test_payout_creates_balance_if_not_exists(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->service->payout($user, $admin, 100, 'adjustment', null);

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
        ]);
    }
}
