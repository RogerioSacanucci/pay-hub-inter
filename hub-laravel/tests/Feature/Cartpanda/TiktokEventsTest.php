<?php

namespace Tests\Feature\Cartpanda;

use App\Models\TiktokPixel;
use App\Models\User;
use App\Services\TiktokEventsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

class TiktokEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_dispatches_all_enabled_pixels_with_ttclid(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $p1 = TiktokPixel::factory()->for($user)->create(['pixel_code' => 'CPIX_A']);
        $p2 = TiktokPixel::factory()->for($user)->create(['pixel_code' => 'CPIX_B']);
        TiktokPixel::factory()->for($user)->disabled()->create(['pixel_code' => 'CPIX_DIS']);

        $this->mock(TiktokEventsService::class, function (MockInterface $mock) use ($p1, $p2) {
            $mock->shouldReceive('sendPurchaseEvent')
                ->once()
                ->withArgs(function (Collection $pixels, array $order) use ($p1, $p2) {
                    $codes = $pixels->pluck('pixel_code')->all();

                    return in_array($p1->pixel_code, $codes, true)
                        && in_array($p2->pixel_code, $codes, true)
                        && ! in_array('CPIX_DIS', $codes, true)
                        && (string) ($order['checkout_params']['ttclid'] ?? '') === 'TTCLID_TEST';
                });
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 70001, 55.50, 'TTCLID_TEST');

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_webhook_skips_tiktok_without_pixels(): void
    {
        User::factory()->withCartpandaParam('afiliado1')->create();

        $this->mock(TiktokEventsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendPurchaseEvent');
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 70002, 30.00, 'TTCLID_TEST');

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_webhook_skips_tiktok_on_non_paid_events(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        TiktokPixel::factory()->for($user)->create();

        $this->mock(TiktokEventsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendPurchaseEvent');
        });

        $payload = $this->makePayload('order.created', 'afiliado1', 70003, 40.00, 'TTCLID_TEST');

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_webhook_skips_tiktok_when_all_pixels_disabled(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        TiktokPixel::factory()->for($user)->disabled()->create();

        $this->mock(TiktokEventsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendPurchaseEvent');
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 70004, 20.00, 'TTCLID_TEST');

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function makePayload(string $event, string $affiliateKey, int $orderId, float $amount, string $ttclid): array
    {
        return [
            'event' => $event,
            'order' => [
                'id' => $orderId,
                'checkout_params' => [
                    'affiliate' => $affiliateKey,
                    'ttclid' => $ttclid,
                    'utm_source' => 'TikTok',
                ],
                'all_payments' => [
                    ['seller_split_amount' => $amount],
                ],
                'payment' => [
                    'actual_exchange_rate' => 1.0,
                    'actual_price_paid' => $amount,
                    'actual_price_paid_currency' => 'USD',
                ],
                'customer' => [
                    'id' => 12345,
                    'email' => 'john@example.com',
                    'full_name' => 'John Doe',
                    'phone' => '+14145249343',
                ],
                'line_items' => [[
                    'sku' => 'SKU1',
                    'title' => 'Product',
                    'quantity' => 1,
                    'actual_price_paid' => $amount,
                ]],
                'browser_ip' => '127.0.0.1',
                'user_agent' => 'TestAgent',
                'processed_at' => '2026-04-24 09:48:08',
                'thank_you_page' => 'https://example.com/thankyou',
            ],
        ];
    }
}
