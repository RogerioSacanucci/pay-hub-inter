<?php

namespace Tests\Feature\Cartpanda;

use App\Models\User;
use App\Services\FacebookConversionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class FacebookConversionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_sends_facebook_event_on_paid_with_credentials(): void
    {
        $user = User::factory()
            ->withCartpandaParam('afiliado1')
            ->withFacebookPixel('999888777', 'EAAtoken123')
            ->create();

        $this->mock(FacebookConversionsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendPurchaseEvent')
                ->once()
                ->withArgs(function (
                    string $pixelId,
                    string $accessToken,
                    string $orderId,
                    float $value,
                    string $currency,
                    array $userData,
                ) {
                    return $pixelId === '999888777'
                        && $accessToken === 'EAAtoken123'
                        && $orderId === '70001'
                        && $value === 55.50
                        && $currency === 'USD'
                        && $userData['email'] === 'john@example.com'
                        && $userData['first_name'] === 'John'
                        && $userData['last_name'] === 'Doe';
                });
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 70001, 55.50);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_webhook_skips_facebook_when_no_credentials(): void
    {
        User::factory()
            ->withCartpandaParam('afiliado1')
            ->create();

        $this->mock(FacebookConversionsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendPurchaseEvent');
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 70002, 30.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_webhook_skips_facebook_on_non_completed_status(): void
    {
        User::factory()
            ->withCartpandaParam('afiliado1')
            ->withFacebookPixel()
            ->create();

        $this->mock(FacebookConversionsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendPurchaseEvent');
        });

        $payload = $this->makePayload('order.created', 'afiliado1', 70003, 20.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

    public function test_webhook_skips_facebook_with_only_pixel_id(): void
    {
        User::factory()
            ->withCartpandaParam('afiliado1')
            ->create(['facebook_pixel_id' => '999888777']);

        $this->mock(FacebookConversionsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendPurchaseEvent');
        });

        $payload = $this->makePayload('order.paid', 'afiliado1', 70004, 40.00);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
    }

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
