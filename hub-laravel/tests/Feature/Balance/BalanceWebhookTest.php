<?php

namespace Tests\Feature\Balance;

use App\Models\CartpandaOrder;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\PushcutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class BalanceWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PushcutService::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->zeroOrMoreTimes();
        });
    }

    // ── order.paid → creditPending ───────────────────────────────

    public function test_order_paid_increments_balance_pending(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.paid', 'afiliado1', 80001, 50.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // afterFee = 50 * 0.915 = 45.75; reserve = 45.75 * 0.05 = 2.2875; pending = 45.75 - 2.2875 = 43.4625
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(43.4625, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(2.2875, (float) $balance->balance_reserve, 0.001);
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_order_paid_accumulates_balance_pending(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.00,
            'balance_released' => 0,
            'balance_reserve' => 0,
        ]);

        $payload = $this->makePayload('order.paid', 'afiliado1', 80002, 25.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // afterFee = 25 * 0.915 = 22.875; reserve = 22.875 * 0.05 = 1.14375; pending = 22.875 - 1.14375 = 21.73125
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(121.73125, (float) $balance->balance_pending, 0.001); // 100 + 21.73125
        $this->assertEqualsWithDelta(1.14375, (float) $balance->balance_reserve, 0.001);
    }

    // ── order.chargeback → debitOnChargeback ─────────────────────

    public function test_chargeback_on_released_order_debits_balance_released(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 0,
            'balance_released' => 200.00,
            'balance_reserve' => 10.00,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80003',
            'user_id' => $user->id,
            'amount' => 50.00,
            'status' => 'PENDING',
            'released_at' => now(),
        ]);

        $payload = $this->makePayload('order.chargeback', 'afiliado1', 80003, 50.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // afterFee = 50 * 0.915 = 45.75; reserve = 45.75 * 0.05 = 2.2875; pending = 45.75 - 2.2875 = 43.4625
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(156.5375, (float) $balance->balance_released, 0.001); // 200 - 43.4625
        $this->assertEqualsWithDelta(7.7125, (float) $balance->balance_reserve, 0.001); // 10 - 2.2875
        $this->assertEquals(0.0, (float) $balance->balance_pending);
    }

    public function test_chargeback_on_unreleased_order_debits_balance_pending(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.00,
            'balance_released' => 0,
            'balance_reserve' => 5.00,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80004',
            'user_id' => $user->id,
            'amount' => 30.00,
            'status' => 'PENDING',
            'released_at' => null,
        ]);

        $payload = $this->makePayload('order.chargeback', 'afiliado1', 80004, 30.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // afterFee = 30 * 0.915 = 27.45; reserve = 27.45 * 0.05 = 1.3725; pending = 27.45 - 1.3725 = 26.0775
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(73.9225, (float) $balance->balance_pending, 0.001); // 100 - 26.0775
        $this->assertEqualsWithDelta(3.6275, (float) $balance->balance_reserve, 0.001); // 5 - 1.3725
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    // ── order.refunded → debitOnChargeback (same logic) ──────────

    public function test_refund_on_released_order_debits_balance_released(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 0,
            'balance_released' => 200.00,
            'balance_reserve' => 8.00,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80005',
            'user_id' => $user->id,
            'amount' => 75.00,
            'status' => 'PENDING',
            'released_at' => now(),
        ]);

        $payload = $this->makePayload('order.refunded', 'afiliado1', 80005, 75.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // afterFee = 75 * 0.915 = 68.625; reserve = 68.625 * 0.05 = 3.43125; pending = 68.625 - 3.43125 = 65.19375
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(134.80625, (float) $balance->balance_released, 0.001); // 200 - 65.19375
        $this->assertEqualsWithDelta(4.56875, (float) $balance->balance_reserve, 0.001); // 8 - 3.43125
    }

    // ── Duplicate webhook (terminal order) → no balance change ───

    public function test_duplicate_webhook_on_terminal_order_does_not_change_balance(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 50.00,
            'balance_released' => 0,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80006',
            'user_id' => $user->id,
            'amount' => 50.00,
            'status' => 'COMPLETED',
        ]);

        $payload = $this->makePayload('order.paid', 'afiliado1', 80006, 50.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_pending' => '50.000000',
        ]);
    }

    // ── order.pending → no balance effect ────────────────────────

    public function test_order_pending_does_not_affect_balance(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();

        $payload = $this->makePayload('order.pending', 'afiliado1', 80007, 20.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseMissing('user_balances', [
            'user_id' => $user->id,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function makePayload(string $event, string $affiliateKey, int $orderId, float $amount): array
    {
        return [
            'event' => $event,
            'order' => [
                'id' => $orderId,
                'checkout_params' => ['id' => $affiliateKey],
                'payment' => [
                    'actual_price_paid' => $amount,
                ],
                'customer' => [
                    'email' => 'buyer@example.com',
                    'full_name' => 'Buyer Name',
                ],
            ],
        ];
    }
}
