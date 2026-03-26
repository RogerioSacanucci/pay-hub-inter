<?php

namespace Tests\Feature\Services;

use App\Services\WayMbService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WayMbServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_create_transaction_sends_correct_post_request(): void
    {
        Http::fake([
            'https://api.waymb.test/api/transactions' => Http::response([
                'transaction_id' => 'txn-123',
                'status' => 'PENDING',
            ], 200),
        ]);

        $service = new WayMbService(
            url: 'https://api.waymb.test',
            accountEmail: 'merchant@example.com',
        );

        $result = $service->createTransaction([
            'amount' => 10.50,
            'currency' => 'EUR',
            'method' => 'mbway',
            'payer_phone' => '912345678',
        ]);

        $this->assertEquals('txn-123', $result['transaction_id']);
        $this->assertEquals('PENDING', $result['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.waymb.test/api/transactions'
                && $request->method() === 'POST'
                && $request['amount'] === 10.50
                && $request['currency'] === 'EUR'
                && $request['method'] === 'mbway'
                && $request['payer_phone'] === '912345678'
                && $request['account_email'] === 'merchant@example.com';
        });
    }

    public function test_get_transaction_info_sends_correct_get_request(): void
    {
        Http::fake([
            'https://api.waymb.test/api/transactions/txn-456' => Http::response([
                'transaction_id' => 'txn-456',
                'status' => 'COMPLETED',
                'amount' => 25.00,
            ], 200),
        ]);

        $service = new WayMbService(
            url: 'https://api.waymb.test',
            accountEmail: 'merchant@example.com',
        );

        $result = $service->getTransactionInfo('txn-456');

        $this->assertEquals('txn-456', $result['transaction_id']);
        $this->assertEquals('COMPLETED', $result['status']);
        $this->assertEquals(25.00, $result['amount']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.waymb.test/api/transactions/txn-456'
                && $request->method() === 'GET';
        });
    }
}
