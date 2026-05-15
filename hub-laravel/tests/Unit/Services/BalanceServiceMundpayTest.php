<?php

namespace Tests\Unit\Services;

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
}
