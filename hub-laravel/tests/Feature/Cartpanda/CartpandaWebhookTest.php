<?php

namespace Tests\Feature\Cartpanda;

use App\Models\CartpandaOrder;
use App\Models\User;
use App\Services\PushcutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CartpandaWebhookTest extends TestCase
{
    use RefreshDatabase;

    // ── Model / Factory tests ───────────────────────────────────

    public function test_cartpanda_order_factory_creates_record(): void
    {
        $order = CartpandaOrder::factory()->create();
        $this->assertDatabaseHas('cartpanda_orders', ['id' => $order->id]);
    }

    public function test_cartpanda_order_is_terminal_for_completed(): void
    {
        $order = CartpandaOrder::factory()->create(['status' => 'COMPLETED']);
        $this->assertTrue($order->isTerminal());
    }

    public function test_cartpanda_order_is_terminal_for_refunded(): void
    {
        $order = CartpandaOrder::factory()->refunded()->create();
        $this->assertTrue($order->isTerminal());
    }

    public function test_cartpanda_order_is_not_terminal_for_pending(): void
    {
        $order = CartpandaOrder::factory()->pending()->create();
        $this->assertFalse($order->isTerminal());
    }

    // ── Webhook tests ───────────────────────────────────────────

    public function test_order_paid_creates_completed_record(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.paid', 'afiliado1', 90001, 43.240491);

        $response = $this->postJson('/api/cartpanda-webhook', $payload);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90001',
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => '43.240491',
        ]);
    }

    public function test_order_pending_creates_pending_record(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.pending', 'afiliado1', 90002, 10.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90002',
            'status' => 'PENDING',
        ]);
    }

    public function test_order_refunded_creates_refunded_record(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.refunded', 'afiliado1', 90003, 25.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90003',
            'status' => 'REFUNDED',
        ]);
    }

    public function test_order_cancelled_creates_failed_record(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.cancelled', 'afiliado1', 90004, 15.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90004',
            'status' => 'FAILED',
        ]);
    }

    public function test_order_chargeback_creates_declined_record(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.chargeback', 'afiliado1', 90005, 50.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90005',
            'status' => 'DECLINED',
        ]);
    }

    public function test_unknown_event_returns_ok_without_creating_record(): void
    {
        User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.unknown', 'afiliado1', 90006, 10.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('cartpanda_orders', ['cartpanda_order_id' => '90006']);
    }

    public function test_no_matching_user_returns_ok_without_creating_record(): void
    {
        $payload = $this->makePayload('order.paid', 'nonexistent_param', 90007, 10.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('cartpanda_orders', ['cartpanda_order_id' => '90007']);
    }

    public function test_null_checkout_params_returns_ok_without_creating_record(): void
    {
        $payload = $this->makePayload('order.paid', null, 90008, 10.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('cartpanda_orders', ['cartpanda_order_id' => '90008']);
    }

    public function test_terminal_order_status_unchanged(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '90009',
            'user_id' => $user->id,
            'status' => 'COMPLETED',
        ]);

        $payload = $this->makePayload('order.refunded', 'afiliado1', 90009, 10.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90009',
            'status' => 'COMPLETED',
        ]);
    }

    public function test_pending_to_completed_upsert(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();

        CartpandaOrder::factory()->pending()->create([
            'cartpanda_order_id' => '90010',
            'user_id' => $user->id,
        ]);

        $payload = $this->makePayload('order.paid', 'afiliado1', 90010, 43.24);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90010',
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseCount('cartpanda_orders', 1);
    }

    public function test_affiliate_param_routes_to_correct_user(): void
    {
        $user1 = User::factory()->withCartpandaParam('afiliado1')->create();
        $user2 = User::factory()->withCartpandaParam('afiliado2')->create();

        $payload = $this->makePayload('order.paid', 'afiliado1', 90011, 20.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90011',
            'user_id' => $user1->id,
        ]);
        $this->assertDatabaseMissing('cartpanda_orders', [
            'user_id' => $user2->id,
        ]);
    }

    public function test_pushcut_fires_on_completed_when_notify_all(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create([
            'pushcut_url' => 'https://pushcut.example.com/hook',
            'pushcut_notify' => 'all',
        ]);

        $this->mock(PushcutService::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->once();
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 90012, 30.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_pushcut_does_not_fire_on_pending_when_notify_paid(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create([
            'pushcut_url' => 'https://pushcut.example.com/hook',
            'pushcut_notify' => 'paid',
        ]);

        $this->mock(PushcutService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $payload = $this->makePayload('order.pending', 'afiliado1', 90013, 10.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_payer_email_and_name_stored_from_customer(): void
    {
        User::factory()->withCartpandaParam('afiliado1')->create();
        $payload = $this->makePayload('order.paid', 'afiliado1', 90014, 20.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '90014',
            'payer_email' => 'john@example.com',
            'payer_name' => 'John Doe',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function makePayload(string $event, ?string $affiliateKey, int $orderId, float $amount): array
    {
        $checkoutParams = $affiliateKey !== null
            ? ['affiliate' => $affiliateKey]
            : null;

        return [
            'event' => $event,
            'order' => [
                'id' => $orderId,
                'checkout_params' => $checkoutParams,
                'payment' => [
                    'actual_price_paid' => $amount,
                ],
                'customer' => [
                    'email' => 'john@example.com',
                    'full_name' => 'John Doe',
                ],
            ],
        ];
    }
}
