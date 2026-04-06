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

        // amount is already net; reserve = 50 * 0.05 = 2.5; pending = 50 * 0.95 = 47.5
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(47.5, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(2.5, (float) $balance->balance_reserve, 0.001);
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

        // reserve = 25 * 0.05 = 1.25; pending = 25 * 0.95 = 23.75
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(123.75, (float) $balance->balance_pending, 0.001); // 100 + 23.75
        $this->assertEqualsWithDelta(1.25, (float) $balance->balance_reserve, 0.001);
    }

    // ── chargeback/refund without checkout_params (fallback) ─────

    public function test_chargeback_without_checkout_params_debits_balance_using_existing_order_amount(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.00,
            'balance_released' => 0,
            'balance_reserve' => 5.00,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80099',
            'user_id' => $user->id,
            'amount' => 50.00,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $payload = $this->makePayloadWithoutCheckoutParams('order.chargeback', 80099);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // amount=50; reserve = 50 * 0.05 = 2.5; pending_debit = 50 * 0.95 = 47.5; penalty = 30
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(22.5, (float) $balance->balance_pending, 0.001); // 100 - 47.5 - 30
        $this->assertEqualsWithDelta(2.5, (float) $balance->balance_reserve, 0.001); // 5 - 2.5
    }

    public function test_refund_without_checkout_params_debits_balance_using_existing_order_amount(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 80.00,
            'balance_released' => 0,
            'balance_reserve' => 4.00,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80100',
            'user_id' => $user->id,
            'amount' => 30.00,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $payload = $this->makePayloadWithoutCheckoutParams('order.refunded', 80100);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // uses existing order amount=30; reserve = 30 * 0.05 = 1.5; pending_debit = 30 * 0.95 = 28.5
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(51.5, (float) $balance->balance_pending, 0.001); // 80 - 28.5
        $this->assertEqualsWithDelta(2.5, (float) $balance->balance_reserve, 0.001); // 4 - 1.5
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

        // reserve = 50 * 0.05 = 2.5; released_debit = 50 * 0.95 = 47.5; penalty = 30
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(122.5, (float) $balance->balance_released, 0.001); // 200 - 47.5 - 30
        $this->assertEqualsWithDelta(7.5, (float) $balance->balance_reserve, 0.001); // 10 - 2.5
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

        // reserve = 30 * 0.05 = 1.5; pending_debit = 30 * 0.95 = 28.5; penalty = 30
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(41.5, (float) $balance->balance_pending, 0.001); // 100 - 28.5 - 30
        $this->assertEqualsWithDelta(3.5, (float) $balance->balance_reserve, 0.001); // 5 - 1.5
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

        // reserve = 75 * 0.05 = 3.75; released_debit = 75 * 0.95 = 71.25
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(128.75, (float) $balance->balance_released, 0.001); // 200 - 71.25
        $this->assertEqualsWithDelta(4.25, (float) $balance->balance_reserve, 0.001); // 8 - 3.75
    }

    public function test_refund_webhook_does_not_apply_penalty(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.00,
            'balance_released' => 0,
            'balance_reserve' => 5.00,
        ]);

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '80110',
            'user_id' => $user->id,
            'amount' => 40.00,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $payload = $this->makePayloadWithoutCheckoutParams('order.refunded', 80110);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        // amount=40; reserve = 40 * 0.05 = 2; pending_debit = 40 * 0.95 = 38; no penalty
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(62.0, (float) $balance->balance_pending, 0.001); // 100 - 38
        $this->assertEqualsWithDelta(3.0, (float) $balance->balance_reserve, 0.001); // 5 - 2

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '80110',
            'chargeback_penalty' => null,
        ]);
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
                'all_payments' => [
                    ['seller_split_amount' => $amount],
                ],
                'payment' => [
                    'actual_exchange_rate' => 1.0,
                ],
                'customer' => [
                    'email' => 'buyer@example.com',
                    'full_name' => 'Buyer Name',
                ],
            ],
        ];
    }

    private function makePayloadWithoutCheckoutParams(string $event, int $orderId): array
    {
        return [
            'event' => $event,
            'order' => [
                'id' => $orderId,
                'checkout_params' => null,
                'payment' => [],
                'customer' => [
                    'email' => 'buyer@example.com',
                    'full_name' => 'Buyer Name',
                ],
            ],
        ];
    }
}
