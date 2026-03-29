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
        $order = CartpandaOrder::factory()->for($user)->create(['amount' => 100.50]);

        $this->service->creditPending($user, $order);

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_pending' => 100.500000,
            'balance_released' => 0.000000,
        ]);
    }

    public function test_credit_pending_accumulates_on_existing_balance(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 50, 'balance_released' => 0]);

        $order = CartpandaOrder::factory()->for($user)->create(['amount' => 25]);

        $this->service->creditPending($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(75.0, (float) $balance->balance_pending);
    }

    // --- debitOnChargeback ---

    public function test_debit_on_chargeback_debits_pending_when_not_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 40,
            'released_at' => null,
        ]);

        $this->service->debitOnChargeback($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(60.0, (float) $balance->balance_pending);
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_debit_on_chargeback_debits_released_when_already_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 200]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 75,
            'released_at' => now(),
        ]);

        $this->service->debitOnChargeback($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(0.0, (float) $balance->balance_pending);
        $this->assertEquals(125.0, (float) $balance->balance_released);
    }

    public function test_debit_on_chargeback_can_make_released_negative(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 10]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 50,
            'released_at' => now(),
        ]);

        $this->service->debitOnChargeback($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(-40.0, (float) $balance->balance_released);
    }

    // --- release ---

    public function test_release_moves_amount_from_pending_to_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 50]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 30,
            'released_at' => null,
        ]);

        $this->service->release($order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(70.0, (float) $balance->balance_pending);
        $this->assertEquals(80.0, (float) $balance->balance_released);

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
