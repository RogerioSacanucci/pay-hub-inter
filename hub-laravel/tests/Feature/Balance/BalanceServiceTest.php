<?php

namespace Tests\Feature\Balance;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
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

    public function test_debit_on_chargeback_with_penalty_deducts_value_from_pending_and_penalty_from_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0, 'balance_reserve' => 10]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 40,
            'released_at' => null,
        ]);

        $this->service->debitOnChargeback($user, $order, applyPenalty: true);

        // value(38) leaves pending; penalty(30) is always deducted from released; reserve -2
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(62.0, (float) $balance->balance_pending, 0.001); // 100 - 38
        $this->assertEqualsWithDelta(8.0, (float) $balance->balance_reserve, 0.001); // 10 - 2
        $this->assertEqualsWithDelta(-30.0, (float) $balance->balance_released, 0.001);

        $order->refresh();
        $this->assertEqualsWithDelta(30.0, (float) $order->chargeback_penalty, 0.001);
    }

    public function test_debit_on_chargeback_with_penalty_deducts_extra_from_released(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 200, 'balance_reserve' => 10]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100,
            'released_at' => now(),
        ]);

        $this->service->debitOnChargeback($user, $order, applyPenalty: true);

        // reserve = 100 * 0.05 = 5; released = 100 * 0.95 = 95; penalty = 30
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(0.0, (float) $balance->balance_pending);
        $this->assertEqualsWithDelta(75.0, (float) $balance->balance_released, 0.001); // 200 - 95 - 30
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001); // 10 - 5

        $order->refresh();
        $this->assertEqualsWithDelta(30.0, (float) $order->chargeback_penalty, 0.001);
    }

    public function test_debit_on_chargeback_without_penalty_no_extra_deduction(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0, 'balance_reserve' => 10]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 40,
            'released_at' => null,
        ]);

        $this->service->debitOnChargeback($user, $order, applyPenalty: false);

        // reserve = 40 * 0.05 = 2; pending = 40 * 0.95 = 38; no penalty
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(62.0, (float) $balance->balance_pending, 0.001); // 100 - 38
        $this->assertEqualsWithDelta(8.0, (float) $balance->balance_reserve, 0.001); // 10 - 2

        $order->refresh();
        $this->assertNull($order->chargeback_penalty);
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

    // --- reseguro ---

    public function test_chargeback_with_reseguro_moves_released_order_back_to_pending(): void
    {
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        UserBalance::factory()->for($user)->create([
            'balance_pending' => 200,   // V1 (pending) + V2 (released before) not overlap: V1=95, V2 post-release 0; balance reflects state
            'balance_released' => 95,   // V2 already released ($100*0.95)
            'balance_reserve' => 10,    // V1+V2 reserve
        ]);

        $v1 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => null, 'shop_id' => $shop->id,
        ]);
        $v2 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => now()->subDay(), 'shop_id' => $shop->id,
        ]);

        $this->service->debitOnChargeback($user, $v1, applyPenalty: true);

        // V1 exits pending (-95) ; reseguro brings V2 back: released -95, pending +95
        // penalty -30 from released ; reserve -5
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(200.0, (float) $balance->balance_pending, 0.001);  // 200 - 95 + 95
        $this->assertEqualsWithDelta(-30.0, (float) $balance->balance_released, 0.001); // 95 - 95 - 30
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001);    // 10 - 5

        $v2->refresh();
        $this->assertNull($v2->released_at);
        $this->assertNotNull($v2->release_eligible_at);
        $this->assertTrue($v2->release_eligible_at->gt(now()));
    }

    public function test_chargeback_without_released_orders_skips_reseguro(): void
    {
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0, 'balance_reserve' => 5]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 40, 'released_at' => null, 'shop_id' => $shop->id,
        ]);

        $this->service->debitOnChargeback($user, $order, applyPenalty: true);

        // no reseguro available → pending -38, released -30 (penalty only), reserve -2
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(62.0, (float) $balance->balance_pending, 0.001);  // 100 - 38
        $this->assertEqualsWithDelta(-30.0, (float) $balance->balance_released, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $balance->balance_reserve, 0.001);
    }

    public function test_chargeback_ignores_released_orders_from_different_shop(): void
    {
        $user = User::factory()->create();
        $shopA = CartpandaShop::factory()->create();
        $shopB = CartpandaShop::factory()->create();
        UserBalance::factory()->for($user)->create([
            'balance_pending' => 100, 'balance_released' => 95, 'balance_reserve' => 10,
        ]);

        $v1 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => null, 'shop_id' => $shopA->id,
        ]);
        $v2 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => now()->subDay(), 'shop_id' => $shopB->id,
        ]);

        $this->service->debitOnChargeback($user, $v1, applyPenalty: true);

        // V2 is shopB → reseguro skipped
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_pending, 0.001);   // 100 - 95
        $this->assertEqualsWithDelta(65.0, (float) $balance->balance_released, 0.001); // 95 - 30
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001);

        $v2->refresh();
        $this->assertNotNull($v2->released_at); // V2 untouched
    }

    public function test_chargeback_reseguro_picks_most_recently_released(): void
    {
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        UserBalance::factory()->for($user)->create([
            'balance_pending' => 100, 'balance_released' => 190, 'balance_reserve' => 15,
        ]);

        $v1 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => null, 'shop_id' => $shop->id,
        ]);
        $older = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => now()->subDays(5), 'shop_id' => $shop->id,
        ]);
        $newer = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => now()->subDay(), 'shop_id' => $shop->id,
        ]);

        $this->service->debitOnChargeback($user, $v1, applyPenalty: true);

        $older->refresh();
        $newer->refresh();
        $this->assertNotNull($older->released_at, 'older should stay released');
        $this->assertNull($newer->released_at, 'newer should be brought back');
    }

    public function test_refund_in_pending_does_not_trigger_reseguro(): void
    {
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        UserBalance::factory()->for($user)->create([
            'balance_pending' => 100, 'balance_released' => 95, 'balance_reserve' => 10,
        ]);

        $v1 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 40, 'released_at' => null, 'shop_id' => $shop->id,
        ]);
        $v2 = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100, 'released_at' => now()->subDay(), 'shop_id' => $shop->id,
        ]);

        $this->service->debitOnChargeback($user, $v1, applyPenalty: false);

        // refund: pending -38, reserve -2; no penalty, no reseguro
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(62.0, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(95.0, (float) $balance->balance_released, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $balance->balance_reserve, 0.001);

        $v2->refresh();
        $this->assertNotNull($v2->released_at);
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
