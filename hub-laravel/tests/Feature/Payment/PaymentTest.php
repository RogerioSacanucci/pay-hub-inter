<?php

namespace Tests\Feature\Payment;

use App\Models\Transaction;
use App\Models\User;
use App\Services\PushcutService;
use App\Services\WayMbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_payment_requires_valid_payer_email(): void
    {
        $this->postJson('/api/create-payment', [
            'amount' => 10.00,
            'method' => 'mbway',
            'payer' => ['email' => 'unknown@test.com', 'name' => 'Test', 'phone' => '912345678'],
        ])->assertStatus(422)->assertJsonFragment(['error' => 'Payer email not registered']);
    }

    public function test_create_payment_mbway_success(): void
    {
        $user = User::factory()->create(['payer_email' => 'payer@test.com']);

        $wayMb = Mockery::mock(WayMbService::class);
        $wayMb->shouldReceive('createTransaction')->once()->andReturn([
            'id' => 'txn_abc',
            'status' => 'PENDING',
            'generatedMBWay' => '912345678',
        ]);
        $this->app->instance(WayMbService::class, $wayMb);

        $this->app->instance(PushcutService::class, Mockery::mock(PushcutService::class, function ($mock) {
            $mock->shouldReceive('send')->zeroOrMoreTimes();
        }));

        $this->postJson('/api/create-payment', [
            'amount' => 10.00,
            'method' => 'mbway',
            'payer' => ['email' => 'payer@test.com', 'name' => 'Test User', 'phone' => '912345678'],
        ])
            ->assertOk()
            ->assertJsonStructure(['transactionId', 'method', 'amount']);

        $this->assertDatabaseHas('transactions', ['transaction_id' => 'txn_abc', 'status' => 'PENDING']);
    }

    public function test_check_status_returns_transaction_by_id(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => 'txn_xyz',
            'status' => 'COMPLETED',
        ]);

        $this->getJson('/api/check-status?id=txn_xyz')
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');
    }

    public function test_check_status_accepts_transaction_id_alias(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => 'txn_xyz2',
            'status' => 'PENDING',
        ]);

        $this->getJson('/api/check-status?transactionId=txn_xyz2')
            ->assertOk()
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_webhook_updates_transaction_status(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => 'txn_hook',
            'status' => 'PENDING',
        ]);

        $wayMb = Mockery::mock(WayMbService::class);
        $wayMb->shouldReceive('getTransactionInfo')->once()->andReturn([
            'id' => 'txn_hook',
            'status' => 'COMPLETED',
        ]);
        $this->app->instance(WayMbService::class, $wayMb);
        $this->app->instance(PushcutService::class, Mockery::mock(PushcutService::class, function ($mock) {
            $mock->shouldReceive('send')->zeroOrMoreTimes();
        }));

        $this->postJson('/api/webhook', ['transactionId' => 'txn_hook', 'status' => 'COMPLETED'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('transactions', ['transaction_id' => 'txn_hook', 'status' => 'COMPLETED']);
    }

    public function test_webhook_on_terminal_transaction_returns_ok_silently(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => 'txn_done',
            'status' => 'COMPLETED',
        ]);

        // Must return 200 ok (not 422) — WayMB retries on non-2xx
        $this->postJson('/api/webhook', ['transactionId' => 'txn_done', 'status' => 'FAILED'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Status must NOT have changed
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'txn_done', 'status' => 'COMPLETED']);
    }
}
