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
}
