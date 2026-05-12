<?php

namespace Tests\Feature\Cartpanda;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileCartpandaOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_ingests_orders_from_webhook_logs_to_default_user(): void
    {
        $shop = CartpandaShop::factory()->create([
            'shop_slug' => 'reconcile-shop',
            'cartpanda_shop_id' => '777',
        ]);
        $defaultUser = User::factory()->create(['email' => 'srretry@fractal.com']);
        config()->set('cartpanda.default_user_email', 'srretry@fractal.com');

        WebhookLog::create([
            'event' => 'order.paid',
            'cartpanda_order_id' => '50001',
            'shop_slug' => 'reconcile-shop',
            'status' => 'ignored',
            'status_reason' => 'user_not_found',
            'payload' => [
                'event' => 'order.paid',
                'order' => [
                    'id' => 50001,
                    'financial_status' => 3,
                    'processed_at' => now()->subDays(5)->toIso8601String(),
                    'all_payments' => [
                        [
                            'seller_split_amount' => 178.51,
                            'seller_allowance_amount' => 9.84,
                        ],
                    ],
                    'transactions' => [
                        ['actual_exchange_rate' => 0.203021],
                    ],
                    'payment' => ['actual_exchange_rate' => 0.203021],
                    'customer' => ['email' => 'org@example.com', 'first_name' => 'Org', 'last_name' => 'Buyer'],
                ],
            ],
            'ip_address' => '127.0.0.1',
        ]);

        $this->artisan('cartpanda:reconcile', [
            'shop_slug' => 'reconcile-shop',
            '--source' => 'webhook_logs',
        ])->assertSuccessful();

        $order = CartpandaOrder::where('cartpanda_order_id', '50001')->first();
        $this->assertNotNull($order);
        $this->assertSame($defaultUser->id, $order->user_id);
        $this->assertSame($shop->id, $order->shop_id);
        $this->assertSame('COMPLETED', $order->status);
        $this->assertEqualsWithDelta(36.241279, (float) $order->amount, 0.000001);
        $this->assertEqualsWithDelta(1.997727, (float) $order->reserve_amount, 0.000001);

        $balance = UserBalance::where('user_id', $defaultUser->id)->first();
        $this->assertEqualsWithDelta(34.243552, (float) $balance->balance_pending, 0.000001);
        $this->assertEqualsWithDelta(1.997727, (float) $balance->balance_reserve, 0.000001);
    }

    public function test_reconcile_skips_already_existing_orders(): void
    {
        $shop = CartpandaShop::factory()->create(['shop_slug' => 'idem-shop', 'cartpanda_shop_id' => '888']);
        $defaultUser = User::factory()->create(['email' => 'srretry@fractal.com']);
        config()->set('cartpanda.default_user_email', 'srretry@fractal.com');

        CartpandaOrder::factory()->for($defaultUser)->create([
            'cartpanda_order_id' => '50002',
            'shop_id' => $shop->id,
            'amount' => 100.0,
        ]);

        WebhookLog::create([
            'event' => 'order.paid',
            'cartpanda_order_id' => '50002',
            'shop_slug' => 'idem-shop',
            'status' => 'ignored',
            'status_reason' => 'user_not_found',
            'payload' => ['order' => ['id' => 50002, 'financial_status' => 3, 'all_payments' => [['seller_split_amount' => 50]]]],
            'ip_address' => '127.0.0.1',
        ]);

        $this->artisan('cartpanda:reconcile', [
            'shop_slug' => 'idem-shop',
            '--source' => 'webhook_logs',
        ])->assertSuccessful();

        $this->assertDatabaseCount('cartpanda_orders', 1);
        $this->assertEqualsWithDelta(100.0, (float) CartpandaOrder::first()->amount, 0.001);
    }

    public function test_reconcile_dry_run_does_not_persist(): void
    {
        CartpandaShop::factory()->create(['shop_slug' => 'dry-shop', 'cartpanda_shop_id' => '999']);
        User::factory()->create(['email' => 'srretry@fractal.com']);
        config()->set('cartpanda.default_user_email', 'srretry@fractal.com');

        WebhookLog::create([
            'event' => 'order.paid',
            'cartpanda_order_id' => '50003',
            'shop_slug' => 'dry-shop',
            'status' => 'ignored',
            'status_reason' => 'user_not_found',
            'payload' => ['order' => ['id' => 50003, 'financial_status' => 3, 'all_payments' => [['seller_split_amount' => 100]]]],
            'ip_address' => '127.0.0.1',
        ]);

        $this->artisan('cartpanda:reconcile', [
            'shop_slug' => 'dry-shop',
            '--source' => 'webhook_logs',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('cartpanda_orders', ['cartpanda_order_id' => '50003']);
    }
}
