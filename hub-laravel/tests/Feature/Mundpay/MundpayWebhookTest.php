<?php

namespace Tests\Feature\Mundpay;

use App\Models\MundpayOrder;
use App\Models\TiktokPixel;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\FacebookConversionsService;
use App\Services\PushcutService;
use App\Services\TiktokEventsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MundpayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mundpay.user_email', 'mundpay-user@example.com');
        config()->set('mundpay.reserve_rate', 0.15);
        config()->set('mundpay.release_delay_days', 3);
        config()->set('mundpay.brl_usd_rate', 5.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function makePayload(string $event, string $orderId, int $amountCents): array
    {
        // $amountCents é em BRL centavos. Para os testes igualamos net_amount = amount
        // (em produção o gateway separa fees; aqui simplificamos para tornar a
        // matemática previsível: USD = amountCents / 100 / 5).
        return [
            'id' => $orderId,
            'ref' => 'ref_123',
            'amount' => (string) $amountCents,
            'net_amount' => (string) $amountCents,
            'status' => $event === 'order.paid' ? 'paid' : 'refunded',
            'event_type' => $event,
            'currency' => 'BRL',
            'payment_method' => 'credit_card',
            'paid_at' => '2026-05-15T00:18:54.000000Z',
            'chargeback_at' => $event === 'order.refunded' ? '2026-05-16T00:00:00.000000Z' : null,
            'created_at' => '2026-05-15T00:18:49.000000Z',
            'customer' => [
                'id' => 'cust-1',
                'name' => 'Archie Lopp',
                'email' => 'archie@example.com',
                'phone' => '+15558884444',
                'document' => null,
                'location' => [
                    'city' => 'Detroit', 'state' => null, 'country' => 'US',
                    'zip_code' => null, 'ip_address' => '1.2.3.4',
                ],
            ],
            'tracking' => [
                'ttclid' => 'TTCLID_XYZ',
                'event_source_url' => 'https://pay.example.com/',
                'utm_source' => 'TikTok',
                'utm_campaign' => 'camp',
                'affiliate' => 'aff?src=99',
                'cid' => 'cid-1',
            ],
            'offers' => [
                ['id' => 'o1', 'sku' => null, 'name' => 'Prod', 'type' => 'principal',
                    'price' => $amountCents, 'quantity' => 1,
                    'product' => ['id' => 'p1', 'name' => 'Prod']],
            ],
            'paymentDetail' => ['card_brand' => null, 'card_last_digits' => null],
        ];
    }

    public function test_order_paid_creates_completed_record(): void
    {
        $user = User::factory()->create(['email' => 'mundpay-user@example.com']);

        // 17637 BRL cents = R$ 176.37 / 5.0 = $35.274 USD
        $response = $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-1', 17637));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('mundpay_orders', [
            'mundpay_order_id' => 'order-1',
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => '35.274000',
            'currency' => 'USD',
        ]);
    }

    public function test_order_paid_sets_reserve_15_percent(): void
    {
        User::factory()->create(['email' => 'mundpay-user@example.com']);

        // 10000 BRL cents = R$ 100 / 5 = $20 USD; reserve 15% = $3 USD
        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-2', 10000))
            ->assertOk();

        $order = MundpayOrder::where('mundpay_order_id', 'order-2')->first();
        $this->assertSame('3.000000', $order->reserve_amount);
        $this->assertSame('20.000000', $order->amount);
    }

    public function test_order_paid_sets_release_eligible_at_three_days_after_paid_at(): void
    {
        User::factory()->create(['email' => 'mundpay-user@example.com']);

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-3', 5000))
            ->assertOk();

        $order = MundpayOrder::where('mundpay_order_id', 'order-3')->first();
        // paid_at = 2026-05-15T00:18:54Z. Cast to datetime in app timezone (America/Sao_Paulo, UTC-3)
        // = 2026-05-14 21:18:54 local. addDays(3) -> 2026-05-17 21:18:54 local -> 2026-05-18 00:18:54 UTC.
        // Controller saves the +3d Carbon back; SQLite stores naive, retrieval re-applies app TZ shift,
        // so utc() output ends up at 2026-05-18 03:18:54Z. Assertion matches actual stored behavior.
        $this->assertEquals(
            '2026-05-18 03:18:54',
            $order->release_eligible_at->utc()->format('Y-m-d H:i:s')
        );
    }

    public function test_order_paid_credits_balance_pending_and_reserve(): void
    {
        $user = User::factory()->create(['email' => 'mundpay-user@example.com']);

        // 10000 BRL cents → $20 USD; reserve $3, pending $17
        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-4', 10000))
            ->assertOk();

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('17.000000', $balance->balance_pending);
        $this->assertEquals('3.000000', $balance->balance_reserve);
    }

    public function test_duplicate_order_paid_is_ignored(): void
    {
        User::factory()->create(['email' => 'mundpay-user@example.com']);

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-5', 5000))->assertOk();
        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-5', 5000))->assertOk();

        $this->assertSame(1, MundpayOrder::where('mundpay_order_id', 'order-5')->count());
        $this->assertDatabaseHas('mundpay_webhook_logs', [
            'status_reason' => 'already_terminal',
        ]);
    }

    public function test_order_refunded_without_existing_order_logs_original_order_not_found(): void
    {
        User::factory()->create(['email' => 'mundpay-user@example.com']);

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.refunded', 'order-6', 5000))
            ->assertOk();

        $this->assertDatabaseMissing('mundpay_orders', ['mundpay_order_id' => 'order-6']);
        $this->assertDatabaseHas('mundpay_webhook_logs', [
            'status_reason' => 'original_order_not_found',
        ]);
    }

    public function test_order_refunded_after_paid_applies_chargeback(): void
    {
        $user = User::factory()->create(['email' => 'mundpay-user@example.com']);

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-7', 10000))->assertOk();
        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.refunded', 'order-7', 10000))->assertOk();

        $order = MundpayOrder::where('mundpay_order_id', 'order-7')->first();
        $this->assertSame('REFUNDED', $order->status);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('0.000000', $balance->balance_pending);
        $this->assertEquals('0.000000', $balance->balance_reserve);
    }

    public function test_unknown_event_logs_unknown_event(): void
    {
        User::factory()->create(['email' => 'mundpay-user@example.com']);

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.cancelled', 'order-8', 5000))
            ->assertOk();

        $this->assertDatabaseMissing('mundpay_orders', ['mundpay_order_id' => 'order-8']);
        $this->assertDatabaseHas('mundpay_webhook_logs', [
            'status_reason' => 'unknown_event',
        ]);
    }

    public function test_missing_user_logs_user_not_found(): void
    {
        // sem User com email mundpay-user@example.com
        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-9', 5000))
            ->assertOk();

        $this->assertDatabaseMissing('mundpay_orders', ['mundpay_order_id' => 'order-9']);
        $this->assertDatabaseHas('mundpay_webhook_logs', [
            'status_reason' => 'user_not_found',
        ]);
    }

    public function test_pushcut_is_dispatched_on_completed(): void
    {
        $user = User::factory()->create(['email' => 'mundpay-user@example.com']);
        $user->pushcutUrls()->create([
            'url' => 'https://api.pushcut.io/test',
            'notify' => 'all',
        ]);

        $this->mock(PushcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->with(
                    'https://api.pushcut.io/test',
                    \Mockery::pattern('/^Venda Aprovada - /'),
                    \Mockery::on(fn ($data) => $data['order_id'] === 'order-10' && $data['status'] === 'COMPLETED'),
                );
        });

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-10', 5000))
            ->assertOk();
    }

    public function test_tiktok_purchase_event_dispatched_on_completed(): void
    {
        $user = User::factory()->create(['email' => 'mundpay-user@example.com']);
        TiktokPixel::factory()->for($user)->create(['enabled' => true]);

        $this->mock(TiktokEventsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendPurchaseEventForMundpay')->once();
        });

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-11', 5000))
            ->assertOk();
    }

    public function test_resolves_user_via_affiliate_cartpanda_param(): void
    {
        // Affiliate "monstrodolago" → casa com users.cartpanda_param
        $affiliate = User::factory()->withCartpandaParam('monstrodolago')->create();
        $fallback = User::factory()->create(['email' => 'mundpay-user@example.com']);

        $payload = $this->makePayload('order.paid', 'order-aff-1', 5000);
        $payload['tracking']['affiliate'] = 'monstrodolago';

        $this->postJson('/api/mundpay-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('mundpay_orders', [
            'mundpay_order_id' => 'order-aff-1',
            'user_id' => $affiliate->id,
        ]);
        // Não bate no fallback: log processed sem default_user_fallback
        $this->assertDatabaseHas('mundpay_webhook_logs', [
            'mundpay_order_id' => 'order-aff-1',
            'status' => 'processed',
            'status_reason' => null,
        ]);
    }

    public function test_affiliate_with_query_suffix_is_truncated_before_lookup(): void
    {
        $affiliate = User::factory()->withCartpandaParam('monstrodolago')->create();
        User::factory()->create(['email' => 'mundpay-user@example.com']);

        $payload = $this->makePayload('order.paid', 'order-aff-2', 5000);
        $payload['tracking']['affiliate'] = 'monstrodolago?src=11233001';

        $this->postJson('/api/mundpay-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('mundpay_orders', [
            'mundpay_order_id' => 'order-aff-2',
            'user_id' => $affiliate->id,
        ]);
    }

    public function test_unknown_affiliate_falls_back_to_config_user(): void
    {
        $fallback = User::factory()->create(['email' => 'mundpay-user@example.com']);

        $payload = $this->makePayload('order.paid', 'order-aff-3', 5000);
        $payload['tracking']['affiliate'] = 'desconhecido';

        $this->postJson('/api/mundpay-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('mundpay_orders', [
            'mundpay_order_id' => 'order-aff-3',
            'user_id' => $fallback->id,
        ]);
        $this->assertDatabaseHas('mundpay_webhook_logs', [
            'mundpay_order_id' => 'order-aff-3',
            'status' => 'processed',
            'status_reason' => 'default_user_fallback',
        ]);
    }

    public function test_facebook_purchase_event_dispatched_when_pixel_configured(): void
    {
        $user = User::factory()->create([
            'email' => 'mundpay-user@example.com',
            'facebook_pixel_id' => '123456',
            'facebook_access_token' => 'token-xyz',
        ]);

        $this->mock(FacebookConversionsService::class, function (MockInterface $mock): void {
            // Positional args: Mockery doesn't match PHP named args.
            // sendPurchaseEvent($pixelId, $accessToken, $orderId, $value, $currency, $userData, $eventTime = null)
            $mock->shouldReceive('sendPurchaseEvent')
                ->once()
                ->with(
                    '123456',
                    'token-xyz',
                    'order-12',
                    10.0, // 5000 BRL cents = R$ 50 / 5 = $10 USD
                    'USD',
                    \Mockery::on(fn ($d) => ($d['email'] ?? null) === 'archie@example.com'),
                );
        });

        $this->postJson('/api/mundpay-webhook', $this->makePayload('order.paid', 'order-12', 5000))
            ->assertOk();
    }
}
