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

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_pending' => '50.000000',
            'balance_released' => '0.000000',
        ]);
    }

    public function test_order_paid_accumulates_balance_pending(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.00,
            'balance_released' => 0,
        ]);

        $payload = $this->makePayload('order.paid', 'afiliado1', 80002, 25.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_pending' => '125.000000',
        ]);
    }

    // ── order.chargeback → debitOnChargeback ─────────────────────

    public function test_chargeback_on_released_order_debits_balance_released(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 0,
            'balance_released' => 200.00,
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

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_released' => '150.000000',
            'balance_pending' => '0.000000',
        ]);
    }

    public function test_chargeback_on_unreleased_order_debits_balance_pending(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.00,
            'balance_released' => 0,
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

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_pending' => '70.000000',
            'balance_released' => '0.000000',
        ]);
    }

    // ── order.refunded → debitOnChargeback (same logic) ──────────

    public function test_refund_on_released_order_debits_balance_released(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 0,
            'balance_released' => 200.00,
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

        $this->assertDatabaseHas('user_balances', [
            'user_id' => $user->id,
            'balance_released' => '125.000000',
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
                'checkout_params' => [$affiliateKey => 'tracking'],
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
