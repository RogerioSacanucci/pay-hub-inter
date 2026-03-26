<?php

namespace Tests\Feature\Services;

use App\Services\PushcutService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PushcutServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_send_posts_correct_payload(): void
    {
        Http::fake([
            'https://pushcut.example.com/notify' => Http::response([], 200),
        ]);

        $service = new PushcutService;

        $service->send(
            url: 'https://pushcut.example.com/notify',
            title: 'Payment received',
            data: ['amount' => 10.50, 'status' => 'COMPLETED'],
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pushcut.example.com/notify'
                && $request->method() === 'POST'
                && $request['title'] === 'Payment received'
                && $request['data']['amount'] === 10.50
                && $request['data']['status'] === 'COMPLETED';
        });
    }

    public function test_send_without_data_omits_data_field(): void
    {
        Http::fake([
            'https://pushcut.example.com/notify' => Http::response([], 200),
        ]);

        $service = new PushcutService;

        $service->send(
            url: 'https://pushcut.example.com/notify',
            title: 'Simple notification',
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pushcut.example.com/notify'
                && $request['title'] === 'Simple notification'
                && ! isset($request['data']);
        });
    }

    public function test_send_logs_warning_on_failure_and_does_not_throw(): void
    {
        Http::fake([
            'https://pushcut.example.com/notify' => Http::response([], 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Pushcut notification failed')
                    && $context['url'] === 'https://pushcut.example.com/notify';
            });

        $service = new PushcutService;

        // Should not throw — fire and forget
        $service->send(
            url: 'https://pushcut.example.com/notify',
            title: 'Payment received',
        );
    }
}
