<?php

namespace Tests\Feature;

use App\Models\CartpandaOrder;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\PushcutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PushcutService::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->zeroOrMoreTimes();
        });
    }

    // ── Webhook controller logging ────────────────────────────────

    public function test_processed_webhook_creates_log_with_processed_status(): void
    {
        $user = User::factory()->withCartpandaParam('aff1')->create();

        $this->postJson('/api/cartpanda-webhook', $this->makePayload('order.paid', 'aff1', 90001, 100.0))
            ->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'event' => 'order.paid',
            'cartpanda_order_id' => '90001',
            'status' => 'processed',
            'status_reason' => null,
        ]);
    }

    public function test_unknown_event_creates_log_with_ignored_status(): void
    {
        $this->postJson('/api/cartpanda-webhook', ['event' => 'order.unknown', 'order' => ['id' => 90002]])
            ->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'event' => 'order.unknown',
            'cartpanda_order_id' => '90002',
            'status' => 'ignored',
            'status_reason' => 'unknown_event',
        ]);
    }

    public function test_unresolvable_user_creates_log_with_user_not_found(): void
    {
        $this->postJson('/api/cartpanda-webhook', $this->makePayload('order.paid', 'nonexistent', 90003, 50.0))
            ->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'event' => 'order.paid',
            'cartpanda_order_id' => '90003',
            'status' => 'ignored',
            'status_reason' => 'user_not_found',
        ]);
    }

    public function test_duplicate_terminal_order_creates_log_with_already_terminal(): void
    {
        $user = User::factory()->withCartpandaParam('aff2')->create();

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '90004',
            'user_id' => $user->id,
            'status' => 'COMPLETED',
        ]);

        $this->postJson('/api/cartpanda-webhook', $this->makePayload('order.paid', 'aff2', 90004, 50.0))
            ->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'event' => 'order.paid',
            'cartpanda_order_id' => '90004',
            'status' => 'ignored',
            'status_reason' => 'already_terminal',
        ]);
    }

    public function test_chargeback_on_completed_order_creates_log_with_chargeback_applied(): void
    {
        $user = User::factory()->withCartpandaParam('aff3')->create();

        CartpandaOrder::factory()->create([
            'cartpanda_order_id' => '90005',
            'user_id' => $user->id,
            'amount' => 50.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $this->postJson('/api/cartpanda-webhook', $this->makePayload('order.chargeback', 'aff3', 90005, 50.0))
            ->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'event' => 'order.chargeback',
            'cartpanda_order_id' => '90005',
            'status' => 'processed',
            'status_reason' => 'chargeback_applied',
        ]);
    }

    public function test_webhook_log_stores_full_payload(): void
    {
        $user = User::factory()->withCartpandaParam('aff4')->create();

        $this->postJson('/api/cartpanda-webhook', $this->makePayload('order.paid', 'aff4', 90006, 75.0))
            ->assertOk();

        $log = WebhookLog::where('cartpanda_order_id', '90006')->first();
        $this->assertNotNull($log);
        $this->assertEquals('order.paid', $log->payload['event']);
    }

    public function test_webhook_log_stores_shop_slug(): void
    {
        $user = User::factory()->withCartpandaParam('aff5')->create();
        $payload = $this->makePayload('order.paid', 'aff5', 90007, 50.0);
        $payload['order']['shop'] = ['id' => 999, 'slug' => 'my-shop', 'name' => 'My Shop'];

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'cartpanda_order_id' => '90007',
            'shop_slug' => 'my-shop',
        ]);
    }

    public function test_chargeback_for_nonexistent_order_is_ignored_and_no_order_is_created(): void
    {
        $user = User::factory()->withCartpandaParam('aff6')->create();

        // Send chargeback for an order that was never recorded
        $this->postJson('/api/cartpanda-webhook', $this->makePayload('order.chargeback', 'aff6', 99999, 0.0))
            ->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'event' => 'order.chargeback',
            'cartpanda_order_id' => '99999',
            'status' => 'ignored',
            'status_reason' => 'original_order_not_found',
        ]);

        $this->assertDatabaseMissing('cartpanda_orders', ['cartpanda_order_id' => '99999']);
    }

    // ── Admin list endpoint ───────────────────────────────────────

    public function test_admin_can_list_webhook_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        WebhookLog::factory()->count(5)->create();

        $response = $this->withToken($token)->getJson('/api/admin/webhook-logs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'event', 'cartpanda_order_id', 'shop_slug', 'status', 'status_reason', 'payload', 'ip_address', 'created_at']],
                'meta' => ['total', 'page', 'per_page', 'pages'],
            ])
            ->assertJsonPath('meta.total', 5);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        WebhookLog::factory()->processed()->count(3)->create();
        WebhookLog::factory()->ignored('unknown_event')->count(2)->create();

        $response = $this->withToken($token)->getJson('/api/admin/webhook-logs?status=processed');

        $response->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_admin_can_filter_by_event(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        WebhookLog::factory()->create(['event' => 'order.paid']);
        WebhookLog::factory()->create(['event' => 'order.paid']);
        WebhookLog::factory()->create(['event' => 'order.created']);

        $response = $this->withToken($token)->getJson('/api/admin/webhook-logs?event=order.paid');

        $response->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_admin_can_filter_by_shop_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        WebhookLog::factory()->create(['shop_slug' => 'lifeboost']);
        WebhookLog::factory()->create(['shop_slug' => 'lifeboost']);
        WebhookLog::factory()->create(['shop_slug' => 'other-shop']);

        $response = $this->withToken($token)->getJson('/api/admin/webhook-logs?shop_slug=lifeboost');

        $response->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_webhook_logs_are_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        WebhookLog::factory()->count(25)->create();

        $response = $this->withToken($token)->getJson('/api/admin/webhook-logs?page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.pages', 2);

        $this->assertCount(20, $response->json('data'));

        $page2 = $this->withToken($token)->getJson('/api/admin/webhook-logs?page=2');
        $this->assertCount(5, $page2->json('data'));
    }

    public function test_non_admin_cannot_list_webhook_logs(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/webhook-logs')->assertForbidden();
    }

    public function test_unauthenticated_cannot_list_webhook_logs(): void
    {
        $this->getJson('/api/admin/webhook-logs')->assertUnauthorized();
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
                'all_payments' => [['seller_split_amount' => $amount]],
                'payment' => ['actual_exchange_rate' => 1.0],
                'customer' => ['email' => 'buyer@example.com', 'full_name' => 'Buyer Name'],
            ],
        ];
    }
}
