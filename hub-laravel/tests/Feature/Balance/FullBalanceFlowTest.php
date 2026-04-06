<?php

namespace Tests\Feature\Balance;

use App\Jobs\ReleaseBalanceJob;
use App\Models\CartpandaOrder;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\BalanceService;
use App\Services\PushcutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end balance lifecycle:
 *   order.paid → release (2d) → chargeback → second order → payout
 *
 * Amount model post-fix:
 *   - order.amount = seller_split_amount * exchange_rate (net, CartPanda fee already deducted)
 *   - pending  = amount * 0.95
 *   - reserve  = amount * 0.05
 *   - release moves pending → released after 2 days
 */
class FullBalanceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PushcutService::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->zeroOrMoreTimes();
        });
    }

    public function test_full_balance_lifecycle(): void
    {
        $user = User::factory()->withCartpandaParam('flow_user')->create();
        $admin = User::factory()->admin()->create();
        $service = app(BalanceService::class);

        // ── Step 1: order.paid (net amount = 100) ─────────────────
        $response = $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload(
            event: 'order.paid',
            affiliateKey: 'flow_user',
            orderId: 'FLOW-001',
            amount: 100.0,
        ));
        $response->assertOk();

        $order = CartpandaOrder::where('cartpanda_order_id', 'FLOW-001')->first();
        $this->assertEquals(100.0, (float) $order->amount);
        $this->assertEquals('COMPLETED', $order->status);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(95.0, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001);
        $this->assertEquals(0.0, (float) $balance->balance_released);

        // ── Step 2: ReleaseBalanceJob after 2 days ─────────────────
        CartpandaOrder::where('id', $order->id)->update(['created_at' => now()->subDays(3)]);
        ReleaseBalanceJob::dispatchSync();

        $balance->refresh();
        $order->refresh();
        // release moves pending(95) → released; reserve(5) unchanged
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_pending, 0.001);   // 95 - 95
        $this->assertEqualsWithDelta(95.0, (float) $balance->balance_released, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001);   // unchanged
        $this->assertNotNull($order->released_at);

        // ── Step 3: chargeback on the released order ───────────────
        $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload(
            event: 'order.chargeback',
            affiliateKey: 'flow_user',
            orderId: 'FLOW-001',
            amount: 100.0,
        ))->assertOk();

        $balance->refresh();
        // released debited: 95 - 95 - 30 (penalty) = -30; reserve debited: 5 - 5 = 0; pending unchanged: 0
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(-30.0, (float) $balance->balance_released, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_reserve, 0.001);

        // ── Step 4: second order.paid (amount = 200) ───────────────
        $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload(
            event: 'order.paid',
            affiliateKey: 'flow_user',
            orderId: 'FLOW-002',
            amount: 200.0,
        ))->assertOk();

        $balance->refresh();
        $this->assertEqualsWithDelta(190.0, (float) $balance->balance_pending, 0.001);   // 0 + 190
        $this->assertEqualsWithDelta(10.0, (float) $balance->balance_reserve, 0.001);    // 0 + 10
        $this->assertEqualsWithDelta(-30.0, (float) $balance->balance_released, 0.001);  // -30 (unchanged)

        // ── Step 5: release second order ───────────────────────────
        $order2 = CartpandaOrder::where('cartpanda_order_id', 'FLOW-002')->first();
        CartpandaOrder::where('id', $order2->id)->update(['created_at' => now()->subDays(3)]);
        ReleaseBalanceJob::dispatchSync();

        $balance->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_pending, 0.001);     // 190 - 190
        $this->assertEqualsWithDelta(160.0, (float) $balance->balance_released, 0.001);  // -30 + 190

        // ── Step 6: payout (withdrawal of 150) ─────────────────────
        $service->payout($user, $admin, 150.0, 'withdrawal', 'Payout semanal');

        $balance->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $balance->balance_released, 0.001);  // 160 - 150
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_pending, 0.001);    // unchanged

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'amount' => -150.0,
            'type' => 'withdrawal',
        ]);
    }

    public function test_duplicate_paid_webhook_is_idempotent(): void
    {
        $user = User::factory()->withCartpandaParam('flow_user2')->create();

        $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload('order.paid', 'flow_user2', 'FLOW-DUP', 50.0))->assertOk();
        $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload('order.paid', 'flow_user2', 'FLOW-DUP', 50.0))->assertOk();

        $this->assertDatabaseCount('cartpanda_orders', 1);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(47.5, (float) $balance->balance_pending, 0.001); // 50 * 0.95, credited only once
    }

    public function test_refund_on_unreleased_order_zeroes_pending(): void
    {
        $user = User::factory()->withCartpandaParam('flow_user3')->create();

        $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload('order.paid', 'flow_user3', 'FLOW-REF-1', 100.0))->assertOk();

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(95.0, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->balance_reserve, 0.001);

        // refund for the same order
        $this->postJson('/api/cartpanda-webhook', $this->makeWebhookPayload('order.refunded', 'flow_user3', 'FLOW-REF-1', 100.0))->assertOk();

        $balance->refresh();
        // order was COMPLETED (terminal) → chargeback path: debit balance
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_pending, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->balance_reserve, 0.001);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function makeWebhookPayload(string $event, string $affiliateKey, string $orderId, float $amount): array
    {
        return [
            'event' => $event,
            'order' => [
                'id' => $orderId,
                'checkout_params' => ['affiliate' => $affiliateKey],
                'all_payments' => [
                    ['seller_split_amount' => $amount],
                ],
                'payment' => [
                    'actual_exchange_rate' => 1.0,
                ],
                'customer' => [
                    'email' => 'buyer@example.com',
                    'full_name' => 'Test Buyer',
                ],
            ],
        ];
    }
}
