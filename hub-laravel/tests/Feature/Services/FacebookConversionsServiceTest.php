<?php

namespace Tests\Feature\Services;

use App\Services\FacebookConversionsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FacebookConversionsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_sends_purchase_event_to_correct_endpoint(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/123456/events*' => Http::response([], 200),
        ]);

        $service = new FacebookConversionsService;

        $service->sendPurchaseEvent(
            pixelId: '123456',
            accessToken: 'test-token',
            orderId: 'order-1',
            value: 29.90,
            currency: 'EUR',
            userData: ['email' => 'test@example.com'],
            eventTime: 1711843200,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://graph.facebook.com/v21.0/123456/events')
                && str_contains($request->url(), 'access_token=test-token')
                && $request->method() === 'POST';
        });
    }

    public function test_hashes_user_data_with_sha256(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/*/events*' => Http::response([], 200),
        ]);

        $service = new FacebookConversionsService;

        $service->sendPurchaseEvent(
            pixelId: '123456',
            accessToken: 'test-token',
            orderId: 'order-1',
            value: 29.90,
            currency: 'EUR',
            userData: [
                'email' => ' Test@Example.com ',
                'first_name' => ' John ',
                'last_name' => ' Doe ',
            ],
            eventTime: 1711843200,
        );

        Http::assertSent(function ($request) {
            $userData = $request['data'][0]['user_data'];

            return $userData['em'] === [hash('sha256', 'test@example.com')]
                && $userData['fn'] === [hash('sha256', 'john')]
                && $userData['ln'] === [hash('sha256', 'doe')];
        });
    }

    public function test_sends_correct_custom_data(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/*/events*' => Http::response([], 200),
        ]);

        $service = new FacebookConversionsService;

        $service->sendPurchaseEvent(
            pixelId: '123456',
            accessToken: 'test-token',
            orderId: 'order-42',
            value: 99.99,
            currency: 'BRL',
            userData: ['email' => 'test@example.com'],
            eventTime: 1711843200,
        );

        Http::assertSent(function ($request) {
            $event = $request['data'][0];

            return $event['event_name'] === 'Purchase'
                && $event['event_time'] === 1711843200
                && $event['event_id'] === 'order-42'
                && $event['action_source'] === 'system_generated'
                && $event['custom_data']['value'] === 99.99
                && $event['custom_data']['currency'] === 'BRL'
                && $event['custom_data']['order_id'] === 'order-42';
        });
    }

    public function test_omits_empty_user_data_fields(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/*/events*' => Http::response([], 200),
        ]);

        $service = new FacebookConversionsService;

        $service->sendPurchaseEvent(
            pixelId: '123456',
            accessToken: 'test-token',
            orderId: 'order-1',
            value: 10.00,
            currency: 'EUR',
            userData: [
                'email' => 'test@example.com',
                'first_name' => '',
                'last_name' => null,
            ],
            eventTime: 1711843200,
        );

        Http::assertSent(function ($request) {
            $userData = $request['data'][0]['user_data'];

            return isset($userData['em'])
                && ! isset($userData['fn'])
                && ! isset($userData['ln']);
        });
    }

    public function test_logs_warning_on_failure_and_does_not_throw(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/*/events*' => Http::response([], 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Facebook Conversions API failed')
                    && $context['pixel_id'] === '123456'
                    && $context['order_id'] === 'order-1';
            });

        $service = new FacebookConversionsService;

        // Should not throw — fire and forget
        $service->sendPurchaseEvent(
            pixelId: '123456',
            accessToken: 'test-token',
            orderId: 'order-1',
            value: 29.90,
            currency: 'EUR',
            userData: ['email' => 'test@example.com'],
            eventTime: 1711843200,
        );
    }
}
