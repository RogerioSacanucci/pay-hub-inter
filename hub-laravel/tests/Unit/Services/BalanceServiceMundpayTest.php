<?php

namespace Tests\Unit\Services;

use App\Jobs\ReleaseMundpayBalanceJob;
use App\Models\MundpayOrder;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceServiceMundpayTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_pending_increments_pending_and_reserve(): void
    {
        $user = User::factory()->create();
        $order = MundpayOrder::factory()->for($user)->create([
            'amount' => 100,
            'reserve_amount' => 15,
        ]);

        app(BalanceService::class)->creditPendingForMundpay($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('85.000000', $balance->balance_pending);
        $this->assertEquals('15.000000', $balance->balance_reserve);
    }

    public function test_debit_on_refund_debits_pending_when_not_released(): void
    {
        $user = User::factory()->create();
        UserBalance::create([
            'user_id' => $user->id,
            'balance_pending' => 100,
            'balance_released' => 0,
            'balance_reserve' => 20,
            'currency' => 'BRL',
        ]);
        $order = MundpayOrder::factory()->for($user)->create([
            'amount' => 80,
            'reserve_amount' => 12,
            'released_at' => null,
        ]);

        app(BalanceService::class)->debitOnChargebackForMundpay($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('32.000000', $balance->balance_pending); // 100 - (80 - 12)
        $this->assertEquals('8.000000', $balance->balance_reserve);  // 20 - 12
        $this->assertEquals('0.000000', $balance->balance_released);
    }

    public function test_debit_on_refund_debits_released_when_already_released(): void
    {
        $user = User::factory()->create();
        UserBalance::create([
            'user_id' => $user->id,
            'balance_pending' => 0,
            'balance_released' => 100,
            'balance_reserve' => 20,
            'currency' => 'BRL',
        ]);
        $order = MundpayOrder::factory()->for($user)->create([
            'amount' => 80,
            'reserve_amount' => 12,
            'released_at' => now(),
        ]);

        app(BalanceService::class)->debitOnChargebackForMundpay($user, $order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('32.000000', $balance->balance_released);
        $this->assertEquals('8.000000', $balance->balance_reserve);
        $this->assertEquals('0.000000', $balance->balance_pending);
    }

    public function test_release_moves_pending_to_released(): void
    {
        $user = User::factory()->create();
        UserBalance::create([
            'user_id' => $user->id,
            'balance_pending' => 100,
            'balance_released' => 0,
            'balance_reserve' => 0,
            'currency' => 'BRL',
        ]);
        $order = MundpayOrder::factory()->for($user)->create([
            'amount' => 80,
            'reserve_amount' => 12,
            'released_at' => null,
        ]);

        app(BalanceService::class)->releaseMundpay($order);

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('32.000000', $balance->balance_pending);   // 100 - 68
        $this->assertEquals('68.000000', $balance->balance_released);
        $this->assertNotNull($order->fresh()->released_at);
    }

    public function test_release_job_releases_only_eligible_orders(): void
    {
        $user = User::factory()->create();
        UserBalance::create([
            'user_id' => $user->id,
            'balance_pending' => 200,
            'balance_released' => 0,
            'balance_reserve' => 0,
            'currency' => 'BRL',
        ]);

        // Eligible (paid 4 dias atrás, ainda não liberada)
        $eligible = MundpayOrder::factory()->for($user)->create([
            'amount' => 80,
            'reserve_amount' => 12,
            'release_eligible_at' => now()->subDay(),
            'released_at' => null,
        ]);

        // Not eligible yet
        MundpayOrder::factory()->for($user)->create([
            'amount' => 50,
            'reserve_amount' => 7.5,
            'release_eligible_at' => now()->addDays(2),
            'released_at' => null,
        ]);

        (new ReleaseMundpayBalanceJob)->handle(app(BalanceService::class));

        $this->assertNotNull($eligible->fresh()->released_at);
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals('68.000000', $balance->balance_released);
    }
}
