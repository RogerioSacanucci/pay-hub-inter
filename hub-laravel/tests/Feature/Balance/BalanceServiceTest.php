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

        // amount is already net; reserve = 100 * 0.05 = 5; pending = 100 * 0.95 = 95
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(95.0, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001);
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_credit_pending_accumulates_on_existing_balance(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 50, 'balance_released' => 0, 'balance_reserve' => 0]);

        $order = CartpandaOrder::factory()->for($user)->create(['amount' => 200]);

        $this->service->creditPending($user, $order);

        // reserve = 200 * 0.05 = 10; pending = 200 * 0.95 = 190
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(240.0, (float) $balance->balance_pending, 0.001); // 50 + 190
        $this->assertEqualsWithDelta(10.0, (float) $balance->balance_reserve, 0.001);
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

        // reserve = 40 * 0.05 = 2; pending = 40 * 0.95 = 38
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(62.0, (float) $balance->balance_pending, 0.001); // 100 - 38
        $this->assertEqualsWithDelta(8.0, (float) $balance->balance_reserve, 0.001); // 10 - 2
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

        // reserve = 100 * 0.05 = 5; released_debit = 100 * 0.95 = 95
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(0.0, (float) $balance->balance_pending);
        $this->assertEqualsWithDelta(105.0, (float) $balance->balance_released, 0.001); // 200 - 95
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001); // 10 - 5
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

        // reserve = 50 * 0.05 = 2.5; released_debit = 50 * 0.95 = 47.5
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(-37.5, (float) $balance->balance_released, 0.001); // 10 - 47.5
        $this->assertEqualsWithDelta(-0.5, (float) $balance->balance_reserve, 0.001); // 2 - 2.5
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

        // netAmount = 100 * 0.95 = 95
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_pending, 0.001); // 100 - 95
        $this->assertEqualsWithDelta(145.0, (float) $balance->balance_released, 0.001); // 50 + 95
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
